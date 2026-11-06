<?php

namespace Tests\Feature\Api\V1;

use App\Mail\RenewalFailed;
use App\Mail\SubscriptionExpired;
use App\Mail\SubscriptionRenewed;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\StoredCard;
use App\Services\SubscriptionRenewals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The renewal scheduler.
 *
 * Every row these tests create belongs to the fixture account and is removed
 * in tearDown, including the rows deliberately marked as Moneris-owned so
 * that the "never touches the other gateway" assertion has something to
 * check.
 *
 * The charging tests need a real Stripe test card attached, so they skip
 * without a test key.
 */
class RenewalSchedulerTest extends TestCase
{
    private object $user;

    private ?StoredCard $card = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => env('CONTRACT_DB', 'clas'),
        ]);

        try {
            $user = DB::table('user')->where('username', 'contract_fixture')->first();
        } catch (\Throwable $e) {
            $this->markTestSkipped('clas database not reachable: '.$e->getMessage());
        }

        if ($user === null) {
            $this->markTestSkipped('Fixture account missing; see tests/Fixtures/v1/README.md.');
        }

        if (! DB::getSchemaBuilder()->hasColumn('user_subscription', 'gateway')) {
            $this->markTestSkipped('Run the gateway columns migration first.');
        }

        $this->user = $user;
        $this->clear();

        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->user)) {
            $this->clear();
        }

        parent::tearDown();
    }

    private function clear(): void
    {
        foreach ([
            'user_subscription' => 'subscription_transactions',
            'wmu_subscription' => 'wmu_subscription_transactions',
            'fishing_lands_subscription' => 'fishing_lands_subscription_transactions',
        ] as $table => $transactions) {
            $key = $table === 'user_subscription' ? 'subscription_id' : 'id';
            $ids = DB::table($table)->where('user_id', $this->user->id)->pluck($key);

            if ($ids->isNotEmpty()) {
                DB::table($transactions)->whereIn('subscription_id', $ids)->delete();
            }

            DB::table($table)->where('user_id', $this->user->id)->delete();
        }
    }

    /** A card Stripe is holding, attached once per test that needs one. */
    private function card(): StoredCard
    {
        if (! str_starts_with((string) config('services.stripe.secret'), 'sk_test_')) {
            $this->markTestSkipped('No Stripe test key configured.');
        }

        return $this->card ??= app(PaymentGateway::class)
            ->rememberCard(User::find($this->user->id), 'pm_card_visa');
    }

    /** A subscription row, due by default. */
    private function subscription(array $overrides = []): int
    {
        return (int) DB::table('user_subscription')->insertGetId(array_merge([
            'user_id' => $this->user->id,
            'subscription_type' => 5,
            'start_date' => now()->subYear()->format('Y-m-d'),
            'expiration_date' => now()->format('Y-m-d'),
            'auto_renew' => 0,
            'gateway' => 'stripe',
            'status' => 'Active',
            'subscription_amount' => '9.99',
            'amount_paid' => '9.99',
            'customer_id' => 'CLAS-USER-'.$this->user->id,
        ], $overrides));
    }

    private function renewals(): SubscriptionRenewals
    {
        return app(SubscriptionRenewals::class);
    }

    // ---- the boundary that matters -------------------------------------

    /**
     * A subscription on the other gateway is never touched.
     *
     * This is the assertion the whole coexistence arrangement rests on. A row
     * with NULL or 'moneris' belongs to the Yii cron; charging it here would
     * bill the customer twice, once through each processor.
     */
    public function test_a_moneris_subscription_is_never_touched(): void
    {
        /*
         * Both rows carry a usable Stripe payment method, so the *only*
         * thing keeping this scheduler away from them is the gateway column.
         * Without that they were being excluded by the missing-payment-method
         * check instead, and the assertion below passed for the wrong reason
         * — which the sabotage harness caught.
         *
         * It is also the realistic shape for phase 2: a migrated row has a
         * Stripe payment method and a Moneris data_key at the same time, and
         * only `gateway` says which one owns it.
         */
        $nullGateway = $this->subscription([
            'gateway' => null,
            'auto_renew' => 1,
            'stripe_customer_id' => 'cus_notours',
            'stripe_payment_method_id' => 'pm_notours',
            'data_key' => 'moneris-vault-token',
        ]);

        $moneris = $this->subscription([
            'gateway' => 'moneris',
            'auto_renew' => 1,
            'stripe_customer_id' => 'cus_notours',
            'stripe_payment_method_id' => 'pm_notours',
            'data_key' => 'moneris-vault-token',
        ]);

        $before = [
            $nullGateway => (array) DB::table('user_subscription')->where('subscription_id', $nullGateway)->first(),
            $moneris => (array) DB::table('user_subscription')->where('subscription_id', $moneris)->first(),
        ];

        $tally = $this->renewals()->run();

        $this->assertSame(0, $tally['attempted'], 'Neither row is this scheduler\'s to charge.');

        foreach ($before as $id => $row) {
            $this->assertSame($row, (array) DB::table('user_subscription')
                ->where('subscription_id', $id)->first(),
                'A row owned by the other gateway must be byte-identical afterwards.');
        }

        $this->assertSame(0, DB::table('subscription_transactions')
            ->where('user_id', $this->user->id)->count(),
            'No transaction may be written for another gateway\'s row.');
    }

    /** Nor is one with no Stripe payment method, whatever its gateway says. */
    public function test_a_row_with_no_payment_method_is_not_charged(): void
    {
        $this->subscription(['stripe_payment_method_id' => null]);

        $this->assertSame(0, $this->renewals()->run()['attempted']);
    }

    // ---- charging ------------------------------------------------------

    public function test_a_due_subscription_is_charged_and_extended(): void
    {
        $card = $this->card();

        $id = $this->subscription([
            'stripe_customer_id' => $card->customerId,
            'stripe_payment_method_id' => $card->paymentMethodId,
        ]);

        $tally = $this->renewals()->run();

        $this->assertSame(1, $tally['attempted']);
        $this->assertSame(1, $tally['renewed']);

        $row = DB::table('user_subscription')->where('subscription_id', $id)->first();

        $this->assertSame(now()->addYear()->format('Y-m-d'), $row->expiration_date);
        $this->assertSame(now()->format('Y-m-d'), $row->last_processed);
        $this->assertStringStartsWith('pi_', (string) $row->txn_number);
        $this->assertSame('Active', $row->status);

        // The invariant survives a renewal: still invisible to the old cron.
        $this->assertSame(0, (int) $row->auto_renew);
        $this->assertSame('stripe', $row->gateway);

        $this->assertSame('APPROVED', DB::table('subscription_transactions')
            ->where('subscription_id', $id)->value('txn_status'));

        Mail::assertSent(SubscriptionRenewed::class);
    }

    /**
     * A missed run costs a day, not the renewal.
     *
     * The old cron charged on exactly two dates — the expiry, and seven days
     * later — so a run that did not happen on one of those dates never
     * charged the subscription at all. Three days past expiry is inside the
     * grace window and must be attempted.
     */
    public function test_a_subscription_missed_on_its_expiry_date_is_still_charged(): void
    {
        $card = $this->card();

        $this->subscription([
            'expiration_date' => now()->subDays(3)->format('Y-m-d'),
            'stripe_customer_id' => $card->customerId,
            'stripe_payment_method_id' => $card->paymentMethodId,
        ]);

        $this->assertSame(1, $this->renewals()->run()['renewed'],
            'Three days late is inside the grace window.');
    }

    /** Running twice in a day must not charge twice. */
    public function test_a_second_run_on_the_same_day_charges_nothing(): void
    {
        $card = $this->card();

        $this->subscription([
            'stripe_customer_id' => $card->customerId,
            'stripe_payment_method_id' => $card->paymentMethodId,
        ]);

        $this->assertSame(1, $this->renewals()->run()['renewed']);
        $this->assertSame(0, $this->renewals()->run()['attempted'],
            'The row was renewed, so it is no longer due.');

        $this->assertSame(1, DB::table('subscription_transactions')
            ->where('user_id', $this->user->id)->count());
    }

    /**
     * Attempts are capped, and counted from what was recorded.
     *
     * Two prior attempts for this period are already on file, so a third is
     * not made however many times the command runs.
     */
    public function test_the_attempt_cap_is_honoured(): void
    {
        $id = $this->subscription([
            'expiration_date' => now()->subDays(2)->format('Y-m-d'),
            'stripe_payment_method_id' => 'pm_whatever',
            'stripe_customer_id' => 'cus_whatever',
            'last_processed' => now()->subDay()->format('Y-m-d'),
        ]);

        $orderId = sprintf('CLAS-RENW-%d-USER-%d-%s', $id, $this->user->id,
            now()->subDays(2)->format('Y-m-d'));

        foreach ([1, 2] as $attempt) {
            DB::table('subscription_transactions')->insert([
                'subscription_id' => $id,
                'user_id' => $this->user->id,
                'order_id' => $orderId,
                'txn_number' => '',
                'amount' => '9.99',
                'txn_status' => 'DECLINED',
                'txn_response' => json_encode(['attempt' => $attempt]),
            ]);
        }

        $tally = $this->renewals()->run();

        $this->assertSame(0, $tally['attempted'], 'Two attempts is the cap.');
        $this->assertSame(1, $tally['skipped']);
    }

    // ---- expiry ---------------------------------------------------------

    /**
     * Out of grace, the subscription and its add-ons expire together.
     *
     * The add-ons are the free year of WMU and fishing-lands access that
     * every subscription includes.
     */
    public function test_a_subscription_out_of_grace_expires_with_its_addons(): void
    {
        $id = $this->subscription([
            'expiration_date' => now()->subDays(30)->format('Y-m-d'),
            'stripe_payment_method_id' => 'pm_whatever',
        ]);

        foreach (['wmu_subscription', 'fishing_lands_subscription'] as $table) {
            DB::table($table)->insert([
                'user_id' => $this->user->id,
                'start_date' => now()->subYear()->format('Y-m-d'),
                'expiration_date' => now()->addMonths(6)->format('Y-m-d'),
                'status' => 'Active',
                'subscription_amount' => '0.00',
                'gateway' => 'stripe',
            ]);
        }

        $tally = $this->renewals()->run();

        $this->assertSame(1, $tally['expired']);
        $this->assertSame(0, $tally['attempted'], 'Past grace is not charged.');

        $this->assertSame('Expired', DB::table('user_subscription')
            ->where('subscription_id', $id)->value('status'));

        foreach (['wmu_subscription', 'fishing_lands_subscription'] as $table) {
            $this->assertSame('Expired', DB::table($table)
                ->where('user_id', $this->user->id)->value('status'),
                $table.' should expire with the subscription it came with.');
        }

        Mail::assertSent(SubscriptionExpired::class);
    }

    /** A Moneris row out of grace is left for the other cron to expire. */
    public function test_expiry_does_not_reach_across_gateways(): void
    {
        $id = $this->subscription([
            'gateway' => 'moneris',
            'expiration_date' => now()->subDays(30)->format('Y-m-d'),
        ]);

        $this->assertSame(0, $this->renewals()->run()['expired']);

        $this->assertSame('Active', DB::table('user_subscription')
            ->where('subscription_id', $id)->value('status'));
    }

    /** A declined renewal is recorded and the customer told. */
    public function test_a_declined_renewal_is_recorded_and_notified(): void
    {
        if (! str_starts_with((string) config('services.stripe.secret'), 'sk_test_')) {
            $this->markTestSkipped('No Stripe test key configured.');
        }

        // An attached card that cannot be charged unattended: it wants 3-D
        // Secure, and there is nobody there to provide it.
        $card = app(PaymentGateway::class)
            ->rememberCard(User::find($this->user->id), 'pm_card_authenticationRequired');

        $id = $this->subscription([
            'stripe_customer_id' => $card->customerId,
            'stripe_payment_method_id' => $card->paymentMethodId,
        ]);

        $tally = $this->renewals()->run();

        $this->assertSame(1, $tally['attempted']);
        $this->assertSame(1, $tally['failed']);

        $row = DB::table('user_subscription')->where('subscription_id', $id)->first();

        $this->assertSame('Active', $row->status, 'A decline does not expire it; the grace period does.');
        $this->assertSame(now()->format('Y-m-d'), $row->expiration_date,
            'The term is not extended by a failed charge.');
        $this->assertSame(now()->format('Y-m-d'), $row->last_processed);

        $this->assertNotSame('APPROVED', DB::table('subscription_transactions')
            ->where('subscription_id', $id)->value('txn_status'));

        Mail::assertSent(RenewalFailed::class);
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $id = $this->subscription([
            'stripe_payment_method_id' => 'pm_whatever',
            'stripe_customer_id' => 'cus_whatever',
        ]);

        $before = (array) DB::table('user_subscription')->where('subscription_id', $id)->first();

        $tally = $this->renewals()->run(dryRun: true);

        $this->assertSame(1, $tally['attempted'], 'It still reports what it would do.');
        $this->assertSame(0, $tally['renewed']);

        $this->assertSame($before, (array) DB::table('user_subscription')
            ->where('subscription_id', $id)->first());

        $this->assertSame(0, DB::table('subscription_transactions')
            ->where('user_id', $this->user->id)->count());

        Mail::assertNothingSent();
    }
}
