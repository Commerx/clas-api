<?php

namespace App\Services\Payments;

/**
 * What came of a charge.
 *
 * Deliberately a small, gateway-neutral shape. The old integration passed
 * Moneris's `mpgResponse` object around and read `getResponseCode() < 50` at
 * each call site to decide success, so the definition of "approved" lived in
 * four places and the response object leaked into the subscription logic.
 */
class PaymentResult
{
    /**
     * @param  bool  $approved  whether the money moved
     * @param  string  $reference  the gateway's id for this charge, stored on the row
     * @param  string  $status  the gateway's own status, for the transaction log
     * @param  string  $message  something a human can read; may reach an email
     * @param  bool  $requiresAction  the cardholder has to authenticate (3DS)
     * @param  string|null  $declineCode  the gateway's decline code, when it gave one
     * @param  array<string,mixed>  $raw  what to write to `txn_response`
     */
    public function __construct(
        public bool $approved,
        public string $reference,
        public string $status,
        public string $message,
        public bool $requiresAction = false,
        public ?string $declineCode = null,
        public array $raw = [],
    ) {}

    /**
     * Whether retrying this charge later could plausibly work.
     *
     * The distinction the old code never drew: it emailed "payment declined"
     * for a dead card and for a network blip alike, and its retry schedule
     * treated both the same. `card_declined` with `do_not_honor` is not worth
     * retrying on the same card; a rate limit or an API error is.
     */
    public function isWorthRetrying(): bool
    {
        if ($this->approved) {
            return false;
        }

        return ! in_array($this->declineCode, [
            'card_declined',
            'expired_card',
            'incorrect_number',
            'invalid_account',
            'do_not_honor',
            'pickup_card',
            'stolen_card',
            'lost_card',
            'revocation_of_authorization',
        ], true);
    }
}
