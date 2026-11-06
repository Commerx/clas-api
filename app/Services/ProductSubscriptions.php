<?php

namespace App\Services;

use App\Mail\SubscriptionStarted;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Buying one of the three add-on products on its own.
 *
 * WMU, dark mode and fishing lands were three controllers in the old API of
 * 355, 346 and 346 lines, differing in the table they wrote, the price key
 * they read and the wording of one error message. Everything else — the card
 * handling, the Moneris calls, the duplicated date arithmetic — was copied
 * between them three times. Here they are one code path over
 * `config/clas.php:subscriptions.products`.
 *
 * Two behaviours from the old controllers are deliberately not carried over.
 *
 * **The WMU endpoint used to write the caller's phone number into their
 * profile**, splitting it on dashes, and refused the entire purchase when it
 * did not split into three parts. So a subscription could not be bought at
 * all unless the profile's phone number happened to be in `xxx-xxx-xxxx`
 * form. Updating a profile is `user/update-profile`'s job; buying a
 * subscription is this one's.
 *
 * **`wmu_subscription.wmu_province` is still not set.** The old endpoint
 * never set it either — 817 rows are NULL and 2 are empty, while the 35 with
 * a province came from the web application — and the price list has
 * per-province keys the API never read, always charging the `annual` rate.
 * Rather than invent a meaning for the column, it stays as the old endpoint
 * left it. Worth deciding properly if per-province pricing is ever wanted.
 */
class ProductSubscriptions
{
    public function __construct(
        private PaymentGateway $gateway,
    ) {}

    /**
     * Buy a product subscription.
     *
     * @return array{subscription_id: int, amount: string, reference: string}
     *
     * @throws RuntimeException when it cannot proceed
     */
    public function buy(User $user, string $product, string $paymentMethodId): array
    {
        $spec = $this->spec($product);

        if ($this->alreadyHas($user, $spec['table'])) {
            throw new RuntimeException(
                sprintf('This account already has a %s subscription.', $spec['name'])
            );
        }

        if ($paymentMethodId === '') {
            throw new RuntimeException('A payment method is required.');
        }

        $card = $this->gateway->rememberCard($user, $paymentMethodId);

        // Keyed on the card, for the reason given in SubscriptionPurchase:
        // a retry resends the same payment method, and Stripe rejects a key
        // reused with different parameters.
        $payment = $this->gateway->charge(
            card: $card,
            amount: $spec['amount'],
            description: sprintf('CLAS %s subscription', $spec['name']),
            idempotencyKey: sprintf('product-%s-%d-%s', $product, $user->id, $card->paymentMethodId),
            onSession: true,
        );

        if (! $payment->approved) {
            throw new RuntimeException($payment->message);
        }

        $expiry = now()->add(new \DateInterval($spec['period']))->format('Y-m-d');

        try {
            $subscriptionId = DB::transaction(function () use ($user, $spec, $card, $payment, $expiry) {
                $id = (int) DB::table($spec['table'])->insertGetId([
                    'user_id' => $user->id,
                    'start_date' => now()->format('Y-m-d'),
                    'expiration_date' => $expiry,
                    'customer_id' => 'CLAS-USER-'.$user->id,
                    'order_id' => $payment->reference,
                    'txn_number' => $payment->reference,
                    'subscription_amount' => $spec['amount'],
                    'status' => 'Active',
                    'last_processed' => now()->format('Y-m-d'),

                    // The same invariant the main subscription carries: the
                    // old Yii cron must never find these.
                    'gateway' => 'stripe',
                    'stripe_customer_id' => $card->customerId,
                    'stripe_payment_method_id' => $card->paymentMethodId,
                ]);

                DB::table($spec['transactions'])->insert([
                    'subscription_id' => $id,
                    'order_id' => $payment->reference,
                    'txn_number' => $payment->reference,
                    'amount' => $spec['amount'],
                    'txn_status' => 'APPROVED',
                    'txn_response' => json_encode($payment->raw),
                ]);

                return $id;
            });
        } catch (\Throwable $e) {
            Log::critical('Product subscription charged but not recorded.', [
                'user_id' => $user->id,
                'product' => $product,
                'payment_reference' => $payment->reference,
                'amount' => $spec['amount'],
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

        $this->sendConfirmation($user, $spec, $expiry, $subscriptionId);

        return [
            'subscription_id' => $subscriptionId,
            'amount' => $spec['amount'],
            'reference' => $payment->reference,
        ];
    }

    /** What a product costs, for the app to show before committing. */
    public function price(string $product): string
    {
        return (string) $this->spec($product)['amount'];
    }

    /** @return array<string,mixed> */
    private function spec(string $product): array
    {
        $spec = config('clas.subscriptions.products.'.$product);

        if (! is_array($spec)) {
            throw new RuntimeException('That subscription product does not exist.');
        }

        return $spec;
    }

    /**
     * Whether the account already holds one.
     *
     * Unexpired as well as Active, which is what the old `getWMUSubscription`
     * and its two copies checked.
     */
    private function alreadyHas(User $user, string $table): bool
    {
        return DB::table($table)
            ->where('user_id', $user->id)
            ->where('status', 'Active')
            ->whereRaw('expiration_date >= CURDATE()')
            ->exists();
    }

    /** @param  array<string,mixed>  $spec */
    private function sendConfirmation(User $user, array $spec, string $expiry, int $id): void
    {
        try {
            Mail::to($user->email)->send(new SubscriptionStarted(
                customerName: $this->displayName($user),
                subscriptionName: (string) $spec['name'],
                amountPaid: (string) $spec['amount'],
                discount: null,
                expiresOn: $expiry,
                subscriptionId: $id,
            ));
        } catch (\Throwable $e) {
            Log::warning('Product subscription confirmation email failed.', [
                'user_id' => $user->id,
                'subscription_id' => $id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function displayName(User $user): string
    {
        $name = DB::table('profile')->where('user_id', $user->id)->value('name');

        return trim((string) $name) !== '' ? (string) $name : (string) $user->username;
    }
}
