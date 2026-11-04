<?php

namespace App\Support\V1;

use Illuminate\Support\Facades\DB;

/**
 * Wildlife management units: how a list of them arrives, and how it resolves.
 *
 * Two endpoints take management units from a client — a hunting alert and a
 * hunter's tag — and the old API resolved them differently. The alert looked
 * them up by province and number. The tag looked them up by number alone:
 *
 *     $wmu_details = WMU::findOne(['wmu_number' => $wmu]);
 *
 * `wmu_number` is not unique. 1,599 rows carry 1,145 distinct numbers; "3"
 * exists in Manitoba, New Brunswick, Nova Scotia, Ontario, Saskatchewan and
 * Yukon. So a Saskatchewan tag for unit 3 could be filed against Manitoba's
 * unit 3, which is a different place — and 3 of the zone rows in the table
 * are in exactly that state today. The hunter is then alerted about the wrong
 * province and missed for their own.
 *
 * Worse, when the number did not resolve the old API inserted a new row into
 * `wmus` with nothing but the number, from a client request. 58 rows have no
 * province, with numbers like "00539" and "69A-2": reference data written by
 * whoever typed into the app. This class does not create rows. An unknown
 * unit is a validation failure, because a tag filed against a unit that does
 * not exist cannot match an alert either way.
 */
class Wmu
{
    /**
     * The numbers a client sent, as a list of trimmed strings.
     *
     * Accepts an array or a comma-separated string: the app has sent both.
     *
     * @return list<string>
     */
    public static function numbers(mixed $wmus): array
    {
        if (is_string($wmus)) {
            $wmus = explode(',', $wmus);
        }

        if (! is_array($wmus)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($wmu) => trim((string) $wmu), $wmus),
            fn ($wmu) => $wmu !== '',
        )));
    }

    /**
     * The ids of the units with those numbers in that province.
     *
     * A number that does not exist there is simply absent from the result, so
     * a caller comparing counts can tell whether everything resolved.
     *
     * @param  list<string>  $numbers
     * @return list<int>
     */
    public static function idsFor(string $province, array $numbers): array
    {
        if ($numbers === []) {
            return [];
        }

        return DB::table('wmus')
            ->where('province', $province)
            ->whereIn('wmu_number', $numbers)
            ->pluck('wmu_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The numbers of the units a tag is filed against.
     *
     * @return list<string>
     */
    public static function numbersForTag(int $tagId): array
    {
        return DB::table('wmus')
            ->join('hunters_match_tags_wmu_zones as zones', 'zones.wmu_id', '=', 'wmus.wmu_id')
            ->where('zones.tag_id', $tagId)
            ->orderBy('wmus.wmu_number')
            ->pluck('wmus.wmu_number')
            ->map(fn ($number) => (string) $number)
            ->all();
    }
}
