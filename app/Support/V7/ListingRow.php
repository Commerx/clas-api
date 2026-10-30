<?php

namespace App\Support\V7;

use Illuminate\Support\Collection;

/**
 * Shapes a `land` row the way v7's listings endpoints did.
 *
 * Everything here exists to preserve observed v7 output, including several
 * things that are plainly bugs. They are preserved because two shipped mobile
 * apps parse this response and cannot be patched from the server — correcting
 * a field here breaks an installed app, whatever the field ought to contain.
 * Each quirk is marked QUIRK with what v7 does and why it does it.
 */
class ListingRow
{
    /** Columns a raw yii\db\Query returned as strings, so they stay strings. */
    private const STRING_COLUMNS = [
        'land_id', 'owner', 'is_rid_land', 'is_first_nations',
        'is_open_property', 'fireban_status_id', 'kml_file_exists',
    ];

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    public static function format(array $row, Collection $keyFeatures, bool $withGeometry): array
    {
        $row = self::stringifyNumerics($row);

        if (array_key_exists('key_features', $row)) {
            $row['features'] = self::features((string) ($row['key_features'] ?? ''), $keyFeatures);
        }

        if (! $withGeometry) {
            return $row;
        }

        // QUIRK: a loose != '' comparison, so both NULL and '' skip processing
        // and the column is emitted unchanged. That is why an empty string
        // renders as "" while a NULL renders as null — the apps see both.
        if (array_key_exists('kml', $row)) {
            $row['kml_data'] = ($row['kml'] ?? '') != '';
        }

        if (array_key_exists('area', $row) && ($row['area'] ?? '') != '') {
            $row['area'] = self::decodeArea($row['area']);
        }

        if (array_key_exists('points', $row) && ($row['points'] ?? '') != '') {
            $row['points'] = self::decodePoints($row['points']);
        }

        return $row;
    }

    /**
     * QUIRK: v7 built `$new_area` from the decoded value and then threw it
     * away, assigning the decoded value itself. So `area` is the whole array.
     * Harmless by accident, and relied upon.
     */
    private static function decodeArea(mixed $raw): mixed
    {
        $decoded = json_decode((string) $raw);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return $decoded;
    }

    /**
     * QUIRK: v7 wrote `foreach ($points as $points)`, so the loop variable
     * shadowed the collection. After the loop `$points` held the LAST element,
     * and that single object is what was assigned — not the array. A listing
     * with 19 boundary points returns one object. The apps are built on this.
     *
     * An empty decoded array leaves the variable untouched, so `[]` stays `[]`.
     *
     * When the column holds the string 'null', json_decode returns NULL with
     * no error. v7's `foreach (null)` used to raise a warning that Yii
     * escalated into an exception, so any geofence page of 13 or more rows
     * returned a 500 — land_id 6418 is row 13 and is in that state. The Yii
     * app now guards the loop and emits the decoded value, which is what this
     * returns too, so the two agree across the whole set.
     */
    private static function decodePoints(mixed $raw): mixed
    {
        $decoded = json_decode((string) $raw);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        if (! is_iterable($decoded)) {
            return $decoded; // see DIVERGENCE above
        }

        $last = $decoded;

        foreach ($decoded as $element) {
            $last = $element;
        }

        return $last;
    }

    /**
     * Literal translation of v7's formatFeaturesList.
     *
     * QUIRK: it indexes the selected features by the part before the dash,
     * then tests `array_key_exists($index, ...)` using the *position* of each
     * key feature in the loaded list rather than its id. Position and id only
     * coincide by chance, so this returns far fewer features than it appears
     * to intend. Translated as written: the response must not change.
     */
    private static function features(string $selectedList, Collection $keyFeatures): array
    {
        $selected = [];

        foreach (explode(',', $selectedList) as $feature) {
            if (! str_contains($feature, '-')) {
                continue;
            }

            [$key, $parent] = explode('-', $feature, 2);

            $selected[$key] = $parent;

            if (str_contains($parent, 'other')) {
                $selected[$parent] = $key;
            }
        }

        $out = [];

        foreach ($keyFeatures->values() as $position => $ignored) {
            if (array_key_exists($position, $selected) && $selected[$position] == 0) {
                $out[] = $position;
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $row */
    private static function stringifyNumerics(array $row): array
    {
        foreach (self::STRING_COLUMNS as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = (string) $row[$column];
            }
        }

        return $row;
    }
}
