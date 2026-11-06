<?php

namespace App\Services\Payments;

/**
 * What a promotional code is worth on a given subscription.
 *
 * Amounts are decimal strings throughout, matching `config/clas.php` and the
 * `decimal` columns, and are computed in cents so the arithmetic is exact —
 * see Money for why that matters.
 */
class Discount
{
    public function __construct(
        public string $code,
        public int $rate,
        public string $subscriptionAmount,
        public string $discountAmount,
        public string $total,
        /** The `promo_codes.type` this came from, or null for a fixed code. */
        public ?string $type = null,
    ) {}

    /** No code, or a code worth nothing: the full price stands. */
    public static function none(string $amount): self
    {
        return new self('', 0, $amount, '0.00', $amount);
    }

    /** Built from a percentage, in cents, so the rounding is stated once. */
    public static function ofPercentage(
        string $code,
        int $rate,
        string $amount,
        ?string $type = null,
    ): self {
        $cents = Money::minorUnits($amount);

        // Rounded to the nearest cent, half up — the old code used
        // number_format, which rounds the same way. 20% of 9.99 is 1.998,
        // which both make 2.00.
        $discountCents = (int) round($cents * $rate / 100);

        return new self(
            code: $code,
            rate: $rate,
            subscriptionAmount: $amount,
            discountAmount: Money::fromMinorUnits($discountCents),
            total: Money::fromMinorUnits($cents - $discountCents),
            type: $type,
        );
    }

    /** Whether this leaves nothing to charge. */
    public function isFree(): bool
    {
        return Money::minorUnits($this->total) === 0;
    }

    public function wasApplied(): bool
    {
        return $this->code !== '' && $this->rate > 0;
    }

    /** @return array<string,mixed> the shape the app already reads */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'rate' => $this->rate,
            'subscription_amount' => $this->subscriptionAmount,
            'discount' => $this->discountAmount,
            'final_total' => $this->total,
        ];
    }
}
