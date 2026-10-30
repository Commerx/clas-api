<?php

namespace App\Http\Controllers\Api\V7;

use App\Http\Controllers\Controller;
use App\Models\KeyFeature;
use App\Support\V7\ListingRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild of the read endpoints on
 * modules/api/controllers/v7/ListingsController.php.
 *
 * geofence-listings is the busiest route in the system — 931 hits in the
 * production logs for the sample window — so its response shape is pinned by
 * contract tests rather than trusted. The per-row quirks live in
 * App\Support\V7\ListingRow, which documents each one.
 *
 * Authentication is the auth.v7 middleware; all three of these read the token
 * from the query string, matching v7.
 */
class ListingsController extends Controller
{
    /** v7's select list for listings/all. */
    private const ALL_COLUMNS = [
        'land.land_id', 'land.land_name', 'land.land_description', 'land.address',
        'land.coordinates', 'land.land_lat', 'land.land_lng', 'land.geofencing',
        'land.source', 'land.is_rid_land', 'land.is_first_nations',
        'land.is_open_property', 'land.contact_email', 'land.barcode', 'land.owner',
        'land.key_features', 'land.features_list', 'land.fireban_status_id',
    ];

    /** geofence-listings asks for more: activities plus the geometry columns. */
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
        $query = $this->baseQuery(self::ALL_COLUMNS, $request)
            ->where('land.active', 'Y')
            ->where('land.visibility_status', 'public');

        return $this->listing($request, $query, withGeometry: false);
    }

    public function geofenceListings(Request $request): JsonResponse
    {
        $query = $this->baseQuery(self::GEOFENCE_COLUMNS, $request)
            ->where('land.active', 'Y')
            ->where('land.geofencing', 'Yes');

        return $this->listing($request, $query, withGeometry: true);
    }

    /**
     * No photos join, no grouping, no geometry and no `after` filter — v7
     * builds this one differently from the other two.
     */
    public function firstNationsListings(Request $request): JsonResponse
    {
        $query = DB::table('land')
            ->select(['land.land_id', 'land.source', 'land.kml', 'land.fn_land_code', 'land.kml_file_exists'])
            ->where('land.active', 'Y')
            ->where('land.is_first_nations', 1)
            ->where('land.source', 'FirstNationLandsAB')
            ->where('land.kml', '!=', '')
            ->orderByDesc('land.land_id');

        return $this->listing($request, $query, withGeometry: false);
    }

    private function baseQuery(array $columns, Request $request)
    {
        $after = $request->query('after', 0);

        return DB::table('land')
            ->select(array_merge($columns, [DB::raw('GROUP_CONCAT(photos.photo_name) AS photos')]))
            ->leftJoin('photos', 'photos.land_id', '=', 'land.land_id')
            ->where('updated_at', '>=', $after)
            ->groupBy('land.land_id')
            ->orderByDesc('land.land_id');
    }

    private function listing(Request $request, $query, bool $withGeometry): JsonResponse
    {
        $user = $request->attributes->get('v7_user');

        $this->rememberUserPosition($request, $user);

        // v7 applies these only when truthy, so start=0 and an absent limit
        // mean "no clause" rather than OFFSET 0 / LIMIT 0.
        $start = (int) $request->query('start', 0);
        $limit = $request->query('limit', '');

        if ($start) {
            $query->offset($start);
        }

        if ($limit !== '' && (int) $limit !== 0) {
            $query->limit((int) $limit);
        }

        $rows = $query->get();

        // v7 returns an empty items list without loading key features at all.
        if ($rows->isEmpty()) {
            return new JsonResponse([
                'error' => '',
                'success' => true,
                'data' => ['items' => []],
            ]);
        }

        $keyFeatures = KeyFeature::activeOrdered();

        $items = $rows
            ->map(fn ($row) => ListingRow::format((array) $row, $keyFeatures, $withGeometry))
            ->all();

        return new JsonResponse([
            'error' => '',
            'success' => true,
            'data' => ['items' => $items],
        ]);
    }

    /**
     * These endpoints double as a position report: when the caller sends
     * coordinates, v7 writes them to the user's profile.
     *
     * The validation is the fix applied to the Yii app in the SQL injection
     * remediation — the values used to be written unchecked, which made a
     * non-numeric latitude a 500 from a strict-mode column error.
     */
    private function rememberUserPosition(Request $request, object $user): void
    {
        $lat = $request->query('user_lat', 0);
        $lng = $request->query('user_lng', 0);

        if (! $lat || ! $lng) {
            return;
        }

        $lat = $this->coordinate($lat, 90);
        $lng = $this->coordinate($lng, 180);

        if ($lat === null || $lng === null) {
            return;
        }

        DB::table('profile')
            ->where('user_id', $user->id)
            ->update(['user_lat' => $lat, 'user_lng' => $lng]);
    }

    private function coordinate(mixed $value, float $max): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        return ($value < -$max || $value > $max) ? null : $value;
    }
}
