<?php

namespace App\Support\V1;

/**
 * Shapes a `requests` row for the API.
 *
 * These rows come from raw joins, so every column arrived as a string in the
 * old API — `request_id` as "23347", `is_user_read` as "1". Native types here,
 * for the same reason as everywhere else: the client should not have to guess
 * whether a field is a number this week.
 *
 * Geometry and photos are shared with ListingRow, because these queries select
 * the same land columns and must shape them the same way.
 */
class RequestRow
{
    private const INT_COLUMNS = [
        'request_id', 'requester_id', 'land_id', 'requested_at',
        'people_count', 'units_count', 'owner', 'fireban_status_id',
        'decision_at', 'user_id',
    ];

    private const BOOL_COLUMNS = [
        'is_user_read', 'is_owner_read', 'is_rid_land', 'is_first_nations',
        'is_open_property', 'is_guide_request', 'is_supersede_request',
        'mute_user',
    ];

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    public static function format(array $row): array
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

        if (array_key_exists('photos', $row)) {
            $row['photos'] = ListingRow::photos($row['photos']);
        }

        foreach (['area', 'points'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = ListingRow::geometry($row[$column]);
            }
        }

        return $row;
    }

    /**
     * @param  iterable<array<string,mixed>|object>  $rows
     * @return list<array<string,mixed>>
     */
    public static function formatAll(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = self::format((array) $row);
        }

        return $out;
    }
}
