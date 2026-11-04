<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\KeyFeature;
use App\Services\SunTimes;
use App\Support\V1\ListingAccess;
use App\Support\V1\ListingRow;
use App\Support\V1\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Listing endpoints.
 *
 * geofence-listings is the busiest route in the system. The old version
 * returned a 500 for any page of 13 rows or more; see ListingRow for that and
 * the other behaviours that changed.
 */
class ListingsController extends Controller
{
    private const SUMMARY_COLUMNS = [
        'land.land_id', 'land.land_name', 'land.land_description', 'land.address',
        'land.coordinates', 'land.land_lat', 'land.land_lng', 'land.geofencing',
        'land.source', 'land.is_rid_land', 'land.is_first_nations',
        'land.is_open_property', 'land.contact_email', 'land.barcode', 'land.owner',
        'land.key_features', 'land.features_list', 'land.fireban_status_id',
    ];

    /**
     * Everything `listings/detail` returns.
     *
     * This is an allowlist, not an exclusion list, and deliberately so. The
     * old API selected `land.*`, which published every column the table ever
     * grows — including `claim_code`, the secret that lets whoever presents it
     * take ownership of a listing, set on 6,131 rows; `supersede_emails_*`,
     * which hold real email addresses; and the payment columns
     * (`txn_number`, `customer_id`, `order_id`, `paid_amount`).
     *
     * An allowlist fails closed: a column added to `land` is absent from the
     * API until someone adds it here, which is the safe direction. A missing
     * field shows up the first time a client looks for it; a leaked secret
     * does not show up at all.
     */
    private const DETAIL_COLUMNS = [
        'land.land_id', 'land.owner', 'land.land_name', 'land.land_description',
        'land.address', 'land.contact_email', 'land.contact_phone',
        'land.coordinates', 'land.land_lat', 'land.land_lng',
        'land.total_deeded', 'land.access_points',
        'land.signage_type', 'land.required_signages', 'land.additional_signages',
        'land.recreation_access_allowed', 'land.people_allowed',
        'land.requests_allowed', 'land.allowed_activities',
        'land.allowed_activities_text', 'land.key_features', 'land.features_list',
        'land.barcode', 'land.auto_approve_requests', 'land.allow_guide',
        'land.allow_unlimited_access', 'land.created_at', 'land.updated_at',
        'land.status', 'land.is_first_nations', 'land.is_open_property',
        'land.kml_file_exists', 'land.fn_land_code', 'land.is_rid_land',
        'land.visibility_status', 'land.active', 'land.kml', 'land.geofencing',
        'land.geo_json', 'land.conservationSiteID', 'land.siteData',
        'land.source', 'land.map_pdf', 'land.camp_sites', 'land.area',
        'land.points', 'land.fireban_status_id',
    ];

    private const GEOFENCE_COLUMNS = [
        'land.land_id', 'land.land_name', 'land.land_description', 'land.address',
        'land.coordinates', 'land.land_lat', 'land.land_lng', 'land.geofencing',
        'land.source', 'land.allowed_activities', 'land.is_rid_land',
        'land.is_first_nations', 'land.is_open_property', 'land.contact_email',
        'land.barcode', 'land.owner', 'land.key_features', 'land.features_list',
        'land.fireban_status_id', 'land.area', 'land.points', 'land.kml',
    ];

    public function all(Request $request): JsonResponse
    {
        $query = $this->summaryQuery($request)
            ->where('land.active', 'Y')
            ->where('land.visibility_status', 'public');

        return $this->listing($request, $query, self::SUMMARY_COLUMNS, withGeometry: false);
    }

    public function geofenceListings(Request $request): JsonResponse
    {
        $query = $this->summaryQuery($request)
            ->where('land.active', 'Y')
            ->where('land.geofencing', 'Yes');

        return $this->listing($request, $query, self::GEOFENCE_COLUMNS, withGeometry: true);
    }

    public function firstNationsListings(Request $request): JsonResponse
    {
        $query = DB::table('land')
            ->where('land.active', 'Y')
            ->where('land.is_first_nations', 1)
            ->where('land.source', 'FirstNationLandsAB')
            ->where('land.kml', '!=', '')
            ->orderByDesc('land.land_id');

        return $this->listing(
            $request,
            $query,
            ['land.land_id', 'land.source', 'land.kml', 'land.fn_land_code', 'land.kml_file_exists'],
            withGeometry: false,
            paginate: false,
        );
    }

