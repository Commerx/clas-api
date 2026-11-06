<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * What Stripe tells us after the fact.
 *
 * The old integration read the synchronous response to its own request and
 * nothing else, which means the database never learned about anything that
 * happened later — a refund issued from the processor's dashboard, a
 * chargeback, a payment that settled after the request returned. Support
 * refunding a customer in the dashboard left the subscription marked Active
 * and paid, and nobody found out until the next renewal or an audit.
 *
 * Three properties matter more than the event coverage:
 *
 * **It fails closed.** With no `STRIPE_WEBHOOK_SECRET` configured, nothing is
 * processed at all. A webhook endpoint is unauthenticated by definition — the
 * signature *is* the authentication — so an endpoint that accepts unsigned
 * payloads is an open door to anyone who can guess the URL, and "we have not
 * set the secret yet" is exactly when that door would be open.
 *
 * **It is idempotent.** Stripe retries until it gets a 2xx and may redeliver
 * an event after a success. Every event id is recorded, and a redelivery is
 * acknowledged without being acted on a second time.
 *
 * **It keeps the payload.** When a payment goes wrong, Stripe's own account
 * of what happened is the authoritative record, and it cannot be
 * reconstructed afterwards.
 */
class StripeWebhooks
{
    /**
     * @return array{status: int, outcome: string}
     */
    public function handle(string $payload, string $signature): array
    {
        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '') {
            // 503 rather than 400: Stripe retries a 5xx, so events that
            // arrive before the secret is configured are not lost — they are
            // redelivered once it is. Refusing is the only safe answer,
            // because without the secret we cannot tell Stripe from anyone
            // else who found the URL.
            Log::warning('Stripe webhook refused: no STRIPE_WEBHOOK_SECRET configured.');

            return ['status' => 503, 'outcome' => 'no_signing_secret'];
        }

        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException $e) {
            // 400, not 503: a bad signature will not become good on a retry.
            Log::warning('Stripe webhook rejected: signature did not verify.', [
                'error' => $e->getMessage(),
            ]);

            return ['status' => 400, 'outcome' => 'bad_signature'];
        } catch (\UnexpectedValueException $e) {
            return ['status' => 400, 'outcome' => 'unparseable'];
        }

        $existing = DB::connection('clas_api')->table('stripe_events')
            ->where('id', $event->id)->first();

        if ($existing !== null && $existing->handled_at !== null) {
            // Already dealt with. Acknowledge so Stripe stops retrying.
            return ['status' => 200, 'outcome' => 'duplicate'];
        }

        if ($existing === null) {
            DB::connection('clas_api')->table('stripe_events')->insert([
                'id' => $event->id,
                'type' => $event->type,
                'payload' => json_encode($event->toArray()),
                'received_at' => now(),
            ]);
        }

        $outcome = $this->dispatch($event);

        DB::connection('clas_api')->table('stripe_events')
            ->where('id', $event->id)
            ->update(['handled_at' => now(), 'outcome' => $outcome]);

        return ['status' => 200, 'outcome' => $outcome];
    }

    private function dispatch(Event $event): string
    {
        return match ($event->type) {
            'charge.refunded' => $this->refunded($event),
            'charge.dispute.created' => $this->disputed($event),
            'payment_intent.payment_failed' => $this->paymentFailed($event),
            'payment_intent.succeeded' => 'noted',

            // Everything else is recorded and acknowledged. Returning 200
            // for an event we do not act on is deliberate: a 4xx would have
            // Stripe retry it forever and eventually disable the endpoint.
            default => 'ignored',
        };
    }

    /**
     * A refund, which usually means somebody pressed refund in the
     * dashboard.
     *
     * A full refund revokes the subscription, because the customer has their
     * money back and should not keep the access. A partial refund is
     * recorded and left alone: it could be a goodwill gesture, a correction,
     * or a proration, and guessing which would be worse than not guessing.
     */
    private function refunded(Event $event): string
    {
        $charge = $event->data->object;
        $intent = (string) ($charge->payment_intent ?? '');

        if ($intent === '') {
            return 'refund_without_payment_intent';
        }

        $subscription = DB::table('user_subscription')
            ->where('txn_number', $intent)
            ->where('gateway', 'stripe')
            ->first(['subscription_id', 'user_id', 'status']);

        if ($subscription === null) {
            Log::warning('Stripe refund for a charge with no matching subscription.', [
                'payment_intent' => $intent,
            ]);

            return 'refund_unmatched';
        }

        $fullyRefunded = (bool) ($charge->refunded ?? false);

        if (! $fullyRefunded) {
            Log::info('Partial Stripe refund recorded; subscription left as it is.', [
                'subscription_id' => $subscription->subscription_id,
                'amount_refunded' => $charge->amount_refunded ?? null,
            ]);

            return 'partial_refund_noted';
        }

        DB::transaction(function () use ($subscription) {
            DB::table('user_subscription')
                ->where('subscription_id', $subscription->subscription_id)
                ->update([
                    'status' => 'Expired',
                    'expiration_date' => now()->format('Y-m-d'),
                    'last_processed' => now()->format('Y-m-d'),
                ]);

            foreach (array_keys((array) config('clas.subscriptions.free_addons')) as $table) {
                DB::table($table)
                    ->where('user_id', $subscription->user_id)
                    ->where('status', 'Active')
                    ->update([
                        'status' => 'Expired',
                        'expiration_date' => now()->format('Y-m-d'),
                        'last_processed' => now()->format('Y-m-d'),
                    ]);
            }
        });

        Log::info('Subscription revoked after a full refund.', [
            'subscription_id' => $subscription->subscription_id,
            'payment_intent' => $intent,
        ]);

        return 'subscription_revoked';
    }

    /**
     * A chargeback.
     *
     * Deliberately does not revoke anything. A dispute can be won, the
     * deadline to respond is short, and revoking access automatically would
     * both prejudge it and remove the evidence of use that is often what
     * wins it. This needs a person, which is what `critical` is for.
     */
    private function disputed(Event $event): string
    {
        $dispute = $event->data->object;

        Log::critical('Stripe dispute opened.', [
            'dispute_id' => $dispute->id ?? null,
            'charge' => $dispute->charge ?? null,
            'amount' => $dispute->amount ?? null,
            'reason' => $dispute->reason ?? null,
            'due_by' => $dispute->evidence_details->due_by ?? null,
        ]);

        return 'dispute_logged';
    }

    /**
     * A charge that failed.
     *
     * Usually already known: this API confirms its intents synchronously and
     * records the outcome there and then. Logged rather than acted on, so
     * that an intent which fails later — or one created outside this
     * application — is not silent.
     */
    private function paymentFailed(Event $event): string
    {
        $intent = $event->data->object;

        Log::info('Stripe reported a failed payment intent.', [
            'payment_intent' => $intent->id ?? null,
            'customer' => $intent->customer ?? null,
            'decline_code' => $intent->last_payment_error->decline_code ?? null,
        ]);

        return 'failure_noted';
    }
}
