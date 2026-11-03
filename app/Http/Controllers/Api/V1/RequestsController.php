<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\KeyFeature;
use App\Services\BookingSubmission;
use App\Services\RequestNotifier;
use App\Support\V1\ListingAccess;
use App\Support\V1\ListingRow;
use App\Support\V1\RequestRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Access requests: what a user has asked for, and what has been asked of the
 * listings they are responsible for.
 *
 * Two sides to almost everything here. A user is the *requester* on their own
 * bookings, and the *owner* side on listings they own or manage — note
 * "or manage": `land_managers` grants the same access as ownership, and the
 * old API honoured that, so this does too.
 *
 * Only the read endpoints are implemented so far. The booking lifecycle
 * (submit, accept, decline, check in and out, cancel) writes to the shared
 * database and sends notifications, and is being done separately.
 */
class RequestsController extends Controller
{
    /** The listing columns these endpoints join alongside the request. */
    private const LISTING_COLUMNS = [
        'land.land_id', 'land.land_name', 'land.address', 'land.barcode',
        'land.coordinates', 'land.allowed_activities', 'land.is_rid_land',
        'land.is_first_nations', 'land.is_open_property', 'land.geofencing',
        'land.area', 'land.points',
    ];

    /**
     * Everything `requests/detail` returns from the request itself.
     *
     * An allowlist rather than `requests.*`, for the same reason the listing
     * detail uses one: a column added to the table should not publish itself.
     * `send_app_review_email` is an internal mail flag and is withheld.
     */
    private const DETAIL_REQUEST_COLUMNS = [
        'requests.request_id', 'requests.requester_id', 'requests.land_id',
        'requests.reason', 'requests.description', 'requests.is_guide_request',
        'requests.is_supersede_request', 'requests.request_date',
        'requests.people_count', 'requests.people_details',
        'requests.start_date', 'requests.end_date', 'requests.status',
        'requests.approved', 'requests.decision_by',
        'requests.landowner_comment', 'requests.requester_comment',
        'requests.requested_at', 'requests.decision_at', 'requests.cancelled_at',
        'requests.checkedin_datetime', 'requests.checkedout_datetime',
        'requests.suspicious_activity', 'requests.harvest_animal',
        'requests.is_user_read', 'requests.is_owner_read',
        'requests.activity_type', 'requests.units_count',
        'requests.visitor_details', 'requests.ohv_details',
    ];

    /** How many nearby and popular listings the upcoming screen shows. */
    private const SUGGESTION_LIMIT = 20;

    /** Listing columns carried by the nearby and popular suggestions. */
    private const SUGGESTION_COLUMNS = [
        'land.land_id', 'land.land_name', 'land.land_description', 'land.address',
        'land.source', 'land.allowed_activities', 'land.is_rid_land',
        'land.is_first_nations', 'land.is_open_property', 'land.barcode',
        'land.owner', 'land.coordinates', 'land.geofencing', 'land.area',
        'land.points', 'land.key_features', 'land.features_list',
        'land.fireban_status_id',
    ];

    private const REQUEST_COLUMNS = [
        'requests.request_id', 'requests.request_date', 'requests.status',
        'requests.reason', 'requests.checkedin_datetime',
        'requests.checkedout_datetime', 'requests.is_user_read',
        'requests.is_owner_read', 'requests.requested_at',
    ];

    /**
     * How many requests await the caller's attention, on each side.
     *
     * Counts were strings in the old API; they are integers here.
     */
    public function unreadCounts(Request $request): JsonResponse
    {
        $userId = $this->userId($request);

        $requester = DB::table('requests')
            ->join('land', 'requests.land_id', '=', 'land.land_id')
            ->where('requests.requester_id', $userId)
            ->where('requests.is_user_read', 0)
            ->count();

        $owner = DB::table('requests')
            ->join('land', 'requests.land_id', '=', 'land.land_id')
            ->where('land.owner', $userId)
            ->where('requests.is_owner_read', 0)
            ->count();

        return ApiResponse::data([
            'requester_unread' => $requester,
            'owner_unread' => $owner,
        ]);
    }

