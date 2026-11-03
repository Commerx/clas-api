<?php

namespace App\Support\V1;

use Illuminate\Support\Collection;

/**
 * Shapes a `land` row for the API.
 *
 * This used to reproduce the old API's output exactly, bugs included, because
 * the shipped apps could not be patched. That constraint is gone: the new API
 * is served from its own subdomain and consumed by a new build, while the old
 * domain keeps serving old builds unchanged. So the bugs are fixed here, and
 * each fix is noted with what the old behaviour was, because the app developer
 * has to know what changed.
 *
 * WAS: numeric columns arrived as strings from raw queries ("56", "0") but as
 *      integers from ActiveRecord endpoints, in the same API.
 * NOW: native types throughout.
 *
 * WAS: `points` returned a single object — the last element — because the old
 *      code wrote `foreach ($points as $points)` and the loop variable
 *      shadowed the collection. A listing with 19 boundary points returned one.
 * NOW: the full array.
 *
 * WAS: `features` compared each key feature's *position* in the loaded list
 *      against ids taken from the listing, so it returned almost nothing.
 * NOW: matches on the feature id, so it returns the listing's actual features.
 *
 * WAS: an empty column rendered as "" and a NULL as null, from a loose
 *      `!= ''` guard.
 * NOW: both render as an empty array for the geometry fields.
 */
class ListingRow
{
    private const INT_COLUMNS = [
        'land_id', 'owner', 'fireban_status_id', 'area_size',
    ];

    private const BOOL_COLUMNS = [
        'is_rid_land', 'is_first_nations', 'is_open_property', 'kml_file_exists',
    ];

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    public static function format(array $row, Collection $keyFeatures, bool $withGeometry): array
    {
        $row = self::castScalars($row);

        if (array_key_exists('key_features', $row)) {
            $row['features'] = self::features((string) ($row['key_features'] ?? ''), $keyFeatures);
        }

        if (array_key_exists('photos', $row)) {
            // GROUP_CONCAT gives one comma-joined string, or null for none.
            $row['photos'] = self::photos($row['photos']);
        }

        if (! $withGeometry) {
            return $row;
        }

        if (array_key_exists('kml', $row)) {
            $row['kml_data'] = ($row['kml'] ?? '') !== '';
        }

        foreach (['area', 'points'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = self::geometry($row[$column]);
            }
        }

        return $row;
    }

    /**
     * Both columns hold a JSON array of {key, value} objects, where value is a
     * pipe-joined list of "lat,lng" pairs. A handful of rows hold the string
     * 'null' or raw pipe-delimited text instead; those yield an empty array
     * rather than an error. The old code raised a 500 on the first of those,
     * which took out any geofence page of 13 rows or more.
     */
    public static function geometry(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            fn ($entry) => is_array($entry) && array_key_exists('value', $entry)
        ));
    }

    /**
     * The listing stores its features as "featureId-parentId" pairs in a
     * comma separated column: "1-0,9-1,-other1,2-0,10-2". A parent of 0 is a
     * top-level feature; anything else is a child of that feature. Entries
     * with no id ("-other1") are free-text placeholders and carry no feature.
     *
     * The old code intended to return only the top-level selections and in
     * practice returned almost nothing, so there is no shape worth matching.
     * Every selected feature is returned here, each carrying its parent_id, so
     * the client can show the flat list or rebuild the tree as it prefers —
     * nothing is dropped on its behalf.
     */
    private static function features(string $selectedList, Collection $keyFeatures): array
    {
        if (trim($selectedList) === '') {
            return [];
        }

        $selected = [];

        foreach (explode(',', $selectedList) as $entry) {
            if (! str_contains($entry, '-')) {
                continue;
            }

            [$id] = explode('-', $entry, 2);
            $id = trim($id);

            if ($id === '' || ! ctype_digit($id)) {
                continue;
            }

            $selected[(int) $id] = true;
        }

        if ($selected === []) {
            return [];
        }

        return $keyFeatures
            ->filter(fn ($feature) => isset($selected[(int) $feature->feature_id]))
            ->map(fn ($feature) => [
                'id' => (int) $feature->feature_id,
                'name' => $feature->feature,
                'parent_id' => (int) $feature->parent_id,
            ])
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $row */
    private static function castScalars(array $row): array
    {
        foreach (self::INT_COLUMNS as $column) {
            if (isset($row[$column])) {
                $row[$column] = (int) $row[$column];
            }
        }

        foreach (self::BOOL_COLUMNS as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = (bool) (int) $row[$column];
            }
        }

        return $row;
    }

    /** GROUP_CONCAT gives one comma-joined string, or null for none. */
    public static function photos(mixed $raw): array
    {
        return $raw === null || $raw === ''
            ? []
            : explode(',', (string) $raw);
    }
}
