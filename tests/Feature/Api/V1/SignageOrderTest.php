<?php

namespace Tests\Feature\Api\V1;

use App\Mail\SignageOrderPlaced;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Ordering and paying for a listing's signs.
 *
 * The headline here is the shipping price. The old `update-shipping-cost`
 * took a string of the form `Fedex@@@Fedex Ground@@@24.50`, split it on
 * `@@@` and used the third part as the shipping charge — which went into
 * `land.total_amount`, which is the figure `update-payment` charged. So the
 * caller set part of the amount they were about to be billed. Several of the
 * tests below exist only to pin that shut.
 *
 * Everything written belongs to the fixture account and listing and is
 * restored in tearDown, including any promo code a test consumes: these are
 * real codes somebody could still redeem.
 */
class SignageOrderTest extends TestCase
{
    private object $user;

    private int $landId;

    private array $landBefore;

    private int $otherLandId;

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

        $land = DB::table('land')->where('land_name', 'Contract Fixture Land')->first();

        if ($land === null) {
            $this->markTestSkipped('Fixture listing missing; see tests/Fixtures/v1/README.md.');
        }

        $this->user = $user;
        $this->landId = (int) $land->land_id;
        $this->landBefore = (array) $land;

        $this->otherLandId = (int) DB::table('land')
            ->where('owner', '!=', $user->id)
            ->whereNotIn('land_id', function ($q) use ($user) {
                $q->select('land_id')->from('land_managers')->where('user_id', $user->id);
            })
            ->value('land_id');

        /*
         * A rate card for the duration of the test.
         *
         * The shipped default is empty on purpose — the live FedEx quote is
         * not ported and nothing invents a price — but the interesting
         * assertions are about a priced option, so one is configured here.
         */
        config(['clas.signage.shipping.rates' => [
            [
                'code' => 'GROUND',
                'carrier' => 'Fedex',
                'service' => 'Fedex Ground',
                'base' => '10.00',
                'per_item' => '2.50',
            ],
        ]]);

