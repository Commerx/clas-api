<?php

namespace App\Http\Controllers\Api\V7;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Support\V7\ListingAccess;
use App\Support\V7\RequestRow;
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

    private function userId(Request $request): int
    {
        return (int) $request->attributes->get('api_user')->id;
    }
}
