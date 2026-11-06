<?php

namespace Tests\Feature\Api\V1;

use App\Services\SubscriptionRenewals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Changing the card on file, which is also the migration off Moneris.
 *
 * The interesting assertions are about the handover: after a card change the
 * row must be owned by exactly one scheduler, the Moneris token must survive
 * so it can be handed back, and the old cron's filter must no longer select
 * it.
 */
class CardOnFileTest extends TestCase
{
    private object $user;

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

    private function token(): string
    {
        return $this->user->auth_key;
    }

    private function requireStripe(): void
    {
        if (! str_starts_with((string) config('services.stripe.secret'), 'sk_test_')) {
            $this->markTestSkipped('No Stripe test key configured.');
        }
    }

    /** A subscription as the old application would have left it. */
    private function monerisSubscription(array $overrides = []): int
    {
        return (int) DB::table('user_subscription')->insertGetId(array_merge([
            'user_id' => $this->user->id,
            'subscription_type' => 5,
            'start_date' => now()->subYear()->format('Y-m-d'),
            'expiration_date' => now()->addMonths(6)->format('Y-m-d'),

            // What a Moneris row looks like: renewed by the Yii cron, with a
            // vault token and no Stripe anything.
            'auto_renew' => 1,
            'gateway' => null,
            'data_key' => 'moneris-vault-token-xyz',
            'customer_id' => 'CLAS-USER-'.$this->user->id,
            'status' => 'Active',
            'subscription_amount' => '9.99',
            'amount_paid' => '9.99',
        ], $overrides));
    }

    /** Would the old Yii cron select this row? Its filter, exactly. */
    private function theOldCronWouldSelect(int $id): bool
    {
        return DB::table('user_subscription as us')
            ->join('user as u', 'u.id', '=', 'us.user_id')
            ->where('us.subscription_id', $id)
            ->whereIn('us.subscription_type', [3, 4, 5])
            ->where('us.status', 'Active')
            ->where('us.auto_renew', 1)
            ->whereNull('u.blocked_at')
            ->exists();
    }

    // ---- the handover ---------------------------------------------------

    /**
     * Changing the card moves the subscription, and exactly one scheduler
     * ends up owning it.
     */
    public function test_changing_the_card_moves_the_subscription_to_stripe(): void
    {
        $this->requireStripe();

        $id = $this->monerisSubscription();

        $this->assertTrue($this->theOldCronWouldSelect($id),
            'Before: the Yii cron owns this row.');

        $data = $this->postJson('/v1/user/payment-method', [
            'token' => $this->token(),
            'payment_method_id' => 'pm_card_visa',
        ])->assertStatus(200)->json('data');

        $this->assertTrue($data['moved_to_stripe']);
        $this->assertSame(1, $data['subscriptions_updated']);
        $this->assertSame('4242', $data['card']['last4']);
        $this->assertSame('visa', $data['card']['brand']);

        $row = DB::table('user_subscription')->where('subscription_id', $id)->first();

        $this->assertSame('stripe', $row->gateway);
        $this->assertSame(0, (int) $row->auto_renew);
        $this->assertStringStartsWith('cus_', (string) $row->stripe_customer_id);
        $this->assertStringStartsWith('pm_', (string) $row->stripe_payment_method_id);

        // After: the Yii cron no longer selects it...
        $this->assertFalse($this->theOldCronWouldSelect($id),
            'After: the Yii cron must no longer own this row.');

        // ...and this application's scheduler does not either, because it is
        // not due for another six months. Exactly one owner at a time, and
        // neither charges a row that is not due.
        $due = array_column(app(SubscriptionRenewals::class)->due(), 'subscription_id');
        $this->assertNotContains($id, $due);
    }

    /**
     * The Moneris token survives, so the row can be handed back.
     *
     * This is what makes the migration reversible one row at a time: setting
     * `gateway` and `auto_renew` back is enough, because the vault token was
     * never destroyed.
     */
    public function test_the_moneris_token_is_kept(): void
    {
        $this->requireStripe();

        $id = $this->monerisSubscription();

        $this->postJson('/v1/user/payment-method', [
            'token' => $this->token(), 'payment_method_id' => 'pm_card_visa',
        ])->assertStatus(200);

        $this->assertSame('moneris-vault-token-xyz', DB::table('user_subscription')
            ->where('subscription_id', $id)->value('data_key'),
            'The vault token is the rollback and must not be cleared.');
    }

    /**
     * A subscriber in their grace period is picked up by the new scheduler.
     *
     * This is the commonest reason somebody changes their card: the renewal
     * failed. Moving them must leave the subscription chargeable, not
     * stranded between two schedulers.
     */
    public function test_a_card_changed_during_grace_leaves_the_row_chargeable(): void
    {
        $this->requireStripe();

        $id = $this->monerisSubscription([
            'expiration_date' => now()->subDays(2)->format('Y-m-d'),
        ]);

        $this->postJson('/v1/user/payment-method', [
            'token' => $this->token(), 'payment_method_id' => 'pm_card_visa',
        ])->assertStatus(200)->assertJsonPath('data.moved_to_stripe', true);

        $due = array_column(app(SubscriptionRenewals::class)->due(), 'subscription_id');

        $this->assertContains($id, $due,
            'A row moved mid-grace must be due on the next scheduler run.');

        $this->assertFalse($this->theOldCronWouldSelect($id));
    }

