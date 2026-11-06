<?php

namespace App\Services\Payments;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\StripeClient;

/**
 * Stripe.
 *
 * Three things here are deliberate and worth reading before changing them.
 *
 * **A key whose prefix is not allowed disables payments.** The keys this
 * deployment holds are the marketplace *test* keys, and
 * `services.stripe.allowed_key_prefixes` defaults to `sk_test_`. Drop a live
 * key into .env and `isConfigured()` returns false, so the endpoints answer
 * "payments are not available" instead of charging real cards. Going live is
 * then a deliberate change to that list, not an accident with a paste buffer.
 *
 * **Every charge carries an idempotency key.** The caller supplies one that
 * is stable for the logical charge — subscription id plus the period being
 * paid for — so a retry after a timeout returns the original charge rather
 * than making a second one. The old renewal code built its order id with
 * `time()`, which made every retry a new charge; the transaction history
 * shows runs where the same subscription was attempted repeatedly.
 *
 * **An unattended renewal fails rather than waits.** Renewals are charged
 * `off_session` with `error_on_requires_action`, so a card that wants 3-D
 * Secure comes back as a clean failure the scheduler can report, instead of
 * a payment intent left half-finished with nobody present to authenticate.
 */
class StripeGateway implements PaymentGateway
{
    private ?StripeClient $client = null;

    public function isConfigured(): bool
    {
        $secret = (string) config('services.stripe.secret');

        if ($secret === '') {
            return false;
        }

        foreach ((array) config('services.stripe.allowed_key_prefixes') as $prefix) {
            if (str_starts_with($secret, trim((string) $prefix))) {
                return true;
            }
        }

        // Worth a log line: a configured-but-refused key is somebody's
        // mistake, and silence would make it look like Stripe was never set
        // up at all.
        Log::warning('Stripe key refused: its prefix is not in services.stripe.allowed_key_prefixes.', [
            'prefix' => substr($secret, 0, 8),
        ]);

        return false;
    }

    public function rememberCard(User $user, string $paymentMethodId): StoredCard
    {
        $stripe = $this->client();

        try {
            $customerId = $this->customerFor($user);

            $method = $stripe->paymentMethods->attach($paymentMethodId, [
                'customer' => $customerId,
            ]);

            // Make it the default for off-session charges, so a renewal does
            // not have to guess which card to use when a customer has more
            // than one attached.
            $stripe->customers->update($customerId, [
                'invoice_settings' => ['default_payment_method' => $method->id],
            ]);
        } catch (ApiErrorException $e) {
            throw new PaymentGatewayException(
                'That card could not be saved: '.$e->getMessage(), 0, $e
            );
        }

        $card = $method->card;

        return new StoredCard(
            customerId: $customerId,
            paymentMethodId: $method->id,
            brand: $card->brand ?? null,
            last4: $card->last4 ?? null,
            expiryMonth: isset($card->exp_month) ? (int) $card->exp_month : null,
            expiryYear: isset($card->exp_year) ? (int) $card->exp_year : null,
        );
    }

