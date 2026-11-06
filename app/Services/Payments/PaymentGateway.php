<?php

namespace App\Services\Payments;

use App\Models\User;

/**
 * Taking money, with the processor behind an interface.
 *
 * There is one implementation, Stripe. The interface exists for two reasons
 * that are not "we might swap providers one day":
 *
 * 1. **The endpoints must not know about a processor.** The old controllers
 *    built `mpgTransaction` objects inline, read `getResponseCode()` to
 *    decide success, and stored Moneris's response shape in the subscription
 *    tables. That is how a gateway becomes impossible to leave, and leaving
 *    one is exactly what this project is doing.
 * 2. **Phase 2 may need two at once.** If the card-file migration ends up
 *    needing the Moneris charge path inside this application during the ramp
 *    — option C in docs/moneris-to-stripe-migration.md — it arrives as a
 *    second implementation rather than as branches through the endpoints.
 *
 * Every amount is a decimal string, as `config/clas.php` holds them; the
 * implementation converts. Nothing here takes a card number: a payment
 * method is created on the device by the gateway's own SDK and reaches this
 * API as an opaque id. That is what keeps the application out of PCI DSS
 * SAQ D scope, and it is the main reason the old flow is not being ported.
 */
interface PaymentGateway
{
    /**
     * Whether this deployment is configured to take payments at all.
     *
     * False with no key, and false for a key whose prefix this deployment
     * does not allow — so a live key in a test deployment disables payments
     * rather than charging real cards.
     */
    public function isConfigured(): bool;

    /**
     * Attach a payment method to the customer, creating one if needed.
     *
     * Idempotent in the sense that matters: calling it twice for the same
     * user does not create a second customer.
     *
     * @throws PaymentGatewayException when the gateway refuses
     */
    public function rememberCard(User $user, string $paymentMethodId): StoredCard;

    /**
     * Charge a card the gateway is already holding.
     *
     * `$idempotencyKey` must be stable for a given logical charge: the
     * renewal scheduler builds it from the subscription id and the period
     * being paid for, so a retry after a timeout cannot take the money
     * twice. The old renewal code put `time()` in its order id, which
     * guaranteed the opposite.
     *
     * `$onSession` is true when the cardholder is present and can
     * authenticate, which is the case for a purchase made in the app, and
     * false for an unattended renewal.
     *
     * @param  string  $amount  decimal, as config holds it: '9.99'
     */
    public function charge(
        StoredCard $card,
        string $amount,
        string $description,
        string $idempotencyKey,
        bool $onSession = false,
    ): PaymentResult;
}
