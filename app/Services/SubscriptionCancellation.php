<?php

namespace App\Services;

use App\Mail\SubscriptionCancelled;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Stopping a subscription from renewing, and starting it again.
 *
 * Neither application had this. There was no endpoint, no screen and no
 * setting, so a subscriber's only way to stop renewing was to let their card
 * fail — and `auto_renew = 0` was written only by the free-promocode path.
 * The renewal reminder is what forced it: a mail that says "we will charge
 * your card on the 17th" has to be able to answer "then don't".
 *
 * **Cancelling does not revoke access.** The subscription stays Active until
 * its `expiration_date`, which is the period already paid for, and then the
 * ordinary expiry sweep ends it. Nothing here touches the free add-ons
 * either; they expire with the subscription, as they always have.
 *
 * **The gateway decides what has to change.** `cancelled_at` is the intent,
 * and it is the same for both. What actually stops the money differs:
 *
 * - A **Moneris** row is charged by the Yii cron, which selects
 *   `auto_renew = 1` and knows nothing about `cancelled_at`. So cancelling
 *   one also writes `auto_renew = 0` — the only signal that application
 *   reads.
 * - A **Stripe** row already carries `auto_renew = 0`; that is the invariant
 *   keeping the old cron away from it. This API's scheduler is what charges
 *   it, and that reads `cancelled_at`.
 *
 * Which is also why resuming is not symmetrical: restoring `auto_renew = 1`
 * on a Stripe row would hand it to the old cron, and the customer would be
 * charged by both applications. It is restored only for Moneris rows.
 */
class SubscriptionCancellation
{
    /**
     * Stop a subscription renewing.
     *
     * @return object the subscription as it now stands
     *
     * @throws RuntimeException when there is nothing to cancel
     */
    public function cancel(User $user, string $reason = ''): object
    {
        $subscription = $this->activeSubscription($user);

        if ($subscription === null) {
            throw new RuntimeException('This account has no active subscription.');
        }

        if ($subscription->cancelled_at !== null) {
            throw new RuntimeException('This subscription is already cancelled.');
        }

        $attributes = [
            'cancelled_at' => now(),
            'cancellation_reason' => trim($reason) !== '' ? trim($reason) : null,
        ];

        if (! $this->isOurs($subscription)) {
            // The one signal the Yii cron reads. Without this the old
            // application would charge them again regardless of what
            // cancelled_at says.
            $attributes['auto_renew'] = 0;
        }

        DB::table('user_subscription')
            ->where('subscription_id', $subscription->subscription_id)
            ->update($attributes);

        $fresh = $this->find((int) $subscription->subscription_id);

        $this->mail($user, new SubscriptionCancelled(
            subscriptionName: $this->nameFor($subscription),
            accessUntil: substr((string) $subscription->expiration_date, 0, 10),
        ));

        return $fresh;
    }

    /**
     * Put a cancelled subscription back on renewal.
     *
     * Only while it is still running: once it has expired there is nothing
     * to resume, and buying again is a purchase, not a change of mind.
     *
     * @throws RuntimeException when there is nothing to resume
     */
    public function resume(User $user): object
    {
        $subscription = $this->activeSubscription($user);

        if ($subscription === null) {
            throw new RuntimeException('This account has no active subscription.');
        }

        if ($subscription->cancelled_at === null) {
            throw new RuntimeException('This subscription is not cancelled.');
        }

        $attributes = ['cancelled_at' => null, 'cancellation_reason' => null];

        if (! $this->isOurs($subscription)) {
            // Hand it back to the Yii cron, which is what charges it.
            $attributes['auto_renew'] = 1;
        }

        /*
         * Deliberately *not* restoring auto_renew on a Stripe row.
         *
         * It is 0 there by design, so the old cron ignores it. Setting it to
         * 1 as part of "undo the cancellation" would look symmetrical and
         * would hand the row to an application that still has the
         * customer's Moneris card on file — they would be charged twice,
         * once by each processor, a year later.
         */
        DB::table('user_subscription')
            ->where('subscription_id', $subscription->subscription_id)
            ->update($attributes);

        return $this->find((int) $subscription->subscription_id);
    }

    /**
     * What the app should show about the caller's subscription.
     *
     * @return array<string,mixed>|null
     */
    public function status(User $user): ?array
    {
        $subscription = $this->activeSubscription($user);

        if ($subscription === null) {
            return null;
        }

        return [
            'subscription_id' => (int) $subscription->subscription_id,
            'subscription_type' => (int) $subscription->subscription_type,
            'name' => $this->nameFor($subscription),
            'status' => (string) $subscription->status,
            'expiration_date' => substr((string) $subscription->expiration_date, 0, 10),
            'cancelled' => $subscription->cancelled_at !== null,
            'cancelled_at' => $subscription->cancelled_at,

            /*
             * Whether a charge is actually coming, which is not the same as
             * "not cancelled": a legacy plan type and a row the old cron
             * does not select will both simply run out. The app should say
             * "access ends on" rather than "renews on" for those.
             */
            'renews' => $this->willRenew($subscription),
        ];
    }

    /** The caller's current subscription, cancelled or not. */
    private function activeSubscription(User $user): ?object
    {
        return DB::table('user_subscription')
            ->where('user_id', $user->id)
            ->where('status', 'Active')
            ->orderByDesc('subscription_id')
            ->first();
    }

    private function find(int $subscriptionId): object
    {
        return DB::table('user_subscription')
            ->where('subscription_id', $subscriptionId)
            ->first();
    }

    /** Whether this API owns the row, or the Yii application does. */
    private function isOurs(object $subscription): bool
    {
        return $subscription->gateway === 'stripe';
    }

    private function willRenew(object $subscription): bool
    {
        if ($subscription->cancelled_at !== null) {
            return false;
        }

        if ($this->isOurs($subscription)) {
            return $subscription->stripe_payment_method_id !== null;
        }

        return (int) $subscription->auto_renew === 1
            && (bool) config(
                'clas.subscriptions.types.'.$subscription->subscription_type.'.renews',
                false,
            );
    }

    private function nameFor(object $subscription): string
    {
        return (string) config(
            'clas.subscriptions.types.'.$subscription->subscription_type.'.name',
            'Annual',
        );
    }

    /** Confirmation mail that cannot take the cancellation down with it. */
    private function mail(User $user, SubscriptionCancelled $mailable): void
    {
        try {
            Mail::to($user->email)->send($mailable);
        } catch (\Throwable $e) {
            Log::warning('Cancellation confirmation failed to send.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
