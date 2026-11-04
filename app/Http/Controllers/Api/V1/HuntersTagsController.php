<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Support\V1\Wmu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A hunter's tags: what they hold, where, and for what.
 *
 * These are what a hunting alert matches on. An owner posting one names a
 * province, some management units, a species and a gender, and everyone
 * holding an active tag that fits is notified — so a tag is a subscription,
 * and filing it against the wrong unit means being alerted about the wrong
 * place.
 *
 * Two of the old controller's seven actions are not here. `hunters-tags/list`
 * and `hunters-tags/categories` were copies of the two Notifications
 * endpoints of the same name, pasted in and left: `list` carries a double
 * comma in its SELECT, and both reference classes the file never imports.
 * Both answer with a PHP fatal error, verified against the running old API,
 * so they have never worked and no app build can be using them. The working
 * versions are `notifications/list` and `notifications/categories`.
 */
class HuntersTagsController extends Controller
{
    /** What a tag reports; `status` and the dates drive alert matching. */
    private const COLUMNS = [
        'id', 'tag_number', 'wildlife_id_number', 'season_start_date',
        'season_end_date', 'species_id', 'species_gender', 'province',
        'status', 'created', 'updated',
    ];

    /** The states the column accepts; the old API validated none of them. */
    private const STATUSES = ['Active', 'Expired', 'Used'];

    /**
     * The caller's tags.
     *
     * Expiry is applied first, as the old endpoint did — but only to the
     * caller's own rows. The old sweep was an `updateAll` across the whole
     * table, so one hunter opening this screen rewrote every other hunter's
     * tags, and whether a tag counted as expired depended on who had read
     * theirs most recently. Alert matching no longer relies on this having
     * run; see App\Services\NotificationBroadcast.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->userId($request);

        DB::table('hunters_match_tags')
            ->where('user_id', $userId)
            ->where('status', 'Active')
            ->whereNotNull('season_end_date')
            ->whereRaw('season_end_date < CURDATE()')
            ->update(['status' => 'Expired']);

        $tags = DB::table('hunters_match_tags')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get(self::COLUMNS)
            ->map(fn ($tag) => $this->format($tag))
            ->all();

        return ApiResponse::data(['items' => $tags]);
    }

    /**
     * One of the caller's tags, with the units it covers.
     *
     * A tag belonging to somebody else answers 404, the same as one that does
     * not exist, so the response says nothing about other people's tags.
     */
    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tag_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $tagId = (int) $request->input('tag_id');

        $tag = DB::table('hunters_match_tags')
            ->where('id', $tagId)
            ->where('user_id', $this->userId($request))
            ->first(self::COLUMNS);

        if ($tag === null) {
            return ApiResponse::notFound('No such tag.');
        }

