<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Services\Payments\Money;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentResult;
use App\Services\Payments\StoredCard;
use App\Services\Payments\StripeGateway;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The payment gateway.
 *
 * Most of this needs no network: the amount conversion, the key guard and the
 * decline classification are all our own logic. The two that do talk to
 * Stripe are marked, use test mode, and skip rather than fail when the keys
 * are absent — so the suite stays runnable on a machine with no Stripe
 * configuration.
 */
class StripeGatewayTest extends TestCase
{
    // ---- amounts -------------------------------------------------------

    /**
     * The float trap, stated as the test that catches it.
     *
     * `(int) ($amount * 100)` is exact for some amounts and a cent short for
     * others, which is the worst way for this to behave — it would have
     * charged 9.99 correctly and 69.99 a cent short, and 69.99 is the annual
     * price in the Yii base config.
     */
    public function test_amounts_convert_without_going_through_a_float(): void
    {
        $this->assertSame(999, Money::minorUnits('9.99'));
        $this->assertSame(5394, Money::minorUnits('53.94'));
        $this->assertSame(4999, Money::minorUnits('49.99'));
        $this->assertSame(499, Money::minorUnits('4.99'));
        $this->assertSame(2999, Money::minorUnits('29.99'));

        // The amounts where the naive version loses a cent, so the test fails
        // if someone "simplifies" Money::minorUnits back to arithmetic.
        foreach (['19.99' => 1999, '69.99' => 6999, '1.15' => 115] as $amount => $cents) {
            $this->assertSame($cents, Money::minorUnits((string) $amount));
            $this->assertSame($cents - 1, (int) ($amount * 100),
                "'{$amount}' is one of the amounts a float gets wrong; if this "
                .'assertion fails the trap has changed shape, not disappeared.');
        }
    }

    public function test_amounts_round_trip(): void
    {
        foreach (['9.99', '53.94', '0.01', '100.00', '4.99'] as $amount) {
            $this->assertSame($amount, Money::fromMinorUnits(Money::minorUnits($amount)));
        }
    }

