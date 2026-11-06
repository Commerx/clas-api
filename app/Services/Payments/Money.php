<?php

namespace App\Services\Payments;

use InvalidArgumentException;

/**
 * Amounts, as Stripe wants them.
 *
 * Prices in this application are decimal strings — '9.99', '53.94' — because
 * that is what they are in `config/clas.php` and what the `decimal(8,2)`
 * columns hold. Stripe charges integer minor units, so 9.99 is 999 cents.
 *
 * The conversion is done on the string rather than through a float, because
 * `(int) ($amount * 100)` is short by a cent for some amounts and exact for
 * others, which is the worst way for a bug like this to behave. Measured on
 * PHP 8.5:
 *
 *   '9.99'  * 100 = 999.0                 -> 999   correct
 *   '19.99' * 100 = 1998.9999999999998    -> 1998  a cent short
 *   '69.99' * 100 = 6998.999999999999     -> 6998  a cent short
 *
 * 69.99 is not hypothetical: it is the annual price in the Yii base
 * `config/params.php`. So the same code would have charged correctly for the
 * current 9.99 and under-charged by a cent had that price been in use —
 * failing only on some of the catalogue, and only after a release that
 * changed a price.
 *
 * Rounding would paper over it. Not going through a float removes the
 * question.
 */
class Money
{
    /** Cents for a decimal amount string. */
    public static function minorUnits(string $amount): int
    {
        $amount = trim($amount);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $match)) {
            throw new InvalidArgumentException(
                "'{$amount}' is not an amount with at most two decimal places."
            );
        }

        [, $sign, $whole, $fraction] = $match + [3 => ''];

        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return $sign === '-' ? -$cents : $cents;
    }

    /** The decimal string for a number of cents, for storing in the row. */
    public static function fromMinorUnits(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }
}
