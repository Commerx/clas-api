<?php

namespace App\Services;

use App\Mail\RenewalFailed;
use App\Mail\SubscriptionExpired;
use App\Mail\SubscriptionRenewed;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\StoredCard;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Renewing the subscriptions this API owns.
 *
 * Scoped to `gateway = 'stripe'` throughout. A row with NULL or 'moneris'
 * belongs to the old Yii cron and is never touched here; a row this API
 * created is invisible to that cron because it carries `auto_renew = 0`.
 * That pair is the whole of the coexistence arrangement, and
 * docs/moneris-to-stripe-migration.md says what happens without it.
 *
 * Two differences from the old renewal cron, both deliberate.
 *
 * **A missed run costs a day, not the renewal.** The old
 * `canProcessPaymentToday()` returned true on exactly two dates — the
 * expiration date, and seven days after it — and false on every other day.
 * A cron that did not run on one of those two dates never charged that
 * subscription at all, and the expiry sweep then expired it a week later with
 * nothing recording the difference between "declined" and "never attempted".
 * Here an attempt is made on any day in the grace window where one has not
 * already been made, up to the same cap of two.
 *
 * **Attempts are counted from what was recorded, not from the calendar.** The
 * attempt number comes from the transaction rows already written for this
 * renewal period, so running twice in a day cannot produce a third attempt
 * and a gap in the schedule cannot skip one.
 */
class SubscriptionRenewals
{
    public function __construct(
        private PaymentGateway $gateway,
    ) {}

    /**
     * Charge everything due, then expire what has run out of grace.
     *
     * @return array{attempted: int, renewed: int, failed: int, expired: int, skipped: int}
     */
    public function run(bool $dryRun = false): array
    {
        $tally = ['attempted' => 0, 'renewed' => 0, 'failed' => 0, 'expired' => 0, 'skipped' => 0];

        if (! $this->gateway->isConfigured()) {
            Log::warning('Subscription renewals skipped: no usable Stripe key.');

            return $tally;
        }

        foreach ($this->due() as $subscription) {
            $attempt = $this->attemptsSoFar($subscription) + 1;

            if ($attempt > (int) config('clas.subscriptions.renewal.max_attempts')) {
                $tally['skipped']++;

                continue;
            }

            if ($subscription->last_processed === now()->format('Y-m-d')) {
                // Already looked at today. Guards a second run of the
                // command on the same day.
                $tally['skipped']++;

                continue;
            }

            $tally['attempted']++;

            if ($dryRun) {
                continue;
            }

            $this->attempt($subscription, $attempt)
                ? $tally['renewed']++
                : $tally['failed']++;
        }

        $tally['expired'] = $this->expireOutOfGrace($dryRun);

        return $tally;
    }

    /**
     * Subscriptions this API owns whose term has run out.
     *
     * No `auto_renew` filter, because every row this API creates carries
     * `auto_renew = 0` by design — that column means "not renewed by the old
     * cron" here, not "not renewing". There is no way for a subscriber to
     * turn off renewal in either application today, so a Stripe subscription
     * past its expiry is one to charge. Worth knowing when a cancel path is
     * eventually built: it needs its own signal, not this column.
     *
     * @return list<object>
     */
    public function due(): array
    {
        $grace = (int) config('clas.subscriptions.renewal.grace_days');

        return DB::table('user_subscription as us')
            ->join('user as u', 'u.id', '=', 'us.user_id')
            ->where('us.gateway', 'stripe')
            ->where('us.status', 'Active')
            // Asked not to renew. `auto_renew` cannot carry this for a
            // Stripe row — it is 0 there by design — so cancellation has its
            // own column. See App\Services\SubscriptionCancellation.
            ->whereNull('us.cancelled_at')
            ->whereRaw('us.expiration_date <= CURDATE()')
            ->whereRaw('us.expiration_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)', [$grace])
            ->whereNull('u.blocked_at')
            ->whereNotNull('us.stripe_payment_method_id')
            ->get([
                'us.subscription_id', 'us.user_id', 'us.subscription_type',
                'us.expiration_date', 'us.subscription_amount', 'us.last_processed',
                'us.stripe_customer_id', 'us.stripe_payment_method_id',
                'u.email',
            ])
            ->all();
    }