    public function test_an_amount_with_too_much_precision_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Not silently truncated: a third decimal place means somebody has
        // computed a price wrongly, and charging a rounded version hides it.
        Money::minorUnits('9.999');
    }

    public function test_every_configured_price_converts(): void
    {
        $prices = [];

        foreach (config('clas.subscriptions.types') as $type) {
            $prices[] = $type['amount'];
        }

        foreach (['wmu', 'dark_mode', 'fishing_lands'] as $product) {
            foreach ((array) config('clas.prices.'.$product) as $amount) {
                $prices[] = $amount;
            }
        }

        $this->assertNotEmpty($prices);

        foreach ($prices as $amount) {
            $cents = Money::minorUnits((string) $amount);
            $this->assertGreaterThan(0, $cents, "'{$amount}' converted to nothing.");
        }
    }

    // ---- the key guard -------------------------------------------------

    /**
     * A live key in a test deployment disables payments.
     *
     * The keys this project holds are the marketplace *test* keys. The guard
     * is what makes reusing them safe: paste a live key into .env and the
     * endpoints report that payments are unavailable, rather than charging
     * real cards. Going live is then a deliberate change to the allowed
     * prefixes.
     */
    public function test_a_live_key_is_refused_unless_explicitly_allowed(): void
    {
        $gateway = new StripeGateway;

        config(['services.stripe.allowed_key_prefixes' => ['sk_test_']]);

        config(['services.stripe.secret' => 'sk_test_abc123']);
        $this->assertTrue($gateway->isConfigured());

        config(['services.stripe.secret' => 'sk_live_abc123']);
        $this->assertFalse($gateway->isConfigured(),
            'A live key must not be usable while only test keys are allowed.');

        config(['services.stripe.secret' => '']);
        $this->assertFalse($gateway->isConfigured());

        // Going live is opting in, explicitly.
        config([
            'services.stripe.secret' => 'sk_live_abc123',
            'services.stripe.allowed_key_prefixes' => ['sk_live_'],
        ]);
        $this->assertTrue($gateway->isConfigured());
    }

    public function test_an_unconfigured_gateway_refuses_rather_than_pretending(): void
    {
        config(['services.stripe.secret' => '']);

        $this->expectException(PaymentGatewayException::class);

        (new StripeGateway)->charge(
            new StoredCard('cus_x', 'pm_x'), '9.99', 'nope', 'key-1',
        );
    }

    // ---- declines ------------------------------------------------------

    /**
     * A dead card and a network blip are not the same failure.
     *
     * The old renewal code emailed "payment declined" for both and retried
     * both on the same schedule. 83 of the 2024 transactions carry
     * "CARD DECLINED * DO NOT RETRY", which is precisely the case where
     * retrying is pointless.
     */
    public function test_declines_are_classified_by_whether_a_retry_could_work(): void
    {
        foreach (['card_declined', 'expired_card', 'stolen_card', 'do_not_honor'] as $code) {
            $this->assertFalse(
                (new PaymentResult(false, '', 'declined', 'no', declineCode: $code))->isWorthRetrying(),
                "'{$code}' must not be retried on the same card.",
            );
        }

        foreach (['processing_error', 'try_again_later', null] as $code) {
            $this->assertTrue(
                (new PaymentResult(false, '', 'declined', 'no', declineCode: $code))->isWorthRetrying(),
                var_export($code, true).' is worth retrying.',
            );
        }

        $this->assertFalse(
            (new PaymentResult(true, 'pi_1', 'succeeded', 'Approved'))->isWorthRetrying(),
            'An approved charge is never retried.',
        );
    }

    public function test_a_stored_card_reports_its_expiry_the_way_the_schema_holds_it(): void
    {
        $card = new StoredCard('cus_1', 'pm_1', 'visa', '4242', 3, 2029);

        // user_moneris_cards.expiry is MM/YYYY text, and the phase 2 mapping
        // verification compares against it.
        $this->assertSame('03/2029', $card->expiry());
        $this->assertNull((new StoredCard('cus_1', 'pm_1'))->expiry());
    }

    // ---- against Stripe, in test mode ----------------------------------

    /**
     * A card is attached and charged, and the same idempotency key does not
     * charge twice.
     *
     * The second half is the one that matters for renewals. The old code put
     * `time()` in its order id, so a retry after a timeout was a new charge
     * every time.
     */
    public function test_a_card_is_attached_charged_and_charged_only_once(): void
    {
        $gateway = $this->liveTestModeGateway();
        $user = $this->fixtureUser();

        $card = $gateway->rememberCard($user, 'pm_card_visa');

        $this->assertStringStartsWith('cus_', $card->customerId);
        $this->assertStringStartsWith('pm_', $card->paymentMethodId);
        $this->assertSame('4242', $card->last4);
        $this->assertSame('visa', $card->brand);

        $key = 'test-'.$user->id.'-'.bin2hex(random_bytes(6));

        $first = $gateway->charge($card, '9.99', 'CLAS test suite', $key, onSession: true);

        $this->assertTrue($first->approved, 'Stripe test Visa should approve: '.$first->message);
        $this->assertSame('succeeded', $first->status);
        $this->assertSame(999, $first->raw['amount']);
        $this->assertSame('cad', $first->raw['currency']);

        $second = $gateway->charge($card, '9.99', 'CLAS test suite', $key, onSession: true);

        $this->assertSame($first->reference, $second->reference,
            'The same idempotency key must return the original charge, not make a second.');
    }

    /** A card the gateway will not hold is refused, not half-stored. */
    public function test_a_declining_card_cannot_be_saved(): void
    {
        $gateway = $this->liveTestModeGateway();

        $this->expectException(PaymentGatewayException::class);

        $gateway->rememberCard($this->fixtureUser(), 'pm_card_chargeDeclined');
    }

    private function liveTestModeGateway(): PaymentGateway
    {
        $secret = (string) config('services.stripe.secret');

        if (! str_starts_with($secret, 'sk_test_')) {
            $this->markTestSkipped('No Stripe test key configured; see .env.example.');
        }

        return app(PaymentGateway::class);
    }

    private function fixtureUser(): User
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => env('CONTRACT_DB', 'clas'),
        ]);

        try {
            $user = User::where('username', 'contract_fixture')->first();
        } catch (\Throwable $e) {
            $this->markTestSkipped('clas database not reachable: '.$e->getMessage());
        }

        if ($user === null) {
            $this->markTestSkipped('Fixture account missing; see tests/Fixtures/v1/README.md.');
        }

        // Nothing in these tests writes to the database; assert it rather
        // than rely on it, since the gateway is given a real account.
        $this->assertSame(0, DB::table('user_subscription')
            ->where('user_id', $user->id)->count(),
            'The fixture account should hold no subscriptions.');

        return $user;
    }
}
