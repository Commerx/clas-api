<?php

namespace App\Services;

use App\Mail\SubscriptionStarted;
use App\Models\User;
use App\Services\Payments\Discount;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentResult;
use App\Services\Payments\Promocodes;
use App\Services\Payments\StoredCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Buying a property-access subscription.
 *
 * The orchestration the old `actionSubscribe` did inline over 200 lines: work
 * out the price, take the money, write the subscription, write the two free
 * add-ons, write the transaction rows, mark the promo code used, send the
 * confirmation.
 *
 * Three things are different, and all three are deliberate.
 *
 * **The card never arrives here.** The old endpoint took `cc_number`,
 * `cc_expiry` and `cc_cvv` as POST parameters and forwarded them to Moneris,
 * which put the whole application in PCI DSS SAQ D scope. This takes a
 * payment method id created on the device by Stripe's SDK.
 *
 * **Rows are written with `auto_renew = 0` and `gateway = 'stripe'`.** That
 * is the standing invariant that keeps the old Yii renewal cron off these
 * subscriptions — it filters `auto_renew = 1`, and would otherwise charge any
 * Moneris card the account still has on file, or email the customer a payment
 * decline. `docs/moneris-to-stripe-migration.md` has the reasoning.
 *
 * **The charge happens before the transaction, and a failure after it is
 * loud.** A card cannot be un-charged by a database rollback, so the money
 * moves first and the rows are written in a transaction afterwards. If that
 * write fails we have taken money without recording a subscription, which is
 * the one outcome nobody can be allowed to discover from a support ticket.
 */
class SubscriptionPurchase
{
    public function __construct(
        private PaymentGateway $gateway,
        private Promocodes $promocodes,
    ) {}

    /**
     * What a subscription of this type costs, with any code applied.
     *
     * A read: nothing is charged and no code is consumed, so the app can
     * quote a total before the customer commits.
     */
    public function quote(int $type, string $code = ''): Discount
    {
        $amount = $this->amountFor($type);

        return $this->promocodes->resolve($code, $amount) ?? Discount::none($amount);
    }

    /**
     * Buy a subscription.
     *
     * @param  string  $paymentMethodId  from the device's Stripe SDK; may be
     *                                   empty only when the total is zero
     * @return array{subscription_id: int, discount: Discount, payment: ?PaymentResult}
     *
     * @throws RuntimeException when the purchase cannot proceed
     */
    public function buy(User $user, int $type, string $code, string $paymentMethodId): array
    {
        if ($this->hasActiveSubscription($user)) {
            throw new RuntimeException('This account already has a CLAS subscription.');
        }

        $discount = $this->quote($type, $code);

        if ($code !== '' && ! $discount->wasApplied()) {
            throw new RuntimeException('That promo code is not valid.');
        }

        $card = null;
        $payment = null;

        if (! $discount->isFree()) {
            if ($paymentMethodId === '') {
                throw new RuntimeException('A payment method is required.');
            }

            $card = $this->gateway->rememberCard($user, $paymentMethodId);

            /*
             * Keyed on the card that will actually be charged.
             *
             * The point of the key is that retrying the same request does not
             * take the money twice, and a retry resends the same payment
             * method, so the attached id is stable across retries and the
             * second attempt returns the original charge.
             *
             * Keying on the day instead — which is what this did first —
             * looks equivalent and is not: Stripe rejects a key reused with
             * different parameters, and the parameters include the payment
             * method. A customer whose first attempt failed and who then
             * entered a new card got "keys for idempotent requests can only
             * be used with the same parameters" and could not subscribe
             * until tomorrow. The test suite caught it, which is the only
             * reason it is not in this file still.
             *
             * A double-tap that produces two payment methods does get two
             * keys, but the first purchase writes an Active subscription and
             * hasActiveSubscription() refuses the second.
             */
            $payment = $this->gateway->charge(
                card: $card,
                amount: $discount->total,
                description: sprintf('CLAS subscription (%s)', $this->nameFor($type)),
                idempotencyKey: sprintf(
                    'subscribe-%d-%d-%s', $user->id, $type, $card->paymentMethodId,
                ),
                onSession: true,
            );

            if (! $payment->approved) {
                throw new RuntimeException($payment->message);
            }
        }

        return $this->record($user, $type, $discount, $card, $payment);
    }