    /**
     * A row the old cron already attempted today is not charged again today.
     *
     * The handover must not produce two charges on one day — one from each
     * processor.
     */
    public function test_a_row_attempted_today_is_not_charged_again_today(): void
    {
        $this->requireStripe();

        $this->monerisSubscription([
            'expiration_date' => now()->subDays(2)->format('Y-m-d'),
            // What the Yii cron stamps when it attempts a row.
            'last_processed' => now()->format('Y-m-d'),
        ]);

        $this->postJson('/v1/user/payment-method', [
            'token' => $this->token(), 'payment_method_id' => 'pm_card_visa',
        ])->assertStatus(200);

        $tally = app(SubscriptionRenewals::class)->run();

        $this->assertSame(0, $tally['attempted'],
            'Already attempted today by the other processor: not again today.');
        $this->assertSame(1, $tally['skipped']);
    }

    /** Changing the card of an account already on Stripe is not a migration. */
    public function test_an_account_already_on_stripe_is_not_reported_as_moved(): void
    {
        $this->requireStripe();

        $this->monerisSubscription([
            'gateway' => 'stripe',
            'auto_renew' => 0,
            'data_key' => null,
        ]);

        $this->postJson('/v1/user/payment-method', [
            'token' => $this->token(), 'payment_method_id' => 'pm_card_visa',
        ])->assertStatus(200)
            ->assertJsonPath('data.moved_to_stripe', false);
    }

    /** The add-ons are kept consistent, though nothing renews them. */
    public function test_the_addons_follow_the_subscription(): void
    {
        $this->requireStripe();

        $this->monerisSubscription();

        foreach (['wmu_subscription', 'fishing_lands_subscription'] as $table) {
            DB::table($table)->insert([
                'user_id' => $this->user->id,
                'start_date' => now()->subMonths(6)->format('Y-m-d'),
                'expiration_date' => now()->addMonths(6)->format('Y-m-d'),
                'status' => 'Active',
                'subscription_amount' => '0.00',
                'data_key' => 'moneris-vault-token-xyz',
            ]);
        }

        $this->postJson('/v1/user/payment-method', [
            'token' => $this->token(), 'payment_method_id' => 'pm_card_visa',
        ])->assertStatus(200);

        foreach (['wmu_subscription', 'fishing_lands_subscription'] as $table) {
            $row = DB::table($table)->where('user_id', $this->user->id)->first();

            $this->assertSame('stripe', $row->gateway, $table.' should follow.');
            $this->assertSame('moneris-vault-token-xyz', $row->data_key,
                $table.' keeps its token too.');
        }
    }

    // ---- the read, and the refusals -------------------------------------

    public function test_the_gateway_is_reported_so_the_app_can_warn(): void
    {
        $this->monerisSubscription();

        $this->getJson('/v1/user/payment-method?token='.$this->token())
            ->assertStatus(200)
            ->assertJsonPath('data.gateway', 'moneris')
            ->assertJsonPath('data.has_card', true);
    }

    public function test_an_account_with_no_subscription_reports_nothing(): void
    {
        $this->getJson('/v1/user/payment-method?token='.$this->token())
            ->assertStatus(200)
            ->assertJsonPath('data.gateway', null)
            ->assertJsonPath('data.has_card', false);
    }

    public function test_a_payment_method_is_required(): void
    {
        $this->postJson('/v1/user/payment-method', ['token' => $this->token()])
            ->assertStatus(422);
    }

    /** A card with no subscription to charge is still saved. */
    public function test_a_card_can_be_saved_with_no_subscription(): void
    {
        $this->requireStripe();

        $this->postJson('/v1/user/payment-method', [
            'token' => $this->token(), 'payment_method_id' => 'pm_card_visa',
        ])->assertStatus(200)
            ->assertJsonPath('data.moved_to_stripe', false)
            ->assertJsonPath('data.subscriptions_updated', 0);
    }

    public function test_a_card_that_cannot_be_saved_changes_nothing(): void
    {
        $this->requireStripe();

        $id = $this->monerisSubscription();
        $before = (array) DB::table('user_subscription')->where('subscription_id', $id)->first();

        $this->postJson('/v1/user/payment-method', [
            'token' => $this->token(), 'payment_method_id' => 'pm_card_chargeDeclined',
        ])->assertStatus(422);

        $this->assertSame($before, (array) DB::table('user_subscription')
            ->where('subscription_id', $id)->first(),
            'A refused card must not half-migrate the row.');
    }
}
