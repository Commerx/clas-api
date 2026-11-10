<?php

namespace App\Services;

use App\Services\Payments\Money;

/**
 * What it costs to post somebody their signs.
 *
 * The whole point of this class is that **the price is ours, not the
 * caller's**. The old `update-shipping-cost` endpoint accepted a string like
 * `Fedex@@@Fedex Ground@@@24.50`, split it on `@@@`, and took the third part
 * as the shipping charge — which landed in `land.total_amount`, which is the
 * figure the payment endpoint then charged. Posting
 * `Fedex@@@Fedex Ground@@@0.00` got free shipping; a negative third part
 * reduced the order total. It also read index `[2]` without checking the
 * string had three parts, so anything without separators was a notice and a
 * silent zero.
 *
 * Here the caller names a method and the amount is looked up. The stored
 * `signage_shipping_method` keeps the old `carrier@@@service@@@amount`
 * shape so the web admin and the existing app keep working, but the string
 * is rebuilt from the figures below rather than echoed back — so a tampered
 * one simply does not match any option on offer.
 *
 * **Live carrier rates are not ported.** The Yii application quoted FedEx
 * through their Rate Service v28 SOAP API, which is FedEx's retired Web
 * Services interface, with credentials that are among the secrets waiting to
 * be rotated. `clas.signage.shipping.rates` is empty by default and nothing
 * makes a price up: a deployment with no rate card offers collection only.
 */
class SignageShipping
{
    /**
     * The shipping methods available for this order.
     *
     * Recomputed rather than remembered. A quote stored at the moment the
     * address was entered would have to be invalidated every time the sign
     * order changed, and a stale one is a price nobody agreed to; deriving
     * it from the order each time cannot drift.
     *
     * @param  array<string,mixed>  $address  as `signage_shipping_info` holds it
     * @return list<array<string,mixed>>
     */
    public function options(int $itemCount, array $address = []): array
    {
        $itemCount = max(1, $itemCount);
        $province = self::province((string) ($address['province'] ?? ''));

        $options = [];

        $pickup = (array) config('clas.signage.shipping.pickup');

        if ($pickup['enabled'] ?? false) {
            $options[] = $this->option(
                code: 'CUSTOMER_PICKUP',
                carrier: (string) $pickup['carrier'],
                service: (string) $pickup['service'],
                amount: (string) $pickup['amount'],
            );
        }

        foreach ((array) config('clas.signage.shipping.rates') as $rate) {
            $provinces = array_map('strtoupper', (array) ($rate['provinces'] ?? []));

            if ($provinces !== [] && ! in_array($province, $provinces, true)) {
                continue;
            }

            $cents = Money::minorUnits((string) ($rate['base'] ?? '0.00'))
                + Money::minorUnits((string) ($rate['per_item'] ?? '0.00')) * $itemCount;

            $options[] = $this->option(
                code: (string) $rate['code'],
                carrier: (string) ($rate['carrier'] ?? 'Fedex'),
                service: (string) ($rate['service'] ?? $rate['code']),
                amount: Money::fromMinorUnits($cents),
            );
        }

        return $options;
    }

    /**
     * Find the option a caller asked for, or null if we are not offering it.
     *
     * Accepts either the stable `code`, or the whole `@@@` string the
     * existing app posts back — but the string has to match one we would
     * have produced, character for character, which is what makes an edited
     * amount a miss rather than a discount.
     *
     * @param  array<string,mixed>  $address
     * @return array<string,mixed>|null
     */
    public function find(string $wanted, int $itemCount, array $address = []): ?array
    {
        $wanted = trim($wanted);

        if ($wanted === '') {
            return null;
        }

        foreach ($this->options($itemCount, $address) as $option) {
            if (strcasecmp($wanted, $option['code']) === 0 || $wanted === $option['key']) {
                return $option;
            }
        }

        return null;
    }

    /**
     * A destination province as a two-letter code.
     *
     * The address is free text — `update-shipping-info` has always stored
     * whatever the app sent — and the stored rows are mostly codes with the
     * occasional full name. An unmatched province falls through to the rate
     * card's province allowlists and quietly leaves the customer with
     * collection as their only option, so the full names are mapped rather
     * than left to chance.
     */
    public static function province(string $value): string
    {
        $value = strtoupper(trim($value));

        $names = [
            'ALBERTA' => 'AB',
            'BRITISH COLUMBIA' => 'BC',
            'MANITOBA' => 'MB',
            'NEW BRUNSWICK' => 'NB',
            'NEWFOUNDLAND AND LABRADOR' => 'NL',
            'NEWFOUNDLAND' => 'NL',
            'NORTHWEST TERRITORIES' => 'NT',
            'NOVA SCOTIA' => 'NS',
            'NUNAVUT' => 'NU',
            'ONTARIO' => 'ON',
            'PRINCE EDWARD ISLAND' => 'PE',
            'QUEBEC' => 'QC',
            'QUÉBEC' => 'QC',
            'SASKATCHEWAN' => 'SK',
            'YUKON' => 'YT',
            'YUKON TERRITORY' => 'YT',
        ];

        return $names[$value] ?? $value;
    }

    /** @return array<string,mixed> */
    private function option(string $code, string $carrier, string $service, string $amount): array
    {
        return [
            'code' => $code,
            'carrier' => $carrier,
            'service' => $service,
            'amount' => $amount,

            // The format the column has always held and the web admin
            // parses. Rebuilt here, never taken from the request.
            'key' => $carrier.'@@@'.$service.'@@@'.$amount,

            'label' => $service.': $'.$amount,
        ];
    }
}