    /**
     * Write everything the purchase implies, in one transaction.
     *
     * @return array{subscription_id: int, discount: Discount, payment: ?PaymentResult}
     */
    private function record(
        User $user,
        int $type,
        Discount $discount,
        ?StoredCard $card,
        ?PaymentResult $payment,
    ): array {
        $reference = 'CLAS-USER-'.$user->id;
        $orderId = $payment?->reference
            ?: sprintf('CLAS-ORDER-%d-FREE-%d', $user->id, time());

        try {
            $subscriptionId = DB::transaction(function () use (
                $user, $type, $discount, $card, $payment, $reference, $orderId
            ) {
                $subscriptionId = (int) DB::table('user_subscription')->insertGetId([
                    'user_id' => $user->id,
                    'subscription_type' => $type,
                    'start_date' => now()->format('Y-m-d'),
                    'expiration_date' => $this->expiryFor($type),

                    // The invariant. See the class comment.
                    'auto_renew' => 0,
                    'gateway' => 'stripe',
                    'stripe_customer_id' => $card?->customerId,
                    'stripe_payment_method_id' => $card?->paymentMethodId,

                    'customer_id' => $reference,
                    'order_id' => $orderId,
                    'txn_number' => $payment?->reference,
                    'subscription_amount' => $discount->subscriptionAmount,
                    'promo_code' => $discount->wasApplied() ? $discount->code : null,
                    'discount_rate' => $discount->wasApplied() ? (string) $discount->rate : null,
                    'discount_amount' => $discount->wasApplied() ? $discount->discountAmount : null,
                    'amount_paid' => $discount->total,
                    'status' => 'Active',
                    'last_processed' => now()->format('Y-m-d'),
                ]);

                DB::table('subscription_transactions')->insert([
                    'subscription_id' => $subscriptionId,
                    'user_id' => $user->id,
                    'order_id' => $orderId,
                    'txn_number' => $payment?->reference ?? '',
                    'amount' => $discount->total,
                    'txn_status' => $payment === null ? 'FREE SUBSCRIPTION' : 'APPROVED',
                    'txn_response' => json_encode($payment?->raw ?? $discount->toArray()),
                ]);

                $this->addFreeAddons($user, $card, $orderId);

                if ($discount->wasApplied()) {
                    $this->promocodes->redeem($discount, (int) $user->id);
                }

                return $subscriptionId;
            });
        } catch (\Throwable $e) {
            if ($payment !== null) {
                // Money moved and the subscription did not get written. This
                // needs a person, now, which is why it is critical and
                // carries the payment reference to refund or reconcile with.
                Log::critical('Subscription charged but not recorded.', [
                    'user_id' => $user->id,
                    'payment_reference' => $payment->reference,
                    'amount' => $discount->total,
                    'error' => $e->getMessage(),
                ]);

                throw new RuntimeException(
                    'Your payment went through but the subscription could not be '
                    .'activated. Support has been notified; please do not pay again. '
                    .'Reference: '.$payment->reference,
                    0,
                    $e,
                );
            }

            throw $e;
        }

        $this->sendConfirmation($user, $type, $discount, $subscriptionId);

        return [
            'subscription_id' => $subscriptionId,
            'discount' => $discount,
            'payment' => $payment,
        ];
    }

    /**
     * The free year of WMU and fishing-lands access.
     *
     * Both paths in the old API created these alongside the main
     * subscription, at 0.00 with `txn_number` 'FREE ADD-ON'. Kept exactly,
     * including the marker, because the old application and the admin
     * screens read these tables.
     */
    private function addFreeAddons(User $user, ?StoredCard $card, string $orderId): void
    {
        foreach ((array) config('clas.subscriptions.free_addons') as $table => $addon) {
            $addonId = (int) DB::table($table)->insertGetId([
                'user_id' => $user->id,
                'start_date' => now()->format('Y-m-d'),
                'expiration_date' => now()->add(new \DateInterval($addon['period']))->format('Y-m-d'),
                'customer_id' => 'CLAS-USER-'.$user->id,
                'order_id' => $orderId,
                'txn_number' => 'FREE ADD-ON',
                'subscription_amount' => '0.00',
                'status' => 'Active',
                'last_processed' => now()->format('Y-m-d'),
                'gateway' => 'stripe',
                'stripe_customer_id' => $card?->customerId,
                'stripe_payment_method_id' => $card?->paymentMethodId,
            ]);

            DB::table(str_replace('_subscription', '_subscription_transactions', $table))->insert([
                'subscription_id' => $addonId,
                'order_id' => $orderId,
                'txn_number' => 'FREE ADD-ON',
                'amount' => '0.00',
                'txn_response' => null,
                'txn_status' => null,
            ]);
        }
    }

    private function sendConfirmation(User $user, int $type, Discount $discount, int $subscriptionId): void
    {
        try {
            Mail::to($user->email)->send(new SubscriptionStarted(
                customerName: $this->displayName($user),
                subscriptionName: $this->nameFor($type),
                amountPaid: $discount->total,
                discount: $discount->wasApplied() ? $discount : null,
                expiresOn: $this->expiryFor($type),
                subscriptionId: $subscriptionId,
            ));
        } catch (\Throwable $e) {
            // The subscription is real whether or not the receipt arrives.
            // The old code let a mail failure throw out of the action after
            // everything had been written, which is how a Swiftmailer
            // exception came to dump a card number into the log.
            Log::warning('Subscription confirmation email failed.', [
                'user_id' => $user->id,
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function hasActiveSubscription(User $user): bool
    {
        return DB::table('user_subscription')
            ->where('user_id', $user->id)
            ->where('status', 'Active')
            ->whereRaw('expiration_date >= CURDATE()')
            ->exists();
    }

    /** @return array<string,mixed> */
    private function type(int $type): array
    {
        $types = (array) config('clas.subscriptions.types');

        if (! isset($types[$type])) {
            throw new RuntimeException('That subscription type does not exist.');
        }

        return $types[$type];
    }

    private function amountFor(int $type): string
    {
        return (string) $this->type($type)['amount'];
    }

    private function nameFor(int $type): string
    {
        return (string) $this->type($type)['name'];
    }

    private function expiryFor(int $type): string
    {
        return now()->add(new \DateInterval($this->type($type)['period']))->format('Y-m-d');
    }

    private function displayName(User $user): string
    {
        $name = DB::table('profile')->where('user_id', $user->id)->value('name');

        return trim((string) $name) !== '' ? (string) $name : (string) $user->username;
    }
}
