<?php

namespace App\Services;

use App\Mail\SignageOrderPlaced;
use App\Models\User;
use App\Services\Payments\Discount;
use App\Services\Payments\Money;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentResult;
use App\Services\Payments\Promocodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Ordering and paying for the physical signs a listing needs.
 *
 * Four endpoints in the old controller — add-signages, promocode,
 * update-shipping-cost and update-payment — each repeating the same access
 * check and each writing the order totals in its own way. The arithmetic is
 * in one place here, because the four of them disagreeing is how a listing
 * ends up charged for something other than what it ordered.
 *
 * Five things are different from the old implementation, all deliberate:
 *
 * **The card never arrives here.** `update-payment` took `cc_number`,
 * `cc_exp_month`, `cc_exp_year` and `cc_cvv` as POST parameters and handed
 * them to Moneris. This takes a Stripe payment method id created on the
 * device, like the subscription endpoints.
 *
 * **Shipping is priced by us.** The old endpoint took the price out of a
 * client-supplied string. App\Services\SignageShipping has the detail.
 *
 * **A promo code is honoured for what it is worth.** The old ladder gave
 * 100% off for *any* row in `promo_codes` of type `onetime`, ignoring the
 * code's own type — the same defect found in the subscription path, where
 * 300 `annual50` codes were granting full discounts.
 *
 * **A code is marked used.** The signage path never incremented `used` at
 * all, so a single-use code worked on every listing for ever.
 *
 * **An order cannot be paid twice.** Nothing stopped `update-payment` being
 * called again on a listing already marked Completed; it simply charged the
 * card a second time and overwrote the receipt.
 */
class SignageOrder
{
    public function __construct(
        private PaymentGateway $gateway,
        private Promocodes $promocodes,
        private SignageShipping $shipping,
    ) {}

    /**
     * Add signs to the order, or more of ones already on it.
     *
     * @param  array<int|string,int|string>  $options  option id => quantity
     *
     * @throws RuntimeException
     */
    public function addSignages(int $landId, int $userId, array $options): void
    {
        $catalogue = $this->catalogue();
        $max = (int) config('clas.signage.max_qty_per_option');

        $wanted = [];

        foreach ($options as $id => $qty) {
            $id = (int) $id;
            $qty = (int) $qty;

            if (! isset($catalogue[$id])) {
                throw new RuntimeException("There is no sign with id {$id}.");
            }

            /*
             * The old loop tested `if ($option && $qty && ...)`, which is
             * truthiness, not validation: a negative quantity passed it and
             * was added to the stored qty, reducing the order — and could
             * take `signage_amount` below zero while `required_signages`
             * went with it.
             */
            if ($qty < 1 || $qty > $max) {
                throw new RuntimeException(
                    "Quantity for {$catalogue[$id]['name']} must be between 1 and {$max}."
                );
            }

            $wanted[$id] = $qty;
        }

        if ($wanted === []) {
            throw new RuntimeException('Please select at least one sign.');
        }

        DB::transaction(function () use ($landId, $userId, $wanted, $catalogue, $max) {
            foreach ($wanted as $id => $qty) {
                $existing = DB::table('land_signages')
                    ->where('land_id', $landId)
                    ->where('signage_type', $id)
                    ->first();

                // The price is the catalogue's, always — never a figure that
                // came in with the request, and refreshed on an existing
                // line so a stale row cannot hold yesterday's price.
                $price = $catalogue[$id]['price'];

                if ($existing !== null) {
                    $total = min((int) $existing->qty + $qty, $max);

                    DB::table('land_signages')
                        ->where('id', $existing->id)
                        ->update(['user_id' => $userId, 'qty' => $total, 'price' => $price]);

                    continue;
                }

                DB::table('land_signages')->insert([
                    'land_id' => $landId,
                    'user_id' => $userId,
                    'signage_type' => $id,
                    'qty' => $qty,
                    'price' => $price,
                    'created_at' => now(),
                ]);
            }

            $this->restate($landId);
        });
    }

    /**
     * Price a code against this order and store the discount.
     *
     * Nothing is consumed here — `redeem()` happens when the order is paid —
     * so an owner can try a code, change the order and try again without
     * burning it.
     *
     * @throws RuntimeException
     */
    public function applyPromocode(int $landId, string $code): Discount
    {
        $land = $this->land($landId);
        $amount = (string) ($land->signage_amount ?? '0.00');

        if (Money::minorUnits($amount) === 0) {
            throw new RuntimeException('Please select signage first.');
        }

        $discount = $this->promocodes->resolve($code, $amount, context: 'signage');

        if ($discount === null) {
            throw new RuntimeException('That promo code is not valid.');
        }

        DB::table('land')->where('land_id', $landId)->update([
            'signage_promocode' => $discount->code,
            'signage_discount_amount' => $discount->discountAmount,

            // A changed price invalidates the chosen shipping, exactly as
            // the old endpoint did: the total has to be rebuilt.
            'signage_shipping_method' => '',
            'signage_shipping_amount' => 0.00,
            'total_amount' => 0.00,
            'updated_at' => time(),
        ]);

        return $discount;
    }