        return ApiResponse::data(['item' => $this->format($tag) + [
            'wmus' => Wmu::numbersForTag($tagId),
        ]]);
    }

    /** File a new tag. */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateTag($request);

        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        [$attributes, $wmuIds] = $validated;
        $attributes['user_id'] = $this->userId($request);

        $tagId = DB::transaction(function () use ($attributes, $wmuIds) {
            $id = (int) DB::table('hunters_match_tags')->insertGetId($attributes);
            $this->replaceZones($id, $wmuIds);

            return $id;
        });

        return ApiResponse::data(['id' => $tagId], 201);
    }

    /** Replace one of the caller's tags. */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tag_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $tagId = (int) $request->input('tag_id');

        $exists = DB::table('hunters_match_tags')
            ->where('id', $tagId)
            ->where('user_id', $this->userId($request))
            ->exists();

        if (! $exists) {
            return ApiResponse::notFound('No such tag.');
        }

        $validated = $this->validateTag($request);

        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        [$attributes, $wmuIds] = $validated;
        $attributes['updated'] = now();

        // The old version saved the tag, then deleted and rebuilt its zones
        // with no transaction: a failure part way through left a tag covering
        // some of the units the hunter asked for and none of the rest.
        DB::transaction(function () use ($tagId, $attributes, $wmuIds) {
            DB::table('hunters_match_tags')->where('id', $tagId)->update($attributes);
            $this->replaceZones($tagId, $wmuIds);
        });

        return ApiResponse::data(['id' => $tagId]);
    }

    /** Remove one of the caller's tags, and the units it covered. */
    public function destroy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tag_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $tagId = (int) $request->input('tag_id');

        $owned = DB::table('hunters_match_tags')
            ->where('id', $tagId)
            ->where('user_id', $this->userId($request))
            ->exists();

        if (! $owned) {
            return ApiResponse::notFound('No such tag.');
        }

        DB::transaction(function () use ($tagId) {
            DB::table('hunters_match_tags_wmu_zones')->where('tag_id', $tagId)->delete();
            DB::table('hunters_match_tags')->where('id', $tagId)->delete();
        });

        return ApiResponse::data(['deleted' => true]);
    }

    /**
     * The fields a tag is written from, or the failure to report.
     *
     * `tag_required` decides whether the physical tag details are needed, as
     * before. Everything else is now checked: the old version accepted any
     * string as a status, any text as a date, and a species id that did not
     * exist — the model's own rules would have caught the species, but the
     * controller read `save()` only for a generic error message.
     *
     * @return array{0: array<string,mixed>, 1: list<int>}|JsonResponse
     */
    private function validateTag(Request $request): array|JsonResponse
    {
        $tagRequired = $request->boolean('tag_required', true);

        $rules = [
            'species_id' => ['required', 'integer', 'exists:species,species_id'],
            'species_gender' => ['required', 'string', 'max:60'],
            'province' => ['required', 'string', 'max:60'],
            'wmus' => ['required'],
            'status' => ['required', 'string', 'in:'.implode(',', self::STATUSES)],
            'tag_number' => ['nullable', 'integer'],
            'wildlife_id_number' => ['nullable', 'integer'],
            'season_start_date' => ['nullable', 'date'],
            'season_end_date' => ['nullable', 'date', 'after_or_equal:season_start_date'],
        ];

        if ($tagRequired) {
            foreach (['tag_number', 'wildlife_id_number', 'season_start_date', 'season_end_date'] as $field) {
                $rules[$field][0] = 'required';
            }
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $province = (string) $request->input('province');
        $numbers = Wmu::numbers($request->input('wmus'));
        $wmuIds = Wmu::idsFor($province, $numbers);

        // Every unit must exist in that province. The old version created a
        // row in `wmus` for anything it did not recognise, from a client
        // request, which is how 58 units came to have no province at all; and
        // it resolved by number alone, so a tag could be filed against
        // another province's unit of the same number. See App\Support\V1\Wmu.
        if ($wmuIds === [] || count($wmuIds) !== count($numbers)) {
            return ApiResponse::invalid([
                'wmus' => ['No management units match that province and list.'],
            ]);
        }

        return [[
            'tag_number' => $tagRequired ? $request->input('tag_number') : null,
            'wildlife_id_number' => $tagRequired ? $request->input('wildlife_id_number') : null,
            'season_start_date' => $request->input('season_start_date') ?: null,
            'season_end_date' => $request->input('season_end_date') ?: null,
            'species_id' => (int) $request->input('species_id'),
            'species_gender' => (string) $request->input('species_gender'),
            'province' => $province,
            'status' => (string) $request->input('status'),
        ], $wmuIds];
    }

    /** @param  list<int>  $wmuIds */
    private function replaceZones(int $tagId, array $wmuIds): void
    {
        DB::table('hunters_match_tags_wmu_zones')->where('tag_id', $tagId)->delete();

        DB::table('hunters_match_tags_wmu_zones')->insert(array_map(
            fn ($wmuId) => ['tag_id' => $tagId, 'wmu_id' => $wmuId],
            $wmuIds,
        ));
    }

    /** Native types: these come back as strings from the query builder. */
    private function format(object $tag): array
    {
        $row = (array) $tag;

        foreach (['id', 'species_id'] as $column) {
            $row[$column] = (int) $row[$column];
        }

        foreach (['tag_number', 'wildlife_id_number'] as $column) {
            $row[$column] = $row[$column] === null ? null : (int) $row[$column];
        }

        return $row;
    }
}
