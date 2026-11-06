<?php

namespace Tests\Feature\Api\V1;

use App\Mail\SubscriptionStarted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The three add-on products, bought on their own.
 *
 * One test body across all three, because there is now one code path across
 * all three. The old API had three controllers of around 350 lines each, and
 * a bug fixed in one of them stayed broken in the other two.
 */
class ProductSubscriptionTest extends TestCase
{
    private object $user;

    /** path => [table, transactions table, price] */
    private const PRODUCTS = [
        'wmu-subscription' => ['wmu_subscription', 'wmu_subscription_transactions', '4.99'],
        'dark-mode-subscription' => ['darkmode_subscription', 'darkmode_subscription_transactions', '49.99'],
        'fishing-lands-subscription' => ['fishing_lands_subscription', 'fishing_lands_subscription_transactions', '4.99'],
    ];

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

        if (! DB::getSchemaBuilder()->hasColumn('wmu_subscription', 'gateway')) {
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
        foreach (self::PRODUCTS as [$table, $transactions]) {
            $ids = DB::table($table)->where('user_id', $this->user->id)->pluck('id');

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

    /** Each product charges its own price and writes its own table. */
    public function test_each_product_is_bought_at_its_own_price(): void
    {
        $this->requireStripe();

        foreach (self::PRODUCTS as $path => [$table, $transactions, $price]) {
            $data = $this->postJson('/v1/'.$path.'/subscribe', [
                'token' => $this->token(),
                'payment_method_id' => 'pm_card_visa',
            ])->assertStatus(201)->json('data');

            $this->assertSame($price, $data['amount'], $path.' should charge '.$price);
            $this->assertStringStartsWith('pi_', (string) $data['reference']);

            $row = DB::table($table)->where('id', $data['subscription_id'])->first();

            $this->assertSame('Active', $row->status);
            $this->assertSame($price, $row->subscription_amount);
            $this->assertSame(now()->addYear()->format('Y-m-d'), $row->expiration_date);
            $this->assertSame('CLAS-USER-'.$this->user->id, $row->customer_id);

            // The invariant, on the add-on tables too.
            $this->assertSame('stripe', $row->gateway);
            $this->assertStringStartsWith('cus_', (string) $row->stripe_customer_id);
            $this->assertNull($row->data_key, 'No Moneris token on a Stripe row.');

            $transaction = DB::table($transactions)
                ->where('subscription_id', $data['subscription_id'])
                ->first(['txn_status', 'txn_response']);

            $this->assertSame('APPROVED', $transaction->txn_status);

            /*
             * What Stripe actually charged, in cents, from its own response.
             *
             * Asserting only the recorded `subscription_amount` would pass
             * even if the charge sent a different figure — which is exactly
             * what the sabotage harness demonstrated: hardcoding 4.99 as the
             * charged amount left dark mode recording 49.99 and charging
             * 4.99, and every assertion still passed.
             */
            $charged = json_decode((string) $transaction->txn_response, true);

            $this->assertSame(
                (int) round(((float) $price) * 100),
                $charged['amount'] ?? null,
                $path.' must charge the price it records.',
            );
        }

        Mail::assertSent(SubscriptionStarted::class, 3);
    }

    /** Buying the same product twice is refused. */
    public function test_a_product_cannot_be_bought_twice(): void
    {
        $this->requireStripe();

        $this->postJson('/v1/wmu-subscription/subscribe', [
            'token' => $this->token(), 'payment_method_id' => 'pm_card_visa',
        ])->assertStatus(201);

        $this->postJson('/v1/wmu-subscription/subscribe', [
            'token' => $this->token(), 'payment_method_id' => 'pm_card_visa',
        ])->assertStatus(409)
            ->assertJsonPath('error', 'This account already has a WMU Layer subscription.');

        $this->assertSame(1, DB::table('wmu_subscription')
            ->where('user_id', $this->user->id)->count());
    }

    /**
     * An expired one does not block buying again.
     *
     * This is the renewal case: the free year that came with a property
     * subscription has run out, and the customer wants to keep the product.
     */
    public function test_an_expired_product_can_be_bought_again(): void
    {
        $this->requireStripe();

        DB::table('wmu_subscription')->insert([
            'user_id' => $this->user->id,
            'start_date' => now()->subYears(2)->format('Y-m-d'),
            'expiration_date' => now()->subYear()->format('Y-m-d'),
            'status' => 'Expired',
            'subscription_amount' => '0.00',
        ]);

        $this->postJson('/v1/wmu-subscription/subscribe', [
            'token' => $this->token(), 'payment_method_id' => 'pm_card_visa',
        ])->assertStatus(201);

        $this->assertSame(2, DB::table('wmu_subscription')
            ->where('user_id', $this->user->id)->count());
    }

    public function test_a_payment_method_is_required(): void
    {
        foreach (array_keys(self::PRODUCTS) as $path) {
            $this->postJson('/v1/'.$path.'/subscribe', ['token' => $this->token()])
                ->assertStatus(422);
        }

        foreach (self::PRODUCTS as [$table]) {
            $this->assertSame(0, DB::table($table)->where('user_id', $this->user->id)->count());
        }
    }

    public function test_a_card_that_cannot_be_saved_leaves_nothing_behind(): void
    {
        $this->requireStripe();

        $this->postJson('/v1/wmu-subscription/subscribe', [
            'token' => $this->token(), 'payment_method_id' => 'pm_card_chargeDeclined',
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('wmu_subscription')
            ->where('user_id', $this->user->id)->count());
        $this->assertSame(0, DB::table('wmu_subscription_transactions')
            ->where('order_id', 'like', 'pi_%')->where('amount', '4.99')->count());
    }

    /**
     * A charge that is not approved leaves nothing behind.
     *
     * Distinct from the declined-at-attach case above: this card attaches
     * fine and then comes back `requires_action`, which is not an approval.
     */
    public function test_a_charge_that_is_not_approved_leaves_nothing_behind(): void
    {
        $this->requireStripe();

        $this->postJson('/v1/dark-mode-subscription/subscribe', [
            'token' => $this->token(),
            'payment_method_id' => 'pm_card_authenticationRequired',
        ])->assertStatus(409);

        $this->assertSame(0, DB::table('darkmode_subscription')
            ->where('user_id', $this->user->id)->count());
    }

    /**
     * Buying WMU does not touch the profile.
     *
     * The old WMU endpoint wrote the caller's phone number into their
     * profile, split on dashes, and refused the whole purchase when it did
     * not split into three parts — so the subscription could not be bought
     * unless the stored phone happened to be formatted `xxx-xxx-xxxx`.
     */
    public function test_buying_wmu_does_not_rewrite_the_profile(): void
    {
        $this->requireStripe();

        $before = (array) DB::table('profile')->where('user_id', $this->user->id)->first();

        $this->postJson('/v1/wmu-subscription/subscribe', [
            'token' => $this->token(),
            'payment_method_id' => 'pm_card_visa',
            // Sent deliberately: the old endpoint would have parsed and
            // stored this, and rejected the purchase if it could not.
            'phone' => 'not-a-phone-number-at-all',
        ])->assertStatus(201);

        $this->assertSame($before, (array) DB::table('profile')
            ->where('user_id', $this->user->id)->first(),
            'Buying a subscription must not edit the profile.');
    }

    public function test_an_unknown_product_has_no_route(): void
    {
        $this->postJson('/v1/nonsense-subscription/subscribe', [
            'token' => $this->token(), 'payment_method_id' => 'pm_card_visa',
        ])->assertStatus(404);
    }
}