    /**
     * One charge, and everything that follows from its outcome.
     *
     * Returns whether the subscription was renewed.
     */
    private function attempt(object $subscription, int $attempt): bool
    {
        $type = (array) config('clas.subscriptions.types.'.$subscription->subscription_type);
        $amount = (string) ($type['amount'] ?? $subscription->subscription_amount);

        $card = new StoredCard(
            customerId: (string) $subscription->stripe_customer_id,
            paymentMethodId: (string) $subscription->stripe_payment_method_id,
        );

        /*
         * The attempt number is part of the key.
         *
         * Stripe replays the original response for a reused key, including a
         * failure — so the second attempt must have a key of its own or it
         * would return the first attempt's decline without contacting the
         * card. The period end is in there too, so next year's renewal is a
         * different charge from this one.
         */
        $result = $this->gateway->charge(
            card: $card,
            amount: $amount,
            description: sprintf('CLAS subscription renewal (%s)', $type['name'] ?? 'Annual'),
            idempotencyKey: sprintf(
                'renew-%d-%s-%d',
                $subscription->subscription_id,
                $subscription->expiration_date,
                $attempt,
            ),
            onSession: false,
        );

        DB::table('subscription_transactions')->insert([
            'subscription_id' => $subscription->subscription_id,
            'user_id' => $subscription->user_id,
            'order_id' => sprintf(
                'CLAS-RENW-%d-USER-%d-%s',
                $subscription->subscription_id, $subscription->user_id,
                $subscription->expiration_date,
            ),
            'txn_number' => $result->reference,
            'amount' => $amount,
            'txn_status' => $result->approved ? 'APPROVED' : strtoupper($result->status),
            'txn_response' => json_encode($result->raw + ['attempt' => $attempt]),
        ]);

        if ($result->approved) {
            $period = $type['period'] ?? 'P1Y';

            DB::table('user_subscription')
                ->where('subscription_id', $subscription->subscription_id)
                ->update([
                    'expiration_date' => now()->add(new \DateInterval($period))->format('Y-m-d'),
                    'amount_paid' => $amount,
                    'txn_number' => $result->reference,
                    'last_processed' => now()->format('Y-m-d'),
                ]);

            $this->mail($subscription, new SubscriptionRenewed(
                subscriptionName: (string) ($type['name'] ?? 'Annual'),
                amount: $amount,
                expiresOn: now()->add(new \DateInterval($period))->format('Y-m-d'),
            ));

            return true;
        }

        DB::table('user_subscription')
            ->where('subscription_id', $subscription->subscription_id)
            ->update(['last_processed' => now()->format('Y-m-d')]);

        Log::info('Subscription renewal declined.', [
            'subscription_id' => $subscription->subscription_id,
            'attempt' => $attempt,
            'status' => $result->status,
            'decline_code' => $result->declineCode,
            'worth_retrying' => $result->isWorthRetrying(),
        ]);

        $this->mail($subscription, new RenewalFailed(
            subscriptionName: (string) ($type['name'] ?? 'Annual'),
            amount: $amount,
            reason: $result->message,
            attempt: $attempt,
            finalAttempt: $attempt >= (int) config('clas.subscriptions.renewal.max_attempts')
                || ! $result->isWorthRetrying(),
        ));

        return false;
    }

    /**
     * How many attempts have already been recorded for this renewal period.
     *
     * Read from the transaction rows rather than kept in a counter column,
     * so it cannot drift from what actually happened, and so a second run of
     * the command on the same day sees the attempt the first one made.
     */
    private function attemptsSoFar(object $subscription): int
    {
        return DB::table('subscription_transactions')
            ->where('subscription_id', $subscription->subscription_id)
            ->where('order_id', sprintf(
                'CLAS-RENW-%d-USER-%d-%s',
                $subscription->subscription_id, $subscription->user_id,
                $subscription->expiration_date,
            ))
            ->count();
    }

    /**
     * Expire what has run out of grace, and the add-ons with it.
     *
     * Scoped to this API's rows. The old cron's sweep does not filter on
     * gateway, so during the overlap it may expire one of these too — which
     * is harmless, because it computes the same outcome at the same boundary.
     * Doing it here as well is what makes this correct once that cron is
     * gone.
     */
    private function expireOutOfGrace(bool $dryRun): int
    {
        $grace = (int) config('clas.subscriptions.renewal.grace_days');

        $expired = DB::table('user_subscription as us')
            ->join('user as u', 'u.id', '=', 'us.user_id')
            ->where('us.gateway', 'stripe')
            ->where('us.status', 'Active')
            ->whereRaw('us.expiration_date < DATE_SUB(CURDATE(), INTERVAL ? DAY)', [$grace])
            ->get(['us.subscription_id', 'us.user_id', 'us.subscription_type', 'u.email'])
            ->all();

        if ($dryRun) {
            return count($expired);
        }

        foreach ($expired as $subscription) {
            DB::transaction(function () use ($subscription) {
                DB::table('user_subscription')
                    ->where('subscription_id', $subscription->subscription_id)
                    ->update([
                        'status' => 'Expired',
                        'last_processed' => now()->format('Y-m-d'),
                    ]);

                // The free add-ons go with it, as the old sweep does.
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

            $this->mail($subscription, new SubscriptionExpired(
                subscriptionName: (string) config(
                    'clas.subscriptions.types.'.$subscription->subscription_type.'.name',
                    'Annual',
                ),
            ));
        }

        return count($expired);
    }

    /** Mail that cannot take the renewal down with it. */
    private function mail(object $subscription, Mailable $mailable): void
    {
        try {
            Mail::to($subscription->email)->send($mailable);
        } catch (\Throwable $e) {
            Log::warning('Renewal email failed.', [
                'subscription_id' => $subscription->subscription_id,
                'mailable' => $mailable::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
