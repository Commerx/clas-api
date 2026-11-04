<?php

namespace App\Support\V1;

use Illuminate\Support\Facades\DB;

/**
 * The caller's reported position: one place to validate it, one place to
 * store it, one place that decides whether a stored position means anything.
 *
 * Listings and Requests both take user_lat/user_lng alongside their real
 * work and write them to the profile; the old API did this at five sites,
 * each with its own copy of the validation, and they had drifted.
 *
 * The reason this class also exists to *reject* positions: profile.user_lat
 * for 5,610 of 5,611 accounts holds 49.90261265,-114.12588656 — the web map's
 * default centre, which is also the Waldron listing. It was not written by
 * clients one at a time. Ten developer accounts that genuinely sat near
 * Lahore in December 2023, and received the only proximity alerts this
 * feature has ever delivered, now hold that same pair, so a single statement
 * overwrote the whole table after that date. The consequence in both
 * applications today: 88 active listings lie within 25 km of that point, so
 * a community-awareness alert on any of them reaches every account; the other
 * 16,451 reach nobody. Treating that exact pair as "no position" is what
 * stops one owner's post becoming a push to the entire user base.
 *
 * The match is a tolerance rather than equality, because a client that
 * rounds the pair on its way out still means the same non-position: a
 * request carrying 49.902613 is the default centre too. Fifty metres is wide
 * enough for any such rounding and narrow enough that the only real reading
 * it swallows is one taken while standing on the Waldron listing, which is a
 * cost this accepts knowingly.
 */
class Position
{
    /** A number within range, or null for anything that is not a position. */
    public static function coordinate(mixed $value, float $max): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        // Zero is how an unset coordinate is stored in this schema, whose
        // columns default to 0 rather than null, not a point off West Africa.
        if ($value === 0.0 || $value < -$max || $value > $max) {
            return null;
        }

        return $value;
    }

    /**
     * How close to a placeholder still counts as one: about fifty metres at
     * these latitudes.
     */
    public const TOLERANCE = 0.0005;

    /**
     * A pair a map put there rather than a device: see the class comment.
     */
    public static function isPlaceholder(?float $lat, ?float $lng): bool
    {
        if ($lat === null || $lng === null) {
            return false;
        }

        foreach (config('clas.position.placeholders', []) as [$placeholderLat, $placeholderLng]) {
            if (abs($lat - $placeholderLat) <= self::TOLERANCE
                && abs($lng - $placeholderLng) <= self::TOLERANCE) {
                return true;
            }
        }

        return false;
    }

    /**
     * Narrow a query to the profiles whose position is a real one.
     *
     * The same rule as isPlaceholder(), expressed where the rows are, so the
     * fan-out does not have to read every profile to apply it.
     */
    public static function excludePlaceholders($query, string $latColumn = 'user_lat', string $lngColumn = 'user_lng')
    {
        foreach (config('clas.position.placeholders', []) as [$placeholderLat, $placeholderLng]) {
            $query->whereNot(function ($row) use ($latColumn, $lngColumn, $placeholderLat, $placeholderLng) {
                $row->whereBetween($latColumn, [$placeholderLat - self::TOLERANCE, $placeholderLat + self::TOLERANCE])
                    ->whereBetween($lngColumn, [$placeholderLng - self::TOLERANCE, $placeholderLng + self::TOLERANCE]);
            });
        }

        return $query;
    }

    /** A stored position worth matching on, or null. */
    public static function usable(mixed $lat, mixed $lng): ?array
    {
        $lat = self::coordinate($lat, 90);
        $lng = self::coordinate($lng, 180);

        if ($lat === null || $lng === null || self::isPlaceholder($lat, $lng)) {
            return null;
        }

        return [$lat, $lng];
    }

    /**
     * Store the caller's position, if they sent one worth storing.
     *
     * A placeholder is dropped rather than written, so the condition the
     * class comment describes stops growing through this API whatever is
     * done about the rows already there.
     */
    public static function remember(int $userId, mixed $lat, mixed $lng): void
    {
        $position = self::usable($lat, $lng);

        if ($position === null) {
            return;
        }

        DB::table('profile')
            ->where('user_id', $userId)
            ->update(['user_lat' => $position[0], 'user_lng' => $position[1]]);
    }
}