    /**
     * Every request the caller has made.
     *
     * `mine` is the same list with expired requests removed. The old API had
     * that filter present but commented out on this endpoint, so the two
     * returned the same rows; the distinction is kept because the endpoints
     * are named as though it exists, and `mine` is the one the app uses for
     * the user's own active bookings.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->requesterList($request, includeExpired: true);
    }

    public function mine(Request $request): JsonResponse
    {
        return $this->requesterList($request, includeExpired: false);
    }

    /** Requests made against any listing the caller owns or manages. */
    public function bookings(Request $request): JsonResponse
    {
        $landIds = ListingAccess::manageableListingIds($this->userId($request));

        if ($landIds === []) {
            return ApiResponse::data(['items' => []]);
        }

        $query = $this->requestQuery()
            ->whereIn('land.land_id', $landIds);

        return $this->paginated($request, $query);
    }

    /**
     * Requests against one listing the caller owns or manages.
     *
     * The parameter is `id`, as the app sends it. The old API validated it
     * under the name `land_id`, so a missing value produced "Land Id cannot be
     * blank." for a field called `id`; the message now names what was sent.
     */
    public function listingBookings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $landId = (int) $request->input('id');

        if (! ListingAccess::canManage($this->userId($request), $landId)) {
            // 404 rather than 403: whether a listing exists is not something
            // to confirm to someone with no access to it.
            return ApiResponse::notFound('No such listing.');
        }

        // No listing columns here, unlike the other lists: the caller already
        // knows which listing they asked about.
        $query = DB::table('requests')
            ->select(self::REQUEST_COLUMNS)
            ->where('requests.land_id', $landId)
            ->groupBy('requests.request_id')
            ->orderByDesc('requests.request_date')
            ->orderByDesc('requests.decision_at')
            ->orderByDesc('requests.checkedin_datetime')
            ->orderByDesc('requests.checkedout_datetime');

