<?php

namespace App\Services;

use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\StoredCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The card a subscriber has on file — and the migration that comes with
 * changing it.
 *
 * Changing a card is the trigger that moves a subscriber from Moneris to
 * Stripe, which is a better trigger than a renewal for one reason: the
 * people it moves are already in the app, deliberately touching their
 * payment details. Nobody lapses for not having noticed a prompt, which is
 * the cost of migrating at renewal time.
 *
 * It also means no card export from Moneris, no mapping file to verify, and
 * no card data through a CLAS server — the payment method is created on the
 * device by Stripe's SDK and arrives here as an opaque id.
 *
 * What a switch does:
 *
 * - attaches the new card to the account's Stripe customer;
 * - sets `gateway = 'stripe'` and `auto_renew = 0` on the subscription, in
 *   one statement, which is what hands it from the Yii cron to this
 *   application's scheduler;
 * - leaves `data_key` exactly as it is, so the Moneris token stays valid and
 *   the row can be handed back if anything goes wrong.
 *
 * Only `user_subscription` moves. The three add-on tables carry the same
 * columns and are updated for consistency, but nothing ever renews them in
 * either application — the Yii cron only charges `user_subscription`, and it
 * only ever *expires* the add-ons. A year of each comes free with a
 * subscription and then lapses unless bought again.
 */
class CardOnFile
{
    public function __construct(
        private PaymentGateway $gateway,
    ) {}

    /**
     * Replace the card on file, moving the account to Stripe if it is not
     * there already.
     *
     * @return array{card: StoredCard, moved: bool, subscriptions: int}
     *
     * @throws RuntimeException
     */
    public function replace(User $user, string $paymentMethodId): array
    {
        if ($paymentMethodId === '') {
            throw new RuntimeException('A payment method is required.');
        }

        $card = $this->gateway->rememberCard($user, $paymentMethodId);

        $subscription = DB::table('user_subscription')
            ->where('user_id', $user->id)
            ->where('status', 'Active')
            ->whereRaw('expiration_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)', [
                (int) config('clas.subscriptions.renewal.grace_days'),
            ])
            ->orderByDesc('subscription_id')
            ->first(['subscription_id', 'gateway', 'data_key', 'expiration_date', 'last_processed']);

        if ($subscription === null) {
            // A card with nothing to charge it for. Worth saving anyway: the
            // next purchase will use it, and refusing would be surprising.
            return ['card' => $card, 'moved' => false, 'subscriptions' => 0];
        }

        $wasOnMoneris = $subscription->gateway !== 'stripe';

        $updated = DB::transaction(function () use ($user, $card, $subscription) {
            $count = DB::table('user_subscription')
                ->where('subscription_id', $subscription->subscription_id)
                ->update([
                    /*
                     * Both of these, in one statement.
                     *
                     * `auto_renew = 0` is what makes the row invisible to the
                     * old Yii cron, and `gateway = 'stripe'` is what makes it
                     * visible to this application's scheduler. Setting them
                     * together means there is no instant in which both
                     * schedulers own the row, or neither does.
                     *
                     * `data_key` is deliberately untouched: the Moneris token
                     * stays valid, so handing the row back is setting two
                     * columns.
                     */
                    'auto_renew' => 0,
                    'gateway' => 'stripe',

                    'stripe_customer_id' => $card->customerId,
                    'stripe_payment_method_id' => $card->paymentMethodId,
                ]);

            // The add-ons do not renew, but they carry the same columns and
            // leaving them inconsistent would make the reporting lie.
            foreach (array_keys((array) config('clas.subscriptions.free_addons')) as $table) {
                DB::table($table)
                    ->where('user_id', $user->id)
                    ->where('status', 'Active')
                    ->update([
                        'gateway' => 'stripe',
                        'stripe_customer_id' => $card->customerId,
                        'stripe_payment_method_id' => $card->paymentMethodId,
                    ]);
            }

            return $count;
        });

        if ($wasOnMoneris) {
            /*
             * Worth a log line per migration, because this is how the book
             * moves across and the rate is the only measure of it.
             *
             * Note what deliberately does not happen here: the row is not
             * stamped `last_processed`. If the subscription is inside its
             * grace window — which is the commonest reason somebody changes
             * their card — the scheduler will attempt it on its next run,
             * which is what the customer wants. It skips a row the Yii cron
             * already attempted today, so the handover cannot produce two
             * charges on one day.
             *
             * The attempt budget does reset, because this application counts
             * attempts by its own order id format and cannot see Moneris's.
             * So a card changed mid-grace gets up to two fresh attempts. For
             * someone who has just supplied a working card that is the right
             * outcome.
             */
            Log::info('Subscription moved from Moneris to Stripe by a card change.', [
                'subscription_id' => $subscription->subscription_id,
                'user_id' => $user->id,
                'moneris_data_key_retained' => $subscription->data_key !== null,
                'in_grace_window' => $subscription->expiration_date <= now()->format('Y-m-d'),
            ]);
        }

        return [
            'card' => $card,
            'moved' => $wasOnMoneris,
            'subscriptions' => $updated,
        ];
    }

    /**
     * What is on file now.
     *
     * `gateway` is here for support and reporting — "which processor is this
     * customer on" is the first question when a charge is queried — not for
     * the app to show. The move is silent by decision (2026-11-06): from the
     * customer's side nothing changes, same price, same renewal date, same
     * card on file, and the statement descriptor is 'CANADIAN LAND ACCESS'
     * on the Stripe account as well, so a migrated charge is recognisable.
     * A descriptor the customer did not recognise would have been the one
     * good reason to tell them, and it checked out.
     */
    public function current(User $user): ?array
    {
        $row = DB::table('user_subscription')
            ->where('user_id', $user->id)
            ->where('status', 'Active')
            ->orderByDesc('subscription_id')
            ->first(['gateway', 'stripe_payment_method_id', 'data_key']);

        if ($row === null) {
            return null;
        }

        return [
            // Which processor will charge the next renewal.
            'gateway' => $row->gateway ?? 'moneris',
            'has_card' => $row->stripe_payment_method_id !== null || $row->data_key !== null,
        ];
    }
}
