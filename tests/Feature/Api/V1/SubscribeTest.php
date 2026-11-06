<?php

namespace Tests\Feature\Api\V1;

use App\Mail\SubscriptionStarted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Buying a subscription.
 *
 * These write to the shared database. Everything created belongs to the
 * fixture account and is removed in tearDown, and the promo codes the tests
 * consume have their `used` and `used_by` restored — they are real codes that
 * somebody could still redeem.
 *
 * The paid path talks to Stripe in test mode and skips when no test key is
 * configured.
 */
class SubscribeTest extends TestCase
{
    private object $user;

    /** @var array<string,array{used:int,used_by:int|null}> */
    private array $codesBefore = [];

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
            $this->markTestSkipped('Run the gateway columns migration first: '
                .'php artisan migrate --database=clas_api');
        }

        $this->user = $user;
        $this->clearFixtureRows();

        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->user)) {
            $this->clearFixtureRows();

            foreach ($this->codesBefore as $code => $before) {
                DB::table('promo_codes')->where('code', $code)->update($before);
            }
        }

        parent::tearDown();
    }

    private function clearFixtureRows(): void
    {
        foreach ([
            'user_subscription' => 'subscription_transactions',
            'wmu_subscription' => 'wmu_subscription_transactions',
            'fishing_lands_subscription' => 'fishing_lands_subscription_transactions',
        ] as $table => $transactions) {
            $ids = DB::table($table)->where('user_id', $this->user->id)
                ->pluck($table === 'user_subscription' ? 'subscription_id' : 'id');

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

    /** An unused code of the given type, remembered so it can be restored. */
    private function unusedCode(string $type): string
    {
        $row = DB::table('promo_codes')
            ->where('type', $type)
            ->where('used', 0)
            ->first();

        if ($row === null) {
            $this->markTestSkipped("No unused '{$type}' promo code to test with.");
        }

        $this->codesBefore[$row->code] = [
            'used' => (int) $row->used,
            'used_by' => $row->used_by,
        ];

        return (string) $row->code;
    }

    // ---- what a code is worth ------------------------------------------

    /**
     * `promo_codes.type` decides the discount.
     *
     * The old calculation gave 100% off for any code present in the table,
     * so all 300 `annual50` codes were granting a free year instead of half
     * off. This is the behaviour change that matters most for anyone holding
     * a code already.
     */
    public function test_the_code_type_decides_the_rate(): void
    {
        $half = $this->unusedCode('annual50');

        $data = $this->postJson('/v1/user/apply-promocode', [
            'token' => $this->token(),
            'promo_code' => $half,
            'subscription_type' => 5,
        ])->assertStatus(200)->json('data');

        $this->assertSame(50, $data['rate'], 'An annual50 code is half off, not free.');
        $this->assertSame('9.99', $data['subscription_amount']);
        $this->assertSame('5.00', $data['discount']);
        $this->assertSame('4.99', $data['final_total']);

        $free = $this->unusedCode('sales100');

        $this->postJson('/v1/user/apply-promocode', [
            'token' => $this->token(),
            'promo_code' => $free,
            'subscription_type' => 5,
        ])->assertStatus(200)
            ->assertJsonPath('data.rate', 100)
            ->assertJsonPath('data.final_total', '0.00');
    }

    /** The three literals the old controller special-cased still work. */
    public function test_the_fixed_codes_are_still_twenty_percent(): void
    {
        $this->postJson('/v1/user/apply-promocode', [
            'token' => $this->token(),
            'promo_code' => 'abp20',
            'subscription_type' => 5,
        ])->assertStatus(200)
            ->assertJsonPath('data.rate', 20)
            ->assertJsonPath('data.code', 'ABP20')
            ->assertJsonPath('data.discount', '2.00')
            ->assertJsonPath('data.final_total', '7.99');
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $this->postJson('/v1/user/apply-promocode', [
            'token' => $this->token(),
            'promo_code' => 'NOT-A-REAL-CODE',
            'subscription_type' => 5,
        ])->assertStatus(422);
    }

    /**
     * A single-use code that has been redeemed is refused.
     *
     * The old code incremented `used` and never read it. One code of type
     * `onetime` has been redeemed 28 times in the live data.
     */
    public function test_a_spent_single_use_code_is_refused(): void
    {
        $code = $this->unusedCode('onetime');

        $this->postJson('/v1/user/apply-promocode', [
            'token' => $this->token(), 'promo_code' => $code, 'subscription_type' => 5,
        ])->assertStatus(200);

        DB::table('promo_codes')->where('code', $code)->update(['used' => 1]);

        $this->postJson('/v1/user/apply-promocode', [
            'token' => $this->token(), 'promo_code' => $code, 'subscription_type' => 5,
        ])->assertStatus(422);
    }

    /** Quoting a price must not consume the code. */
    public function test_quoting_a_price_consumes_nothing(): void
    {
        $code = $this->unusedCode('sales100');

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/v1/user/apply-promocode', [
                'token' => $this->token(), 'promo_code' => $code, 'subscription_type' => 5,
            ])->assertStatus(200);
        }

        $this->assertSame(0, (int) DB::table('promo_codes')->where('code', $code)->value('used'),
            'apply-promocode is a read.');
    }

    // ---- buying ---------------------------------------------------------

    /**
     * The whole free path, and the invariant that protects it.
     *
     * A 100% code needs no card. Everything the old endpoint wrote is
     * written, plus the two columns that keep the old Yii renewal cron away
     * from this subscription.
     */
    public function test_a_free_subscription_is_written_with_the_invariant(): void
    {
        $code = $this->unusedCode('sales100');

        $data = $this->postJson('/v1/user/subscribe', [
            'token' => $this->token(),
            'subscription_type' => 5,
            'promo_code' => $code,
        ])->assertStatus(201)->json('data');

        $this->assertSame('0.00', $data['amount_paid']);
        $this->assertNull($data['reference'], 'Nothing was charged, so there is no payment.');

        $row = DB::table('user_subscription')
            ->where('subscription_id', $data['subscription_id'])->first();

        // The invariant: docs/moneris-to-stripe-migration.md explains what
        // happens without these two.
        $this->assertSame(0, (int) $row->auto_renew);
        $this->assertSame('stripe', $row->gateway);

        $this->assertSame('Active', $row->status);
        $this->assertSame('9.99', $row->subscription_amount);
        $this->assertSame('0.00', $row->amount_paid);
        $this->assertSame($code, $row->promo_code);
        $this->assertSame('CLAS-USER-'.$this->user->id, $row->customer_id);

        // The two free add-ons both paths have always created.
        foreach (['wmu_subscription', 'fishing_lands_subscription'] as $addon) {
            $addonRow = DB::table($addon)->where('user_id', $this->user->id)->first();
            $this->assertNotNull($addonRow, $addon.' should have been created.');
            $this->assertSame('FREE ADD-ON', $addonRow->txn_number);
            $this->assertSame('0.00', $addonRow->subscription_amount);
            $this->assertSame('stripe', $addonRow->gateway);
        }

        $this->assertSame('FREE SUBSCRIPTION', DB::table('subscription_transactions')
            ->where('subscription_id', $data['subscription_id'])->value('txn_status'));

        $this->assertSame(1, (int) DB::table('promo_codes')->where('code', $code)->value('used'),
            'Buying consumes the code, where quoting does not.');

        Mail::assertSent(SubscriptionStarted::class);
    }

    public function test_an_account_cannot_subscribe_twice(): void
    {
        $code = $this->unusedCode('sales100');

        $this->postJson('/v1/user/subscribe', [
            'token' => $this->token(), 'subscription_type' => 5, 'promo_code' => $code,
        ])->assertStatus(201);

        $second = $this->unusedCode('sales100');

        $this->postJson('/v1/user/subscribe', [
            'token' => $this->token(), 'subscription_type' => 5, 'promo_code' => $second,
        ])->assertStatus(409);

        $this->assertSame(1, DB::table('user_subscription')
            ->where('user_id', $this->user->id)->count());
    }

    public function test_a_paid_subscription_needs_a_payment_method(): void
    {
        $this->postJson('/v1/user/subscribe', [
            'token' => $this->token(),
            'subscription_type' => 5,
        ])->assertStatus(409)
            ->assertJsonPath('error', 'A payment method is required.');

        $this->assertSame(0, DB::table('user_subscription')
            ->where('user_id', $this->user->id)->count());
    }

    public function test_a_retired_subscription_type_cannot_be_bought(): void
    {
        // Types 1 and 2 are legacy: the old renewal cron only charges 3, 4
        // and 5, so nothing renews them.
        $this->postJson('/v1/user/subscribe', [
            'token' => $this->token(), 'subscription_type' => 1,
        ])->assertStatus(409);
    }

    public function test_an_unknown_subscription_type_is_refused(): void
    {
        $this->postJson('/v1/user/subscribe', [
            'token' => $this->token(), 'subscription_type' => 99,
        ])->assertStatus(422);
    }

    /** The paid path, end to end, against Stripe test mode. */
    public function test_a_card_is_charged_and_the_subscription_recorded(): void
    {
        if (! str_starts_with((string) config('services.stripe.secret'), 'sk_test_')) {
            $this->markTestSkipped('No Stripe test key configured.');
        }

        $data = $this->postJson('/v1/user/subscribe', [
            'token' => $this->token(),
            'subscription_type' => 5,
            'payment_method_id' => 'pm_card_visa',
        ])->assertStatus(201)->json('data');

        $this->assertSame('9.99', $data['amount_paid']);
        $this->assertStringStartsWith('pi_', (string) $data['reference']);
        $this->assertNull($data['discount']);

        $row = DB::table('user_subscription')
            ->where('subscription_id', $data['subscription_id'])->first();

        $this->assertSame(0, (int) $row->auto_renew);
        $this->assertSame('stripe', $row->gateway);
        $this->assertStringStartsWith('cus_', (string) $row->stripe_customer_id);
        $this->assertStringStartsWith('pm_', (string) $row->stripe_payment_method_id);
        $this->assertSame('9.99', $row->amount_paid);

        // The Moneris token column is untouched: this subscription has no
        // Moneris card, and the old cron must never find one for it.
        $this->assertNull($row->data_key);

        $this->assertSame('APPROVED', DB::table('subscription_transactions')
            ->where('subscription_id', $data['subscription_id'])->value('txn_status'));
    }

    /**
     * A charge that does not succeed leaves nothing behind either.
     *
     * Distinct from the test below, which fails when the card is attached and
     * so never reaches the charge. This one attaches fine and comes back
     * `requires_action` — the cardholder would have to complete 3-D Secure —
     * which is not an approval, so no subscription may be written.
     */
    public function test_a_charge_that_does_not_succeed_leaves_nothing_behind(): void
    {
        if (! str_starts_with((string) config('services.stripe.secret'), 'sk_test_')) {
            $this->markTestSkipped('No Stripe test key configured.');
        }

        $this->postJson('/v1/user/subscribe', [
            'token' => $this->token(),
            'subscription_type' => 5,
            'payment_method_id' => 'pm_card_authenticationRequired',
        ])->assertStatus(409);

        $this->assertSame(0, DB::table('user_subscription')
            ->where('user_id', $this->user->id)->count(),
            'An unapproved charge must not create a subscription.');

        foreach (['wmu_subscription', 'fishing_lands_subscription'] as $addon) {
            $this->assertSame(0, DB::table($addon)->where('user_id', $this->user->id)->count());
        }
    }

    /** A card the gateway will not even hold does not create a subscription. */
    public function test_a_card_that_cannot_be_saved_leaves_nothing_behind(): void
    {
        if (! str_starts_with((string) config('services.stripe.secret'), 'sk_test_')) {
            $this->markTestSkipped('No Stripe test key configured.');
        }

        $this->postJson('/v1/user/subscribe', [
            'token' => $this->token(),
            'subscription_type' => 5,
            'payment_method_id' => 'pm_card_chargeDeclined',
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('user_subscription')
            ->where('user_id', $this->user->id)->count());

        foreach (['wmu_subscription', 'fishing_lands_subscription'] as $addon) {
            $this->assertSame(0, DB::table($addon)->where('user_id', $this->user->id)->count(),
                'A failed purchase must not leave an add-on behind.');
        }
    }
}