        $this->reset();

        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->landId)) {
            DB::table('land_signages')->where('land_id', $this->landId)->delete();
            DB::table('land')->where('land_id', $this->landId)->update($this->landBefore);

            foreach ($this->codesBefore as $code => $before) {
                DB::table('promo_codes')->where('code', $code)->update($before);
            }
        }

        parent::tearDown();
    }

    /** An empty sign order with an address on file. */
    private function reset(): void
    {
        DB::table('land_signages')->where('land_id', $this->landId)->delete();

        DB::table('land')->where('land_id', $this->landId)->update([
            'signage_type' => 2,
            'required_signages' => 0,
            'signage_amount' => 0.00,
            'signage_promocode' => '',
            'signage_discount_amount' => 0.00,
            'signage_shipping_method' => '',
            'signage_shipping_amount' => 0.00,
            'total_amount' => 0.00,
            'paid_amount' => 0.00,
            'payment_status' => 'Pending',
            'signage_shipping_info' => json_encode([
                'street' => '1 Test Road', 'city' => 'Lethbridge', 'province' => 'AB',
                'country' => 'CA', 'postal_code' => 'T1J 3Y3', 'phone' => '4035550000',
            ]),
        ]);
    }

    private function token(): string
    {
        return $this->user->auth_key;
    }

    private function land(): object
    {
        return DB::table('land')->where('land_id', $this->landId)->first();
    }

    /** Two Red Access Signs at 49.99 — 99.98 of signs. */
    private function orderTwoSigns(): void
    {
        $this->postJson('/v1/manage-listings/add-signages', [
            'token' => $this->token(),
            'id' => $this->landId,
            'signage_options' => [1 => 2],
        ])->assertStatus(200);
    }

    private function unusedCode(string $type): string
    {
        $row = DB::table('promo_codes')->where('type', $type)->where('used', 0)->first();

        if ($row === null) {
            $this->markTestSkipped("No unused '{$type}' promo code to test with.");
        }

        $this->codesBefore[$row->code] = [
            'used' => (int) $row->used, 'used_by' => $row->used_by,
        ];

        return (string) $row->code;
    }

    // ---- adding signs ---------------------------------------------------

    public function test_signs_are_added_and_the_order_restated(): void
    {
        $this->orderTwoSigns();

        $land = $this->land();

        $this->assertSame(2, (int) $land->required_signages);
        $this->assertSame('99.98', (string) $land->signage_amount);

        $this->assertSame(1, DB::table('land_signages')
            ->where('land_id', $this->landId)->count());
    }

    /** Ordering again adds to the line rather than replacing it. */
    public function test_adding_the_same_sign_again_increases_the_quantity(): void
    {
        $this->orderTwoSigns();
        $this->orderTwoSigns();

        $this->assertSame(4, (int) $this->land()->required_signages);
        $this->assertSame('199.96', (string) $this->land()->signage_amount);
    }

    /**
     * The quantity is validated, not merely truthy.
     *
     * The old loop was `if ($option && $qty && array_key_exists(...))`. A
     * negative quantity passes that, and was then *added* to the stored
     * quantity — reducing the order, and taking `signage_amount` down with
     * it. Enough of them and the listing owes a negative amount.
     */
    public function test_a_negative_quantity_is_refused(): void
    {
        $this->orderTwoSigns();

        $this->postJson('/v1/manage-listings/add-signages', [
            'token' => $this->token(),
            'id' => $this->landId,
            'signage_options' => [1 => -5],
        ])->assertStatus(422);

        $this->assertSame(2, (int) $this->land()->required_signages,
            'The order must be untouched by a refused request.');
        $this->assertSame('99.98', (string) $this->land()->signage_amount);
    }

    public function test_a_zero_quantity_is_refused(): void
    {
        $this->postJson('/v1/manage-listings/add-signages', [
            'token' => $this->token(),
            'id' => $this->landId,
            'signage_options' => [1 => 0],
        ])->assertStatus(422);
    }

    public function test_a_quantity_beyond_the_cap_is_refused(): void
    {
        $this->postJson('/v1/manage-listings/add-signages', [
            'token' => $this->token(),
            'id' => $this->landId,
            'signage_options' => [1 => 100000],
        ])->assertStatus(422);

        $this->assertSame(0, (int) $this->land()->required_signages);
    }

    public function test_an_unknown_sign_is_refused(): void
    {
        $this->postJson('/v1/manage-listings/add-signages', [
            'token' => $this->token(),
            'id' => $this->landId,
            'signage_options' => [999 => 1],
        ])->assertStatus(422);
    }

    /** Changing the order invalidates the discount and the shipping choice. */
    public function test_adding_signs_clears_the_promocode_and_shipping(): void
    {
        $this->orderTwoSigns();

        $this->postJson('/v1/manage-listings/promocode', [
            'token' => $this->token(), 'id' => $this->landId, 'code' => 'LO20',
        ])->assertStatus(200);

        $this->postJson('/v1/manage-listings/update-shipping-cost', [
            'token' => $this->token(), 'id' => $this->landId,
            'shipping_method' => 'GROUND',
        ])->assertStatus(200);

        $this->orderTwoSigns();

        $land = $this->land();

        $this->assertSame('', (string) $land->signage_promocode);
        $this->assertSame('0.00', (string) $land->signage_discount_amount);
        $this->assertSame('', (string) $land->signage_shipping_method);
        $this->assertSame('0.00', (string) $land->total_amount);
    }

    public function test_a_listing_the_caller_does_not_manage_cannot_be_ordered_for(): void
    {
        if ($this->otherLandId === 0) {
            $this->markTestSkipped('No listing owned by another account.');
        }

        foreach ([
            'add-signages' => ['signage_options' => [1 => 1]],
            'promocode' => ['code' => 'LO20'],
            'update-shipping-cost' => ['shipping_method' => 'GROUND'],
            'update-payment' => ['payment_method_id' => 'pm_card_visa'],
        ] as $path => $params) {
            $this->postJson('/v1/manage-listings/'.$path, $params + [
                'token' => $this->token(), 'id' => $this->otherLandId,
            ])->assertStatus(404, "manage-listings/{$path} must not answer for another listing.");
        }
    }

    // ---- promo codes ----------------------------------------------------

    public function test_a_code_needs_signs_to_discount(): void
    {
        $this->postJson('/v1/manage-listings/promocode', [
            'token' => $this->token(), 'id' => $this->landId, 'code' => 'LO20',
        ])->assertStatus(422);
    }

    public function test_a_fixed_code_discounts_the_sign_order(): void
    {
        $this->orderTwoSigns();

        $this->postJson('/v1/manage-listings/promocode', [
            'token' => $this->token(), 'id' => $this->landId, 'code' => 'LO20',
        ])->assertStatus(200)->assertJsonPath('data.rate', 20);

        // 20% of 99.98 is 19.996, rounded half up as the old number_format did.
        $this->assertSame('20.00', (string) $this->land()->signage_discount_amount);
    }

    /**
     * `promo_codes.type` decides the rate here too.
     *
     * The old signage ladder looked up `['code' => $code, 'used' => 0,
     * 'type' => 'onetime']` and gave 100% off for anything it found — and
     * gave 100% for any other row that matched as well, so the type column
     * was decorative.
     */
    public function test_the_code_type_decides_the_rate(): void
    {
        $this->orderTwoSigns();

        $half = $this->unusedCode('annual50');

        $this->postJson('/v1/manage-listings/promocode', [
            'token' => $this->token(), 'id' => $this->landId, 'code' => $half,
        ])->assertStatus(200)->assertJsonPath('data.rate', 50);

        $this->assertSame('49.99', (string) $this->land()->signage_discount_amount);
    }

    /** FREELISTING is a signage code, and only a signage code. */
    public function test_freelisting_applies_to_signage_but_not_to_subscriptions(): void
    {
        $this->orderTwoSigns();

        $this->postJson('/v1/manage-listings/promocode', [
            'token' => $this->token(), 'id' => $this->landId, 'code' => 'FREELISTING',
        ])->assertStatus(200)->assertJsonPath('data.rate', 100);

        $this->postJson('/v1/user/apply-promocode', [
            'token' => $this->token(),
            'promo_code' => 'FREELISTING',
            'subscription_type' => 5,
        ])->assertStatus(422, 'FREELISTING never worked against a subscription.');
    }

    /** Quoting a code does not spend it. */
    public function test_quoting_a_code_does_not_consume_it(): void
    {
        $this->orderTwoSigns();

        $code = $this->unusedCode('onetime');

        $this->postJson('/v1/manage-listings/promocode', [
            'token' => $this->token(), 'id' => $this->landId, 'code' => $code,
        ])->assertStatus(200);

        $this->assertSame(0, (int) DB::table('promo_codes')->where('code', $code)->value('used'),
            'The code is spent when the order is paid, not when it is quoted.');
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $this->orderTwoSigns();

        $this->postJson('/v1/manage-listings/promocode', [
            'token' => $this->token(), 'id' => $this->landId, 'code' => 'NOPE-NOT-A-CODE',
        ])->assertStatus(422);
    }

    // ---- shipping: the price is ours ------------------------------------

    public function test_a_shipping_option_is_priced_by_the_server(): void
    {
        $this->orderTwoSigns();

        $this->postJson('/v1/manage-listings/update-shipping-cost', [
            'token' => $this->token(), 'id' => $this->landId,
            'shipping_method' => 'GROUND',
        ])->assertStatus(200);

        $land = $this->land();

        // 10.00 base + 2.50 for each of two signs.
        $this->assertSame('15.00', (string) $land->signage_shipping_amount);
        $this->assertSame('114.98', (string) $land->total_amount);
        $this->assertSame('Fedex@@@Fedex Ground@@@15.00', (string) $land->signage_shipping_method);
    }

    /**
     * The defect this whole class is here for.
     *
     * The old endpoint took the amount out of the caller's own string. This
     * posts a well-formed, plausible method whose only difference is the
     * price, which is exactly what an attacker would send.
     */
    public function test_a_shipping_string_with_an_edited_price_is_refused(): void
    {
        $this->orderTwoSigns();

        foreach ([
            'Fedex@@@Fedex Ground@@@0.00',
            'Fedex@@@Fedex Ground@@@-50.00',
            'Fedex@@@Fedex Ground@@@14.99',
        ] as $tampered) {
            $this->postJson('/v1/manage-listings/update-shipping-cost', [
                'token' => $this->token(), 'id' => $this->landId,
                'shipping_method' => $tampered,
            ])->assertStatus(422, "'{$tampered}' must not be accepted.");
        }

        $land = $this->land();

        $this->assertSame('', (string) $land->signage_shipping_method);
        $this->assertSame('0.00', (string) $land->total_amount);
    }

    /** The exact string we would have produced is accepted, so the above is a price check. */
    public function test_the_servers_own_shipping_string_is_accepted(): void
    {
        $this->orderTwoSigns();

        $this->postJson('/v1/manage-listings/update-shipping-cost', [
            'token' => $this->token(), 'id' => $this->landId,
            'shipping_method' => 'Fedex@@@Fedex Ground@@@15.00',
        ])->assertStatus(200);

        $this->assertSame('15.00', (string) $this->land()->signage_shipping_amount);
    }

    /** A string with no separators was a PHP notice and a silent zero before. */
    public function test_a_malformed_shipping_string_is_refused(): void
    {
        $this->orderTwoSigns();

        $this->postJson('/v1/manage-listings/update-shipping-cost', [
            'token' => $this->token(), 'id' => $this->landId,
            'shipping_method' => 'whatever',
        ])->assertStatus(422);
    }

    public function test_shipping_cannot_be_chosen_before_signs_are(): void
    {
        $this->postJson('/v1/manage-listings/update-shipping-cost', [
            'token' => $this->token(), 'id' => $this->landId,
            'shipping_method' => 'GROUND',
        ])->assertStatus(422);
    }

    /** Collection is always on offer, and costs nothing. */
    public function test_pickup_is_available_with_no_rate_card(): void
    {
        config(['clas.signage.shipping.rates' => []]);

        $this->orderTwoSigns();

        $this->postJson('/v1/manage-listings/update-shipping-cost', [
            'token' => $this->token(), 'id' => $this->landId,
            'shipping_method' => 'CUSTOMER_PICKUP',
        ])->assertStatus(200);

        $this->assertSame('0.00', (string) $this->land()->signage_shipping_amount);
        $this->assertSame('99.98', (string) $this->land()->total_amount);

        // And the carrier option is genuinely gone, so the assertion above
        // is about the empty rate card rather than about pickup alone.
        $this->postJson('/v1/manage-listings/update-shipping-cost', [
            'token' => $this->token(), 'id' => $this->landId,
            'shipping_method' => 'GROUND',
        ])->assertStatus(422);
    }

    /**
     * A province written out in full still gets a carrier quote.
     *
     * The address is free text and always has been: of the stored rows most
     * carry 'AB' and one carries 'Alberta'. Without the mapping that order
     * matches no rate and the customer is quietly left with collection as
     * their only option — a wrong answer that looks like a correct one.
     */
    public function test_a_province_name_is_matched_as_well_as_its_code(): void
    {
        // A rate scoped to one province, unlike the unrestricted one the
        // other tests use — otherwise it would match whatever was sent and
        // this would prove nothing.
        config(['clas.signage.shipping.rates' => [[
            'code' => 'GROUND_AB', 'carrier' => 'Fedex', 'service' => 'FedEx Ground',
            'base' => '0.00', 'per_item' => '40.00', 'provinces' => ['AB'],
        ]]]);

        $this->orderTwoSigns();

        foreach (['AB', 'Alberta', 'alberta'] as $province) {
            $options = $this->postJson('/v1/manage-listings/update-shipping-info', [
                'token' => $this->token(), 'id' => $this->landId,
                'street' => '1 Test Road', 'city' => 'Lethbridge', 'province' => $province,
                'country' => 'CA', 'postal_code' => 'T1J 3Y3', 'phone' => '4035550000',
            ])->assertStatus(200)->json('data.shipping_options');

            $this->assertContains('GROUND_AB', array_column($options, 'code'),
                "'{$province}' must reach the Alberta rate.");
        }

        // And a province the card does not cover still gets nothing but
        // collection, so the mapping has not simply made everything match.
        $options = $this->postJson('/v1/manage-listings/update-shipping-info', [
            'token' => $this->token(), 'id' => $this->landId,
            'street' => '1 Test Road', 'city' => 'Toronto', 'province' => 'Ontario',
            'country' => 'CA', 'postal_code' => 'M5H 2N2', 'phone' => '4165550000',
        ])->assertStatus(200)->json('data.shipping_options');

        $this->assertSame(['CUSTOMER_PICKUP'], array_column($options, 'code'));
    }

    public function test_shipping_options_come_back_with_the_address(): void
    {
        $this->orderTwoSigns();

        $options = $this->postJson('/v1/manage-listings/update-shipping-info', [
            'token' => $this->token(), 'id' => $this->landId,
            'street' => '1 Test Road', 'city' => 'Lethbridge', 'province' => 'AB',
            'country' => 'CA', 'postal_code' => 'T1J 3Y3', 'phone' => '4035550000',
        ])->assertStatus(200)->json('data.shipping_options');

        $this->assertNotEmpty($options);
        $this->assertContains('GROUND', array_column($options, 'code'));
        $this->assertContains('CUSTOMER_PICKUP', array_column($options, 'code'));
    }

    // ---- paying ---------------------------------------------------------

    public function test_an_order_with_nothing_to_pay_completes_without_a_card(): void
    {
        $this->orderTwoSigns();

        $this->postJson('/v1/manage-listings/promocode', [
            'token' => $this->token(), 'id' => $this->landId, 'code' => 'FREELISTING',
        ])->assertStatus(200);

        $this->postJson('/v1/manage-listings/update-shipping-cost', [
            'token' => $this->token(), 'id' => $this->landId,
            'shipping_method' => 'CUSTOMER_PICKUP',
        ])->assertStatus(200);

        $this->postJson('/v1/manage-listings/update-payment', [
            'token' => $this->token(), 'id' => $this->landId,
        ])->assertStatus(200)->assertJsonPath('data.amount_paid', '0.00');

        $land = $this->land();

        $this->assertSame('Completed', (string) $land->payment_status);
        $this->assertSame(1, (int) $land->current_step);

        Mail::assertSent(SignageOrderPlaced::class);
    }

    /** Paying spends the code, which the old signage path never did. */
    public function test_paying_marks_a_single_use_code_used(): void
    {
        $this->orderTwoSigns();

        $code = $this->unusedCode('onetime');

        $this->postJson('/v1/manage-listings/promocode', [
            'token' => $this->token(), 'id' => $this->landId, 'code' => $code,
        ])->assertStatus(200);

        $this->postJson('/v1/manage-listings/update-shipping-cost', [
            'token' => $this->token(), 'id' => $this->landId,
            'shipping_method' => 'CUSTOMER_PICKUP',
        ])->assertStatus(200);

        $this->postJson('/v1/manage-listings/update-payment', [
            'token' => $this->token(), 'id' => $this->landId,
        ])->assertStatus(200);

        $this->assertSame(1, (int) DB::table('promo_codes')->where('code', $code)->value('used'),
            'The old signage path stored the code and never incremented used, '
            .'so a single-use code worked on every listing for ever.');
    }

    /**
     * An order cannot be paid twice.
     *
     * Nothing in the old endpoint looked at `payment_status`; calling it
     * again charged the card a second time and overwrote the first receipt,
     * so there was not even a record of the double charge.
     */
    public function test_a_paid_listing_cannot_be_paid_again(): void
    {
        $this->orderTwoSigns();

        $this->postJson('/v1/manage-listings/promocode', [
            'token' => $this->token(), 'id' => $this->landId, 'code' => 'FREELISTING',
        ]);
        $this->postJson('/v1/manage-listings/update-shipping-cost', [
            'token' => $this->token(), 'id' => $this->landId,
            'shipping_method' => 'CUSTOMER_PICKUP',
        ]);
        $this->postJson('/v1/manage-listings/update-payment', [
            'token' => $this->token(), 'id' => $this->landId,
        ])->assertStatus(200);

        $this->postJson('/v1/manage-listings/update-payment', [
            'token' => $this->token(), 'id' => $this->landId,
        ])->assertStatus(409);
    }

    /**
     * Asserting the message, not just the status.
     *
     * Without the explicit check, this still refuses — the total cannot be
     * priced without a shipping method, so it fails further in with "that
     * option is no longer available", which is both confusing and wrong.
     * The sabotage harness caught the test passing for that reason, so it
     * names the answer it expects.
     */
    public function test_paying_before_choosing_shipping_is_refused(): void
    {
        $this->orderTwoSigns();

        $this->postJson('/v1/manage-listings/update-payment', [
            'token' => $this->token(), 'id' => $this->landId,
            'payment_method_id' => 'pm_card_visa',
        ])->assertStatus(409)
            ->assertJsonPath('error', 'Please choose a shipping option first.');
    }

    public function test_paying_with_nothing_ordered_is_refused(): void
    {
        $this->postJson('/v1/manage-listings/update-payment', [
            'token' => $this->token(), 'id' => $this->landId,
            'payment_method_id' => 'pm_card_visa',
        ])->assertStatus(409);
    }

    /** A real charge, against Stripe test mode. */
    public function test_a_paid_order_is_charged_and_recorded(): void
    {
        if (! str_starts_with((string) config('services.stripe.secret'), 'sk_test_')) {
            $this->markTestSkipped('No Stripe test key configured.');
        }

        $this->orderTwoSigns();

        $this->postJson('/v1/manage-listings/update-shipping-cost', [
            'token' => $this->token(), 'id' => $this->landId,
            'shipping_method' => 'CUSTOMER_PICKUP',
        ])->assertStatus(200);

        $card = app(PaymentGateway::class)
            ->rememberCard(User::find($this->user->id), 'pm_card_visa');

        $this->postJson('/v1/manage-listings/update-payment', [
            'token' => $this->token(), 'id' => $this->landId,
            'payment_method_id' => $card->paymentMethodId,
        ])->assertStatus(200)->assertJsonPath('data.amount_paid', '99.98');

        $land = $this->land();

        $this->assertSame('Completed', (string) $land->payment_status);
        $this->assertSame('99.98', (string) $land->paid_amount);
        $this->assertNotEmpty($land->txn_number);
    }
}