    public function search(Request $request): JsonResponse
    {
        $query = $this->summaryQuery($request)
            ->where('land.active', 'Y')
            ->where('land.visibility_status', 'public');

        // Each whitespace-separated word may match the name, the description
        // or the address. The old code built three condition groups and then
        // appended every term to the first one, which happened to produce
        // this same OR — kept, because it is the sensible reading.
        if (($keyword = trim((string) $request->input('q', ''))) !== '') {
            $query->where(function ($outer) use ($keyword) {
                foreach (preg_split('/\s+/', $keyword) as $word) {
                    foreach (['land_name', 'land_description', 'address'] as $column) {
                        $outer->orWhere($column, 'like', '%'.$word.'%');
                    }
                }
            });
        }

        if (($feature = trim((string) $request->input('f', ''))) !== '') {
            $query->where('features_list', 'like', '%'.$feature.'%');
        }

        return $this->listing($request, $query, self::SUMMARY_COLUMNS, withGeometry: false);
    }

    public function detail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $item = $this->listingPayload(
            (int) $request->input('id'),
            (int) $request->attributes->get('api_user')->id,
        );

        if ($item === null) {
            return ApiResponse::notFound('No such listing.');
        }

        return ApiResponse::data(['item' => $item]);
    }

    /**
     * Subscribe the caller to a listing's notifications.
     *
     * The row written is the same one the old API wrote — land_id and user_id,
     * with `created` left to the column default — because both applications
     * share this database and a subscription must look identical whichever
     * one made it.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $landId = (int) $request->input('id');
        $userId = (int) $request->attributes->get('api_user')->id;

        if (! $this->activeListingExists($landId)) {
            return ApiResponse::notFound('No such listing.');
        }

        $already = DB::table('land_notification_subscriptions')
            ->where('land_id', $landId)
            ->where('user_id', $userId)
            ->exists();

        if ($already) {
            // 409 rather than the old 200-with-an-error: the request conflicts
            // with the current state, which is exactly what 409 is for.
            return ApiResponse::error('You are already subscribed to this listing.', 409);
        }

        DB::table('land_notification_subscriptions')->insert([
            'land_id' => $landId,
            'user_id' => $userId,
        ]);

        return ApiResponse::data(['item' => $this->listingPayload($landId, $userId)]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $landId = (int) $request->input('id');
        $userId = (int) $request->attributes->get('api_user')->id;

        if (! $this->activeListingExists($landId)) {
            return ApiResponse::notFound('No such listing.');
        }

        $removed = DB::table('land_notification_subscriptions')
            ->where('land_id', $landId)
            ->where('user_id', $userId)
            ->delete();

        if ($removed === 0) {
            return ApiResponse::error('You are not subscribed to this listing.', 409);
        }

        return ApiResponse::data(['item' => $this->listingPayload($landId, $userId)]);
    }

    /** Block one day on a listing the caller owns. */
    public function blockDay(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'land_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'open_property_limits' => ['sometimes', 'array'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        [$land, $failure] = $this->ownedListing($request, (int) $request->input('land_id'));

        if ($failure !== null) {
            return $failure;
        }

        $date = (string) $request->input('date');

        $exists = DB::table('land_blocked_days')
            ->where('land_id', $land->land_id)
            ->where('blocked_date', $date)
            ->exists();

        if ($exists) {
            return ApiResponse::error('That day is already blocked.', 409);
        }

        $id = DB::table('land_blocked_days')->insertGetId(
            $this->blockedDayRow($land, $date, $request->input('open_property_limits'))
        );

        return ApiResponse::data([
            'blocked_day' => [
                'id' => (int) $id,
                'blocked_date' => $date,
            ],
        ], 201);
    }

    public function unblockDay(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'land_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        [$land, $failure] = $this->ownedListing($request, (int) $request->input('land_id'));

        if ($failure !== null) {
            return $failure;
        }

        $removed = DB::table('land_blocked_days')
            ->where('land_id', $land->land_id)
            ->where('blocked_date', (string) $request->input('date'))
            ->delete();

        if ($removed === 0) {
            return ApiResponse::notFound('That day is not blocked.');
        }

        return ApiResponse::data(['unblocked' => $removed]);
    }

    /**
     * Block several days at once.
     *
     * Days already blocked are reported in `skipped` rather than failing the
     * whole request, so a client retrying a partially-applied batch converges
     * instead of erroring. The inserts run in a transaction, so a failure
     * part-way leaves no half-applied batch behind.
     */
    public function blockMultipleDays(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'land_id' => ['required', 'integer'],
            'dates' => ['required', 'array', 'min:1'],
            'dates.*' => ['date_format:Y-m-d'],
            'open_property_limits' => ['sometimes', 'array'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        [$land, $failure] = $this->ownedListing($request, (int) $request->input('land_id'));

        if ($failure !== null) {
            return $failure;
        }

        $dates = array_values(array_unique($request->input('dates')));
        $limits = $request->input('open_property_limits');

        $existing = DB::table('land_blocked_days')
            ->where('land_id', $land->land_id)
            ->whereIn('blocked_date', $dates)
            ->pluck('blocked_date')
            ->map(fn ($d) => (string) $d)
            ->all();

        $toBlock = array_values(array_diff($dates, $existing));

        if ($toBlock !== []) {
            DB::transaction(function () use ($land, $toBlock, $limits) {
                foreach ($toBlock as $date) {
                    DB::table('land_blocked_days')->insert(
                        $this->blockedDayRow($land, $date, $limits)
                    );
                }
            });
        }

        return ApiResponse::data([
            'blocked' => $toBlock,
            'skipped' => array_values($existing),
        ]);
    }

    /**
     * One blocked-day row, shaped as the old API shaped it: the per-activity
     * limits are stored only for open properties, and `created` is left to the
     * column default.
     */
    private function blockedDayRow(object $land, string $date, mixed $limits): array
    {
        $row = [
            'land_id' => $land->land_id,
            'blocked_date' => $date,
        ];

        if ($land->is_open_property && is_array($limits)) {
            $row['open_property_limits'] = json_encode($limits);
        }

        return $row;
    }

    /**
     * Resolves a listing the caller owns.
     *
     * @return array{0: ?object, 1: ?JsonResponse} the listing, or the response to return
     */
    private function ownedListing(Request $request, int $landId): array
    {
        $land = DB::table('land')->where('land_id', $landId)->first();

        if ($land === null) {
            return [null, ApiResponse::notFound('No such listing.')];
        }

        if (! ListingAccess::isOwner($this->userId($request), $landId)) {
            // 403, not 401: the caller is authenticated, just not the owner.
            // Deliberately owner-only, not owner-or-manager: that is what the
            // old application enforced, and writes must stay identical.
            return [null, ApiResponse::error('Only the owner can change this listing.', 403)];
        }

        return [$land, null];
    }

    private function activeListingExists(int $landId): bool
    {
        return DB::table('land')
            ->where('land_id', $landId)
            ->where('active', 'Y')
            ->exists();
    }

    /** The full listing payload, shared by detail, subscribe and unsubscribe. */
    private function listingPayload(int $landId, int $userId): ?array
    {
        $row = DB::table('land')
            ->select(array_merge(
                self::DETAIL_COLUMNS,
                [DB::raw('GROUP_CONCAT(photos.photo_name) AS photos')],
            ))
            ->leftJoin('photos', 'photos.land_id', '=', 'land.land_id')
            ->where('land.active', 'Y')
            ->where('land.land_id', $landId)
            ->groupBy('land.land_id')
            ->first();

        if ($row === null) {
            return null;
        }

        $item = ListingRow::format((array) $row, KeyFeature::activeOrdered(), withGeometry: true);

        // Only First Nations listings with a published KML get a URL.
        $item['kml_url'] = ($item['kml_file_exists'] ?? false)
            && ! empty($item['fn_land_code'])
            && ($item['is_first_nations'] ?? false)
                ? rtrim(config('app.url'), '/').'/uploads/kml/'.$item['fn_land_code'].'.kml'
                : null;

        $item['is_subscribed'] = DB::table('land_notification_subscriptions')
            ->where('land_id', $item['land_id'])
            ->where('user_id', $userId)
            ->exists();

        return $item;
    }

    /**
     * Which days of this listing are blocked by the owner, and how full the
     * bookable days are. Percentages are bucketed to 25/50/75/100 as the
     * client renders them as a fill level, not a precise figure.
     */
    public function availability(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $land = DB::table('land')->where('land_id', (int) $request->input('id'))->first();

        if ($land === null) {
            return ApiResponse::notFound('No such listing.');
        }

        $allowed = $this->allowedCount($land);
        $monthStart = date('Y-m-01');

        $requests = DB::table('requests')
            ->select(['request_date', 'people_count', 'units_count'])
            ->where('land_id', $land->land_id)
            ->whereIn('status', ['Accepted', 'Checked In', 'Checked Out'])
            ->where('request_date', '>=', $monthStart)
            ->get();

        $perDay = [];

        foreach ($requests as $row) {
            // An open property counts vehicles; a private one counts people.
            // Either way a booking counts as at least one.
            $count = $land->is_open_property
                ? max(1, (int) $row->units_count)
                : max(1, (int) $row->people_count);

            $perDay[$row->request_date] = ($perDay[$row->request_date] ?? 0) + $count;
        }

        $percentFilled = [];

        foreach ($perDay as $date => $count) {
            if ($allowed <= 0) {
                continue;
            }

            $percentFilled[$date] = match (true) {
                $count >= $allowed => 100,
                ($pct = (int) ceil(($count / $allowed) * 100)) > 50 => 75,
                $pct > 25 => 50,
                $pct > 0 => 25,
                default => 0,
            };
        }

        // Owner-blocked days apply to private listings only; an open property
        // uses per-activity limits instead.
        $blocked = $land->is_open_property
            ? []
            : DB::table('land_blocked_days')
                ->where('land_id', $land->land_id)
                ->where('blocked_date', '>=', date('Y-m-d'))
                ->orderBy('blocked_date')
                ->pluck('blocked_date')
                ->all();

        return ApiResponse::data([
            'blocked' => $blocked,
            'percent_filled' => $percentFilled,
        ]);
    }

    public function blockedDays(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'land_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $landId = (int) $request->input('land_id');

        // Owner or manager only. Blocking and unblocking a day already
        // required that; reading the schedule back did not, which let any
        // account enumerate when any listing was closed and on what terms.
        // Requesters get what they need from listings/availability instead.
        //
        // 404 rather than 403, so the existence of a listing is not
        // confirmed to someone with no claim to it.
        if (! ListingAccess::canManage($this->userId($request), $landId)) {
            return ApiResponse::notFound('No such listing.');
        }

        $days = DB::table('land_blocked_days')
            ->select(['id', 'blocked_date', 'open_property_limits'])
            ->where('land_id', $landId)
            ->orderBy('blocked_date')
            ->get()
            ->map(function ($day) {
                $limits = $day->open_property_limits;

                return [
                    'id' => (int) $day->id,
                    'blocked_date' => $day->blocked_date,
                    'open_property_limits' => $limits
                        ? (json_decode($limits, true) ?: null)
                        : null,
                ];
            })
            ->all();

        return ApiResponse::data([
            'total' => count($days),
            'blocked_days' => $days,
        ]);
    }

    public function sunDetails(Request $request, SunTimes $sun): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        return ApiResponse::data($sun->forLocation(
            (float) $request->input('lat'),
            (float) $request->input('lng'),
        ));
    }

    private function summaryQuery(Request $request)
    {
        return DB::table('land')
            ->leftJoin('photos', 'photos.land_id', '=', 'land.land_id')
            ->where('land.updated_at', '>=', (int) $request->input('after', 0))
            ->groupBy('land.land_id')
            ->orderByDesc('land.land_id');
    }

    private function listing(
        Request $request,
        $query,
        array $columns,
        bool $withGeometry,
        bool $paginate = true,
    ): JsonResponse {
        $this->rememberUserPosition($request);

        $query->select(array_merge($columns, in_array('land.land_id', $columns, true)
            && ! in_array('land.fn_land_code', $columns, true)
                ? [DB::raw('GROUP_CONCAT(photos.photo_name) AS photos')]
                : []));

        if ($paginate) {
            $start = (int) $request->input('start', 0);
            $limit = (int) $request->input('limit', 0);

            if ($start > 0) {
                $query->offset($start);
            }

            if ($limit > 0) {
                $query->limit(min($limit, 500));
            }
        }

        $keyFeatures = KeyFeature::activeOrdered();

        $items = $query->get()
            ->map(fn ($row) => ListingRow::format((array) $row, $keyFeatures, $withGeometry))
            ->all();

        return ApiResponse::data(['items' => $items]);
    }

    /**
     * These endpoints double as a position report: when the caller sends
     * coordinates, they are written to the user's profile. Position decides
     * what counts as a position; a map's default centre does not.
     */
    private function rememberUserPosition(Request $request): void
    {
        Position::remember(
            $this->userId($request),
            $request->input('user_lat'),
            $request->input('user_lng'),
        );
    }

    /**
     * How many bookings a listing accepts per day. A private listing uses
     * people_allowed; an open property sums its per-activity limits.
     */
    private function allowedCount(object $land): int
    {
        if (! $land->is_open_property) {
            return (int) $land->people_allowed;
        }

        $limits = DB::table('land_access_limits')
            ->where('land_id', $land->land_id)
            ->first();

        if ($limits === null) {
            return (int) $land->people_allowed;
        }

        $total = 0;

        foreach (['foot', 'hunting', 'fishing', 'ohv', 'horse', 'snowmobile'] as $activity) {
            $total += (int) ($limits->{$activity} ?? 0);
        }

        return $total;
    }
}
