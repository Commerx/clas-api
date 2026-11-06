<?php

namespace App\Services\Payments;

/**
 * A card the gateway is holding for a customer.
 *
 * `last4` and the expiry are kept because they are what makes the phase 2
 * migration verifiable: `user_moneris_cards` already holds a masked PAN and
 * expiry for every Moneris card, and Stripe's import mapping file returns the
 * same fields, so a mapped card can be checked against what we already knew
 * rather than trusted. They are also what the app shows on the payment
 * screen.
 */
class StoredCard
{
    public function __construct(
        public string $customerId,
        public string $paymentMethodId,
        public ?string $brand = null,
        public ?string $last4 = null,
        public ?int $expiryMonth = null,
        public ?int $expiryYear = null,
    ) {}

    /** `MM/YYYY`, the shape `user_moneris_cards.expiry` uses. */
    public function expiry(): ?string
    {
        if ($this->expiryMonth === null || $this->expiryYear === null) {
            return null;
        }

        return sprintf('%02d/%04d', $this->expiryMonth, $this->expiryYear);
    }
}