    /**
     * Choose how the signs are shipped, and settle the order total.
     *
     * @throws RuntimeException
     */
    public function chooseShipping(int $landId, string $method): array
    {
        $land = $this->land($landId);

        if ((int) ($land->required_signages ?? 0) < 1) {
            throw new RuntimeException('Please select signage first.');
        }

        $option = $this->shipping->find(
            $method,
            $this->itemCount($landId),
            $this->address($land),
        );

        if ($option === null) {
            // Either an option we do not offer, or an edited `@@@` string
            // whose amount no longer matches ours. Both are the same answer.
            throw new RuntimeException('That shipping option is not available.');
        }

        $total = $this->totalFor($land, $option['amount']);

        DB::table('land')->where('land_id', $landId)->update([
            'signage_shipping_method' => $option['key'],
            'signage_shipping_amount' => $option['amount'],
            'total_amount' => $total,
            'updated_at' => time(),
        ]);

        return $option;
    }

    /**
     * Pay for the order.
     *
     * @return array{payment: ?PaymentResult, amount: string, order_id: string}
     *
     * @throws RuntimeException
     */
    public function pay(User $user, int $landId, string $paymentMethodId): array
    {
        $land = $this->land($landId);

        if ((string) $land->payment_status === 'Completed') {
            throw new RuntimeException('This listing has already been paid for.');
        }

        if ((int) ($land->required_signages ?? 0) < 1) {
            throw new RuntimeException('Please select signage first.');
        }

        if ((string) ($land->signage_shipping_method ?? '') === '') {
            throw new RuntimeException('Please choose a shipping option first.');
        }

        /*
         * Priced here, not read out of `total_amount`.
         *
         * The column is written by the three endpoints above, and the amount
         * that gets charged should not depend on all of them having been
         * called in the right order. Recomputing it from the sign lines, the
         * stored discount and the stored shipping method means the charge is
         * for what the order actually is.
         */
        $amount = $this->chargeableTotal($land, $landId);
        $orderId = sprintf('CLAS-LISTING-%d-%d', $landId, time());

        $card = null;
        $payment = null;

        if (Money::minorUnits($amount) > 0) {
            if ($paymentMethodId === '') {
                throw new RuntimeException('A payment method is required.');
            }

            $card = $this->gateway->rememberCard($user, $paymentMethodId);

            // Keyed on the listing and the attached card, for the same
            // reason the subscription key is: a retry resends the same
            // payment method and must return the first charge, while a
            // customer who enters a different card after a decline needs a
            // key of their own. See SubscriptionPurchase.
            $payment = $this->gateway->charge(
                card: $card,
                amount: $amount,
                description: sprintf('CLAS listing signage (land %d)', $landId),
                idempotencyKey: sprintf('signage-%d-%s', $landId, $card->paymentMethodId),
                onSession: true,
            );

            if (! $payment->approved) {
                throw new RuntimeException($payment->message);
            }
        }

        $this->record($user, $land, $landId, $amount, $orderId, $payment);

        return ['payment' => $payment, 'amount' => $amount, 'order_id' => $orderId];
    }

    /**
     * Write the paid order, and tell the people who make the signs.
     *
     * The charge has already happened by this point, so a failure here is
     * money taken against a listing still marked Pending — the one outcome
     * that has to reach a person rather than a log line nobody reads.
     */
    private function record(
        User $user,
        object $land,
        int $landId,
        string $amount,
        string $orderId,
        ?PaymentResult $payment,
    ): void {
        try {
            DB::transaction(function () use ($user, $land, $landId, $amount, $orderId, $payment) {
                DB::table('land')->where('land_id', $landId)->update([
                    'customer_id' => 'CLAS-USER-'.$user->id,
                    'order_id' => $payment?->reference ?: $orderId,
                    'txn_number' => $payment?->reference,
                    'paid_amount' => $amount,
                    'total_amount' => $amount,
                    'payment_status' => 'Completed',
                    'current_step' => 1,
                    'updated_at' => time(),
                ]);

                $code = (string) ($land->signage_promocode ?? '');

                if ($code !== '') {
                    /*
                     * Marked used here, which the old signage path never did
                     * at all — it stored the code against the listing and
                     * left `used` alone, so a single-use code worked on
                     * every listing for ever.
                     */
                    $this->promocodes->redeem(
                        $this->promocodes->resolve(
                            $code,
                            (string) ($land->signage_amount ?? '0.00'),
                            context: 'signage',
                        ) ?? Discount::none('0.00'),
                        (int) $user->id,
                    );
                }
            });
        } catch (\Throwable $e) {
            if ($payment !== null) {
                Log::critical('Signage order charged but not recorded.', [
                    'land_id' => $landId,
                    'user_id' => $user->id,
                    'payment_reference' => $payment->reference,
                    'amount' => $amount,
                    'error' => $e->getMessage(),
                ]);

                throw new RuntimeException(
                    'Your payment went through but the order could not be saved. '
                    .'Support has been notified; please do not pay again. '
                    .'Reference: '.$payment->reference,
                    0,
                    $e,
                );
            }

            throw $e;
        }

        $this->notify($user, $landId, $amount, $payment);
    }

