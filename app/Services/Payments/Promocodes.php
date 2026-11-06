<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;

/**
 * Resolving and redeeming a promotional code.
 *
 * The old implementation, in full:
 *
 *     if ($code == 'ABP20' || $code == 'LO20' || $code == 'WSG20') { 20% }
 *     else if ($promocode) { 100% }       // any row in promo_codes
 *     else { invalid }
 *
 * Two things that gives away, both visible in the live data:
 *
 * **`promo_codes.type` was ignored.** The column holds `onetime`,
 * `annual50` and `sales100`, 300 of each. Every one of them granted 100% off,
 * so the 300 `annual50` codes were giving a free year instead of half off.
 *
 * **`used` was never read.** It was incremented on redemption and never
 * checked, so every code was unlimited. One `onetime` code has been redeemed
 * 28 times, another 7, two more twice — 29 of the 903 codes have been used at
 * all, and four of those were reused.
 *
 * Both are fixed here, driven by `config/clas.php:promocodes`. Honouring the
 * type is a behaviour change for codes already in circulation, which is
 * recorded in that config and in the build order rather than left to be
 * discovered.
 */
class Promocodes
{
    /**
     * What a code is worth against an amount, or null if it is not valid.
     *
     * Nothing is written here: resolving a code is a read, so the
     * apply-promocode endpoint can quote a price without consuming anything.
     * `redeem()` is the separate, deliberate step.
     */
    public function resolve(string $code, string $amount): ?Discount
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        // The three literals the old controller special-cased. Matched
        // case-insensitively, since they are typed by hand off a flyer.
        foreach ((array) config('clas.promocodes.fixed') as $fixed => $rate) {
            if (strcasecmp($code, (string) $fixed) === 0) {
                return Discount::ofPercentage((string) $fixed, (int) $rate, $amount);
            }
        }

        $row = DB::table('promo_codes')->where('code', $code)->first();

        if ($row === null) {
            return null;
        }

        $rate = config('clas.promocodes.rates.'.$row->type);

        if ($rate === null) {
            // A type nobody has priced. Refusing is the safe direction: the
            // old code would have given it away entirely.
            return null;
        }

        if ($this->isSpent($row)) {
            return null;
        }

        return Discount::ofPercentage((string) $row->code, (int) $rate, $amount, (string) $row->type);
    }

    /**
     * Whether a code has already been used as many times as it may be.
     *
     * Only the types named in `single_use` are limited. The others keep the
     * old behaviour of being reusable, because nothing in the schema says
     * how many times they should work and inventing a limit would refuse
     * codes that have been handed out.
     */
    public function isSpent(object $row): bool
    {
        if (! in_array($row->type, (array) config('clas.promocodes.single_use'), true)) {
            return false;
        }

        return (int) $row->used > 0;
    }

    /**
     * Record that a code was used.
     *
     * `used` is incremented and `used_by` set, as before. `used_by` is a
     * single integer column, so a reusable code only ever records its most
     * recent redeemer — a schema limitation, left alone because changing it
     * would alter a table the old application also writes.
     */
    public function redeem(Discount $discount, int $userId): void
    {
        if (! $discount->wasApplied() || $discount->type === null) {
            // A fixed code is not a row, so there is nothing to mark.
            return;
        }

        DB::table('promo_codes')
            ->where('code', $discount->code)
            ->update([
                'used' => DB::raw('used + 1'),
                'used_by' => $userId,
            ]);
    }
}