    public function charge(
        StoredCard $card,
        string $amount,
        string $description,
        string $idempotencyKey,
        bool $onSession = false,
    ): PaymentResult {
        $stripe = $this->client();

        $parameters = [
            'amount' => Money::minorUnits($amount),
            'currency' => (string) config('services.stripe.currency'),
            'customer' => $card->customerId,
            'payment_method' => $card->paymentMethodId,
            'description' => $description,
            'confirm' => true,
            'off_session' => ! $onSession,

            /*
             * Cards only, stated explicitly rather than inherited.
             *
             * This gateway never charges anything but a card it is already
             * holding, so there is no redirect-based method to accommodate
             * and no `return_url` to supply. Naming the type also keeps
             * behaviour independent of which methods are enabled in the
             * Stripe dashboard.
             *
             * It has to be this rather than `automatic_payment_methods` with
             * redirects disabled, which is what this tried first: Stripe
             * refuses `error_on_requires_action` on an intent whose methods
             * are dashboard-managed, so the two cannot be combined. The
             * renewal test caught it — on-session charges worked throughout,
             * and only unattended renewals failed, which is a year after a
             * subscription is sold.
             */
            'payment_method_types' => ['card'],
        ];

        if ($onSession) {
            // Record the cardholder's agreement to be charged later, so the
            // renewal a year from now is not the first off-session use of
            // this card.
            $parameters['setup_future_usage'] = 'off_session';
        } else {
            // Nobody is there to complete 3-D Secure, so do not leave an
            // intent waiting for it.
            $parameters['error_on_requires_action'] = true;
        }

        try {
            $intent = $stripe->paymentIntents->create($parameters, [
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (CardException $e) {
            // A decline is an outcome, not a failure of the integration.
            return $this->declined($e);
        } catch (ApiErrorException $e) {
            Log::warning('Stripe charge failed before reaching a decision.', [
                'idempotency_key' => $idempotencyKey,
                'error' => $e->getMessage(),
            ]);

            return new PaymentResult(
                approved: false,
                reference: '',
                status: 'gateway_error',
                message: 'The payment could not be processed. Please try again.',
                declineCode: null,
                raw: ['error' => $e->getMessage()],
            );
        }

        $approved = $intent->status === 'succeeded';

        return new PaymentResult(
            approved: $approved,
            reference: (string) $intent->id,
            status: (string) $intent->status,
            message: $approved ? 'Approved' : 'The payment was not completed.',
            requiresAction: $intent->status === 'requires_action',
            raw: [
                'id' => $intent->id,
                'status' => $intent->status,
                'amount' => $intent->amount,
                'currency' => $intent->currency,
                'payment_method' => $intent->payment_method,
            ],
        );
    }

    /**
     * The Stripe customer for this account, found or created.
     *
     * Keyed by a metadata field rather than by email, because an email can
     * change and two CLAS accounts can share one. The search is on metadata
     * so a customer created by an earlier phase-1 subscription is reused
     * rather than duplicated.
     */
    private function customerFor(User $user): string
    {
        $stripe = $this->client();
        $reference = 'CLAS-USER-'.$user->id;

        $existing = $stripe->customers->search([
            'query' => sprintf("metadata['clas_user_id']:'%d'", $user->id),
            'limit' => 1,
        ]);

        if (count($existing->data) > 0) {
            return (string) $existing->data[0]->id;
        }

        $customer = $stripe->customers->create([
            'email' => $user->email,
            'description' => $reference,
            'metadata' => [
                // The same reference the Moneris vault used as its cust_id,
                // so the two sides can be reconciled during the phase 2
                // migration without a lookup table.
                'clas_user_id' => (string) $user->id,
                'clas_customer_id' => $reference,
            ],
        ], ['idempotency_key' => 'customer-'.$reference]);

        return (string) $customer->id;
    }

    private function declined(CardException $e): PaymentResult
    {
        $error = $e->getError();

        return new PaymentResult(
            approved: false,
            reference: (string) ($error->payment_intent->id ?? ''),
            status: 'declined',
            message: (string) ($error->message ?? 'The card was declined.'),
            declineCode: (string) ($error->decline_code ?? $error->code ?? 'card_declined'),
            raw: [
                'code' => $error->code ?? null,
                'decline_code' => $error->decline_code ?? null,
                'message' => $error->message ?? null,
            ],
        );
    }

    private function client(): StripeClient
    {
        if (! $this->isConfigured()) {
            throw new PaymentGatewayException('Stripe is not configured for this deployment.');
        }

        return $this->client ??= new StripeClient([
            'api_key' => (string) config('services.stripe.secret'),
            // Pinned, so a Stripe-side API change cannot alter behaviour
            // without a deliberate bump here.
            'stripe_version' => '2025-03-31.basil',
        ]);
    }
}