        return $this->paginated($request, $query);
    }

    /**
     * One request in full, with the listing and the requester's profile.
     *
     * Visible to the requester and to anyone who owns or manages the listing.
     * The old API checked neither and returned any request to any caller.
     */
    public function detail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'request_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $row = DB::table('requests')
            ->select(array_merge(
                self::DETAIL_REQUEST_COLUMNS,
                self::LISTING_COLUMNS,
                [
                    'land.address as land_address', 'land.source',
                    'land.contact_email', 'land.contact_phone',
                    DB::raw('GROUP_CONCAT(photos.photo_name) AS photos'),
                ],
                ['profile.name', 'profile.phone', 'profile.user_lat', 'profile.user_lng'],
            ))
            ->join('land', 'requests.land_id', '=', 'land.land_id')
            ->leftJoin('photos', 'photos.land_id', '=', 'land.land_id')
            ->leftJoin('profile', 'profile.user_id', '=', 'requests.requester_id')
            ->where('requests.request_id', (int) $request->input('request_id'))
            // Group by the request's own key, not the listing's. The grouping
            // exists only to collapse the photos GROUP_CONCAT, and grouping by
            // land_id leaves requests.* non-aggregated — which ONLY_FULL_GROUP_BY
            // rejects. Yii ran without strict mode so it never surfaced; both
            // Laravel and MySQL 8 have it on by default.
            ->groupBy('requests.request_id')
            ->first();

        if ($row === null) {
            return ApiResponse::notFound('No such request.');
        }

        $item = RequestRow::format((array) $row);
        $userId = $this->userId($request);

        $permitted = $item['requester_id'] === $userId
            || ListingAccess::canManage($userId, (int) $item['land_id']);

        if (! $permitted) {
            return ApiResponse::notFound('No such request.');
        }

        return ApiResponse::data(['item' => $item]);
    }

    /**
     * What the caller has coming up, plus suggestions.
     *
     * Three lists in one response, because the app opens on this screen:
     * their own live bookings, public listings near them, and the most
     * requested listings overall.
     *
     * `nearby` needs coordinates and is empty without them. Sending them also
     * records the caller's position on their profile, which is what the
     * geofence features read.
     */
    public function upcoming(Request $request): JsonResponse
    {
        $userId = $this->userId($request);

        $this->rememberPosition($request, $userId);
        $this->rememberDeviceToken($request, $userId);

        // Accepted or checked-in bookings from today onwards, and anything
        // still checked in whatever its date — someone who never checked out
        // is still on the land.
        $items = $this->requestQuery()
            ->where('requests.requester_id', $userId)
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->whereIn('requests.status', ['Accepted', 'Checked In'])
                        ->whereRaw('requests.request_date >= CURDATE()');
                })->orWhere('requests.status', 'Checked In');
            })
            ->reorder('requests.request_date')
            ->get();

        return ApiResponse::data([
            'items' => RequestRow::formatAll($items),
            'nearby' => $this->nearbyListings($request),
            'popular' => $this->popularListings(),
        ]);
    }

    /**
     * Whether the caller has a live booking on one listing today.
     *
     * The app calls this at the gate, so it also returns the stored push token
     * for the device — which is why `os` is a parameter here.
     */
    public function todayBookings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'land_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $userId = $this->userId($request);
        $landId = (int) $request->input('land_id');

        $items = DB::table('requests')
            ->select(['requests.request_id', 'requests.status'])
            ->join('land', 'requests.land_id', '=', 'land.land_id')
            ->where('requests.requester_id', $userId)
            ->where('requests.land_id', $landId)
            ->whereIn('requests.status', ['Pending', 'Accepted', 'Checked In'])
            ->whereRaw('requests.request_date = CURDATE()')
            ->get();

        $os = strtolower((string) $request->input('os', ''));

        return ApiResponse::data([
            'user_name' => DB::table('profile')->where('user_id', $userId)->value('first_name'),
            'items' => RequestRow::formatAll($items),
            'device_token' => in_array($os, ['ios', 'android'], true)
                ? (string) DB::table('device_tokens')
                    ->where('user_id', $userId)
                    ->where('os', $os)
                    ->orderByDesc('device_token_id')
                    ->value('device_token')
                : '',
            'land_id' => $landId,
            'listing_name' => DB::table('land')->where('land_id', $landId)->value('land_name'),
        ]);
    }

    /** Public listings nearest the caller, closest first. */
    private function nearbyListings(Request $request): array
    {
        $lat = $this->coordinate($request->input('user_lat'), 90);
        $lng = $this->coordinate($request->input('user_lng'), 180);

        if ($lat === null || $lng === null) {
            return [];
        }

        $query = $this->suggestionQuery()
            ->selectRaw($this->distanceExpression($lat, $lng))
            ->orderBy('distance');

        return $this->asListings($query->limit(self::SUGGESTION_LIMIT)->get());
    }

    /** The most requested public listings. */
    private function popularListings(): array
    {
        $query = $this->suggestionQuery()
            ->join('requests', 'requests.land_id', '=', 'land.land_id')
            ->selectRaw('COUNT(requests.request_id) AS requests')
            ->orderByDesc('requests');

        return $this->asListings($query->limit(self::SUGGESTION_LIMIT)->get());
    }

    private function suggestionQuery()
    {
        return DB::table('land')
            ->select(array_merge(self::SUGGESTION_COLUMNS, [
                DB::raw('GROUP_CONCAT(photos.photo_name) AS photos'),
            ]))
            ->leftJoin('photos', 'photos.land_id', '=', 'land.land_id')
            ->where('land.active', 'Y')
            ->where('land.visibility_status', 'public')
            ->groupBy('land.land_id');
    }

    private function asListings($rows): array
    {
        $keyFeatures = KeyFeature::activeOrdered();

        return $rows
            ->map(fn ($row) => ListingRow::format((array) $row, $keyFeatures, withGeometry: true))
            ->all();
    }

    /**
     * Great-circle distance in kilometres.
     *
     * The coordinates are validated floats by the time they reach here, never
     * caller text — this expression cannot be parameterised because the same
     * value appears three times, and PDO will not reuse a named placeholder
     * under native prepares.
     */
    private function distanceExpression(float $lat, float $lng): string
    {
        return sprintf(
            '(6371 * acos(cos(radians(%1$F)) * cos(radians(land_lat))'
            .' * cos(radians(land_lng) - radians(%2$F))'
            .' + sin(radians(%1$F)) * sin(radians(land_lat)))) AS distance',
            $lat,
            $lng,
        );
    }

    private function rememberPosition(Request $request, int $userId): void
    {
        $lat = $this->coordinate($request->input('user_lat'), 90);
        $lng = $this->coordinate($request->input('user_lng'), 180);

        if ($lat === null || $lng === null) {
            return;
        }

        DB::table('profile')->where('user_id', $userId)
            ->update(['user_lat' => $lat, 'user_lng' => $lng]);
    }

    /** One push token per user per OS: the old rows are replaced, not updated. */
    private function rememberDeviceToken(Request $request, int $userId): void
    {
        $token = (string) $request->input('device_token', '');
        $os = strtolower((string) $request->input('os', ''));

        if ($token === '' || ! in_array($os, ['ios', 'android'], true)) {
            return;
        }

        DB::table('device_tokens')->where('user_id', $userId)->where('os', $os)->delete();
        DB::table('device_tokens')->insert([
            'user_id' => $userId, 'os' => $os, 'device_token' => $token,
        ]);
    }

    private function coordinate(mixed $value, float $max): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        return ($value < -$max || $value > $max) ? null : $value;
    }

    /**
     * Make an access request, one row per day of the stay.
     *
     * The availability and limit rules live in BookingSubmission, because they
     * are the substance and are worth testing without a request in the way.
     * A refusal is 409: the request is well formed, the days are not available.
     */
    public function submit(
        Request $request,
        BookingSubmission $submission,
        RequestNotifier $notifier,
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'land_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:255'],
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
            'people_count' => ['nullable', 'integer', 'min:1'],
            'units_count' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'people_details' => ['nullable', 'string'],
            'ohv_details' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $land = DB::table('land')->where('land_id', (int) $request->input('land_id'))->first();

        if ($land === null || $land->active !== 'Y') {
            return ApiResponse::notFound('No such listing.');
        }

        // The submission rules need the caller's guide details, which live on
        // the profile rather than the user row.
        $caller = DB::table('user')
            ->leftJoin('profile', 'profile.user_id', '=', 'user.id')
            ->where('user.id', $this->userId($request))
            ->select(['user.id', 'user.email', 'profile.guide', 'profile.guide_number'])
            ->first();

        [$ids, $refusal] = $submission->submit($caller, $land, $request->all());

        if ($refusal !== null) {
            return ApiResponse::error($refusal, 409);
        }

        $created = DB::table('requests')->whereIn('request_id', $ids)->get();
        $first = $created->first();

        if ($first !== null) {
            $notifier->submitted(
                $first,
                $land,
                $this->describeDates($request->input('start'), $request->input('end')),
                $first->status === 'Accepted',
            );
        }

        return ApiResponse::data([
            'request_ids' => $ids,
            'status' => $first->status ?? 'Pending',
            'days' => count($ids),
        ], 201);
    }

    /** How the notification refers to the stay: one day, or a range. */
    private function describeDates(string $start, string $end): string
    {
        $from = date('M d, Y', strtotime($start));

        return $start === $end
            ? sprintf('on "%s"', $from)
            : sprintf('from "%s" to "%s"', $from, date('M d, Y', strtotime($end)));
    }

    /**
     * Accept or decline a request. Owner or accepted manager only.
     *
     * The row written matches the old application's exactly: status,
     * decision_at, decision_by, and the two read flags flipped so the decision
     * shows as unread to the requester and read to the owner.
     */
    public function accept(Request $request, RequestNotifier $notifier): JsonResponse
    {
        return $this->decide($request, $notifier, 'Accepted');
    }

    public function decline(Request $request, RequestNotifier $notifier): JsonResponse
    {
        return $this->decide($request, $notifier, 'Declined');
    }

    /**
     * Cancel a request. The requester's own, and only while it is still
     * Pending or Accepted — a cancelled or completed booking cannot be
     * cancelled again.
     */
    public function cancel(Request $request, RequestNotifier $notifier): JsonResponse
    {
        [$booking, $failure] = $this->ownRequest($request, ['Pending', 'Accepted']);

        if ($failure !== null) {
            return $failure;
        }

        DB::table('requests')
            ->where('request_id', $booking->request_id)
            ->update(['status' => 'Cancelled', 'cancelled_at' => time()]);

        $notifier->cancelled($booking, $this->listingFor($booking));

        return ApiResponse::data(['request_id' => (int) $booking->request_id, 'status' => 'Cancelled']);
    }

    /** Check in to an accepted booking. */
    public function checkin(Request $request, RequestNotifier $notifier): JsonResponse
    {
        [$booking, $failure] = $this->ownRequest($request, ['Accepted']);

        if ($failure !== null) {
            return $failure;
        }

        // The old application had a date check here, commented out, so a
        // booking can be checked into on any day. Left as it is: changing it
        // would change behaviour for the apps still using the other API.
        $at = date('Y-m-d H:i:s');

        DB::table('requests')
            ->where('request_id', $booking->request_id)
            ->update(['status' => 'Checked In', 'checkedin_datetime' => $at]);

        $notifier->checkedIn($booking, $this->listingFor($booking));

        return ApiResponse::data([
            'request_id' => (int) $booking->request_id,
            'status' => 'Checked In',
            'checkedin_datetime' => $at,
        ]);
    }

    /**
     * Check out of a booking, recording what the visitor reports.
     *
     * `suspicious_activity` and `harvest_animal` are the requester's own
     * answers at check-out, not anything held about them.
     */
    public function checkout(Request $request, RequestNotifier $notifier): JsonResponse
    {
        // harvest_animal is an enum in the database. Unvalidated, anything
        // else reaches MySQL and comes back as a 500 under strict mode rather
        // than telling the client what was wrong with the request.
        $validator = Validator::make($request->all(), [
            'harvest_animal' => ['nullable', 'in:Yes,No'],
            'suspicious_activity' => ['nullable', 'string', 'max:65535'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        [$booking, $failure] = $this->ownRequest($request, ['Checked In']);

        if ($failure !== null) {
            return $failure;
        }

        $at = date('Y-m-d H:i:s');

        $changes = [
            'status' => 'Checked Out',
            'checkedout_datetime' => $at,
            'send_app_review_email' => 1,
            'suspicious_activity' => trim((string) $request->input('suspicious_activity', '')),
        ];

        // Only overwritten when supplied, as before.
        if ($harvest = $request->input('harvest_animal')) {
            $changes['harvest_animal'] = $harvest;
        }

        DB::table('requests')->where('request_id', $booking->request_id)->update($changes);

        $notifier->checkedOut(
            $booking,
            $this->listingFor($booking),
            $request->input('category'),
            $request->input('sub_category'),
        );

        return ApiResponse::data([
            'request_id' => (int) $booking->request_id,
            'status' => 'Checked Out',
            'checkedout_datetime' => $at,
        ]);
    }

    /**
     * Mark a request read, on whichever side the caller is.
     *
     * `is_owner=1` marks the owner side and needs owner or manager access;
     * otherwise the requester marks their own.
     */
    public function markRead(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'request_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $booking = DB::table('requests')
            ->where('request_id', (int) $request->input('request_id'))
            ->first();

        if ($booking === null) {
            return ApiResponse::notFound('No such request.');
        }

        $userId = $this->userId($request);
        $asOwner = filter_var($request->input('is_owner', false), FILTER_VALIDATE_BOOLEAN);

        if ($asOwner) {
            if (! ListingAccess::canManage($userId, (int) $booking->land_id)) {
                return ApiResponse::notFound('No such request.');
            }

            $column = 'is_owner_read';
        } else {
            if ((int) $booking->requester_id !== $userId) {
                return ApiResponse::notFound('No such request.');
            }

            $column = 'is_user_read';
        }

        DB::table('requests')
            ->where('request_id', $booking->request_id)
            ->update([$column => 1]);

        return ApiResponse::data(['request_id' => (int) $booking->request_id, $column => true]);
    }

    /** Delete one of the caller's own requests. */
    public function destroy(Request $request): JsonResponse
    {
        [$booking, $failure] = $this->ownRequest($request, null);

        if ($failure !== null) {
            return $failure;
        }

        DB::table('requests')->where('request_id', $booking->request_id)->delete();

        return ApiResponse::data(['deleted' => (int) $booking->request_id]);
    }

    private function decide(Request $request, RequestNotifier $notifier, string $decision): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'request_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $booking = DB::table('requests')
            ->where('request_id', (int) $request->input('request_id'))
            ->first();

        if ($booking === null) {
            return ApiResponse::notFound('No such request.');
        }

        if (! ListingAccess::canManage($this->userId($request), (int) $booking->land_id)) {
            return ApiResponse::notFound('No such request.');
        }

        if (in_array($booking->status, ['Checked In', 'Checked Out', 'Cancelled'], true)) {
            return ApiResponse::error('That request can no longer be decided.', 409);
        }

        DB::table('requests')
            ->where('request_id', $booking->request_id)
            ->update([
                'status' => $decision,
                'decision_at' => time(),
                'decision_by' => $this->userId($request),
                'is_owner_read' => 1,
                'is_user_read' => 0,
            ]);

        $notifier->decided($booking, $this->listingFor($booking), $decision);

        return ApiResponse::data([
            'request_id' => (int) $booking->request_id,
            'status' => $decision,
        ]);
    }

    /**
     * A request the caller made themselves, optionally restricted to the
     * statuses the transition allows.
     *
     * @param  list<string>|null  $allowedStatuses
     * @return array{0: ?object, 1: ?JsonResponse}
     */
    private function ownRequest(Request $request, ?array $allowedStatuses): array
    {
        $validator = Validator::make($request->all(), [
            'request_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return [null, ApiResponse::invalid($validator->errors()->toArray())];
        }

        $booking = DB::table('requests')
            ->where('request_id', (int) $request->input('request_id'))
            ->where('requester_id', $this->userId($request))
            ->first();

        if ($booking === null) {
            return [null, ApiResponse::notFound('No such request.')];
        }

        if ($allowedStatuses !== null && ! in_array($booking->status, $allowedStatuses, true)) {
            return [null, ApiResponse::error(
                sprintf('A request that is %s cannot be changed this way.', strtolower($booking->status)),
                409
            )];
        }

        return [$booking, null];
    }

    private function listingFor(object $booking): object
    {
        return DB::table('land')->where('land_id', $booking->land_id)->first()
            ?? (object) ['land_id' => $booking->land_id, 'land_name' => null];
    }

    private function requesterList(Request $request, bool $includeExpired): JsonResponse
    {
        $query = $this->requestQuery()
            ->where('requests.requester_id', $this->userId($request));

        if (! $includeExpired) {
            $query->where('requests.status', '!=', 'Expired');
        }

        return $this->paginated($request, $query);
    }

    /** The shared request-with-listing query, ordered as the old API ordered it. */
    private function requestQuery()
    {
        return DB::table('requests')
            ->select(array_merge(
                self::REQUEST_COLUMNS,
                self::LISTING_COLUMNS,
                [DB::raw('GROUP_CONCAT(photos.photo_name) AS photos'), 'profile.phone'],
            ))
            ->join('land', 'requests.land_id', '=', 'land.land_id')
            ->leftJoin('photos', 'photos.land_id', '=', 'land.land_id')
            ->leftJoin('profile', 'profile.user_id', '=', 'land.owner')
            ->groupBy('requests.request_id', 'land.land_id')
            ->orderByDesc('requests.request_date')
            ->orderByDesc('requests.decision_at')
            ->orderByDesc('requests.checkedin_datetime')
            ->orderByDesc('requests.checkedout_datetime');
    }

    private function paginated(Request $request, $query): JsonResponse
    {
        $start = (int) $request->input('start', 0);
        $limit = (int) $request->input('limit', 0);

        if ($start > 0) {
            $query->offset($start);
        }

        if ($limit > 0) {
            $query->limit(min($limit, 500));
        }

        return ApiResponse::data(['items' => RequestRow::formatAll($query->get())]);
    }
}
