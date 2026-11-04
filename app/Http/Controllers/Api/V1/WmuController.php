<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Wildlife management units, the games in them, and the seasons for each.
 *
 * All reference data: nothing here is per-account, and nothing writes. The
 * only reason a token is required is that the old API required one.
 *
 * The season tables are per province — `ab_season_details`, `bc_...` and so
 * on — so the province goes into a table name. That cannot be a bound
 * parameter, which is why the allowlist below exists and why it is the only
 * way a province reaches the query.
 */
class WmuController extends Controller
{
    /**
     * Provinces with a season table.
     *
     * Nine of the thirteen. NL, NT, NU and QC have no table, so a season
     * request for them is a validation failure rather than a SQL error.
     * Note that Yukon is `yk` here while `fishing_lands_pdfs` spells it YT;
     * the two tables disagree and this follows the one it queries.
     */
    private const SEASON_PROVINCES = ['ab', 'bc', 'mb', 'nb', 'ns', 'on', 'pe', 'sk', 'yk'];

    /** A season row, listed because the old query selected the whole table. */
    private const SEASON_COLUMNS = [
        'season_id', 'game_detail_id', 'species_id', 'type', 'season',
        'bag_limit', 'daily_limit', 'posession_limit', 'day_restrictions',
        'notes', 'alerts', 'season_start_date', 'season_end_date',
    ];

    /**
     * One management unit, with the games recorded in it.
     *
     * `id` is the unit *number*, not `wmu_id` — the old endpoint named the
     * parameter that way and the app sends it, so it keeps the name. Numbers
     * repeat across provinces, which is why the province is required.
     */
    public function games(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => ['required'],
            'province' => ['required', 'string', 'max:30'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $wmu = DB::table('wmus')
            ->where('province', (string) $request->input('province'))
            ->where('wmu_number', (string) $request->input('id'))
            ->first(['wmu_id', 'province', 'wmu_number', 'wmu_name']);

        if ($wmu === null) {
            return ApiResponse::notFound('No such management unit in that province.');
        }

        $wmuId = (int) $wmu->wmu_id;

        $details = DB::table('game_details')
            ->where('wmu_id', $wmuId)
            ->orderBy('game_detail_id')
            ->get(['game_detail_id', 'wmu_id', 'game_id'])
            ->map(fn ($row) => [
                'game_detail_id' => (int) $row->game_detail_id,
                'wmu_id' => (int) $row->wmu_id,
                'game_id' => (int) $row->game_id,
            ])
            ->all();

        // The old endpoint built this list by walking the details and
        // appending each one's game, so a unit with two details for the same
        // game listed that game twice. Distinct, in the order they appear.
        $games = DB::table('games')
            ->whereIn('game_id', array_column($details, 'game_id'))
            ->orderBy('game_id')
            ->get(['game_id', 'game_name', 'province', 'regulations_pdf'])
            ->map(fn ($row) => ['game_id' => (int) $row->game_id] + (array) $row)
            ->all();

        return ApiResponse::data([
            'wmu' => ['wmu_id' => $wmuId] + (array) $wmu,
            'game_details' => $details,
            'games' => $games,
        ]);
    }

    /**
     * The seasons recorded for one game detail.
     *
     * `wmu_id` is required because the app sends it, and it is not used as a
     * filter — the old endpoint required it and filtered on `game_detail_id`
     * alone. It stays that way deliberately: a game detail already belongs to
     * exactly one unit, and the app sends the unit *number* as `id` to
     * games() while sending `wmu_id` here, so which identifier this carries
     * cannot be settled from the data. Filtering on a guess would silently
     * return nothing. It is on the list of questions for the app developer.
     */
    public function gameSeasonDetails(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wmu_id' => ['required'],
            'game_detail_id' => ['required', 'integer'],
            'province' => ['required', 'string', 'max:10'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        // The one check that decides what reaches the table name below. It is
        // deliberately not also expressed as a validation rule: two copies of
        // an allowlist drift, and a test can only hold one of them.
        $province = strtolower((string) $request->input('province'));

        if (! in_array($province, self::SEASON_PROVINCES, true)) {
            return ApiResponse::invalid(['province' => ['That province has no season table.']]);
        }

        $table = $province.'_season_details';

        $rows = DB::table($table)
            ->leftJoin('species', 'species.species_id', '=', $table.'.species_id')
            ->where($table.'.game_detail_id', (int) $request->input('game_detail_id'))
            ->distinct()
            ->orderBy($table.'.season_id')
            ->get(array_merge(
                ['species.species_name'],
                array_map(fn ($column) => $table.'.'.$column, self::SEASON_COLUMNS),
            ))
            ->map(fn ($row) => $this->season($row))
            ->all();

        return ApiResponse::data(['items' => $rows]);
    }

    /** Every species, for the pickers. */
    public function species(): JsonResponse
    {
        $species = DB::table('species')
            ->orderBy('species_id')
            ->get(['species_id', 'species_name'])
            ->map(fn ($row) => [
                'species_id' => (int) $row->species_id,
                'species_name' => $this->name($row->species_name),
            ])
            ->all();

        return ApiResponse::data(['items' => $species]);
    }

    /** Native types; the old endpoint returned every number as a string. */
    private function season(object $row): array
    {
        $season = (array) $row;

        foreach (['season_id', 'game_detail_id', 'species_id'] as $column) {
            $season[$column] = (int) $season[$column];
        }

        $season['species_name'] = $this->name($season['species_name']);

        return $season;
    }

    /**
     * A reference name fit to display.
     *
     * 13 of the 174 species names hold a carriage return and newline, so
     * "White-tailed\r\nDeer" arrives wherever a species is named. Matching is
     * by `species_id` everywhere, so collapsing the whitespace changes
     * nothing but the display.
     */
    private function name(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }
}