    /**
     * Tell whoever makes and posts the signs that an order exists.
     *
     * The old endpoint rendered the listing to a PDF with a Code 39 barcode
     * and mailed that to the admin address. The PDF is not ported — it
     * needed the Yii view layer and a barcode library — so this sends the
     * order itself: the listing, the lines, the address and what was paid,
     * which is what fulfilment actually reads off it. Flagged in the build
     * order, because somebody downstream may be relying on the attachment.
     */
    private function notify(User $user, int $landId, string $amount, ?PaymentResult $payment): void
    {
        $recipient = (string) config('clas.admin_email');

        if ($recipient === '') {
            return;
        }

        try {
            Mail::to($recipient)->send(new SignageOrderPlaced(
                landId: $landId,
                landName: (string) ($this->land($landId)->land_name ?? ''),
                orderedBy: trim($user->email),
                amount: $amount,
                reference: $payment?->reference ?? 'FREE ORDER',
                lines: $this->lines($landId),
                shippingTo: $this->address($this->land($landId)),
            ));
        } catch (\Throwable $e) {
            // The customer has paid; failing their request because we could
            // not email ourselves would be the wrong way round.
            Log::warning('Signage order notification failed.', [
                'land_id' => $landId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ---- the arithmetic, in one place ----------------------------------

    /** Recompute the sign lines onto the listing, and clear what they invalidate. */
    public function restate(int $landId): void
    {
        $lines = DB::table('land_signages')->where('land_id', $landId)->get(['qty', 'price']);

        $cents = 0;
        $qty = 0;

        foreach ($lines as $line) {
            $cents += Money::minorUnits((string) $line->price) * (int) $line->qty;
            $qty += (int) $line->qty;
        }

        DB::table('land')->where('land_id', $landId)->update([
            'required_signages' => $qty,
            'signage_amount' => Money::fromMinorUnits($cents),

            // Changing what is being bought invalidates any discount and any
            // shipping quote, as the old endpoint also did.
            'signage_promocode' => '',
            'signage_discount_amount' => 0.00,
            'signage_shipping_method' => '',
            'signage_shipping_amount' => 0.00,
            'total_amount' => 0.00,
            'updated_at' => time(),
        ]);
    }

    /** Signs, less any discount, plus shipping. */
    private function totalFor(object $land, string $shippingAmount): string
    {
        $signage = Money::minorUnits((string) ($land->signage_amount ?? '0.00'));
        $discount = Money::minorUnits((string) ($land->signage_discount_amount ?? '0.00'));

        // The old guard, kept: a discount larger than the order is ignored
        // rather than turned into a negative total.
        $net = $discount <= $signage ? $signage - $discount : $signage;

        return Money::fromMinorUnits($net + Money::minorUnits($shippingAmount));
    }

    /** What to charge, derived from the order rather than read off the row. */
    private function chargeableTotal(object $land, int $landId): string
    {
        $option = $this->shipping->find(
            (string) $land->signage_shipping_method,
            $this->itemCount($landId),
            $this->address($land),
        );

        if ($option === null) {
            throw new RuntimeException(
                'The shipping option on this order is no longer available. '
                .'Please choose shipping again.'
            );
        }

        return $this->totalFor($land, $option['amount']);
    }

    // ---- reading -------------------------------------------------------

    /** @return array<int,array{name: string, price: string}> */
    private function catalogue(): array
    {
        $catalogue = [];

        foreach ((array) config('clas.signage_options') as $option) {
            $catalogue[(int) $option['id']] = [
                'name' => (string) $option['name'],
                'price' => number_format((float) $option['price'], 2, '.', ''),
            ];
        }

        return $catalogue;
    }

    private function land(int $landId): object
    {
        $land = DB::table('land')->where('land_id', $landId)->first();

        if ($land === null) {
            throw new RuntimeException('No such listing.');
        }

        return $land;
    }

    public function itemCount(int $landId): int
    {
        return (int) DB::table('land_signages')->where('land_id', $landId)->sum('qty');
    }

    /** @return list<array<string,mixed>> */
    private function lines(int $landId): array
    {
        $catalogue = $this->catalogue();

        return DB::table('land_signages')
            ->where('land_id', $landId)
            ->orderBy('id')
            ->get(['signage_type', 'qty', 'price'])
            ->map(fn ($line) => [
                'name' => $catalogue[(int) $line->signage_type]['name'] ?? 'Sign',
                'qty' => (int) $line->qty,
                'price' => (string) $line->price,
            ])
            ->all();
    }

    /** @return array<string,mixed> */
    public function address(object $land): array
    {
        $decoded = json_decode((string) ($land->signage_shipping_info ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }
}
