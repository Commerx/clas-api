<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Mail\ManagerInvited;
use App\Models\KeyFeature;
use App\Models\Rule;
use App\Services\ListingPhotos;
use App\Support\V1\FeatureList;
use App\Support\V1\ListingAccess;
use App\Support\V1\ManagedListingRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * A listing from its owner's side: creating it, editing it, publishing it.
 *
 * Three things are different here from the old controller, and they are the
 * reason this is the longest file in the API.
 *
 * **detail had no access check at all.** It took a land id, selected
 * `land.*`, and returned it to any caller with a valid token — for any
 * listing, owned by anyone. That published `claim_code`, the secret that lets
 * whoever holds it take ownership of a listing, for all 6,131 rows that have
 * one, along with the payment identifiers and the supersede email addresses.
 * Every endpoint here now goes through App\Support\V1\ListingAccess, and
 * `detail` answers 404 for a listing the caller does not manage.
 *
 * **A manager could appoint and remove managers.** Access throughout the old
 * controller was `owner OR any land_managers row`, with no check on the
 * invitation's status, so somebody invited and never accepted could add
 * further managers to a property that was not theirs and remove the ones who
 * were. add-manager and delete-manager are owner-only here; everything else
 * stays owner-or-manager, as before, but now only an *accepted* manager
 * counts.
 *
 * **Responses returned the whole row.** Every write answered with the full
 * ActiveRecord model, so each of those endpoints leaked what `detail` leaked.
 * ManagedListingRow is the allowlist, and it is what every response here is
 * built from.
 *
 * The signage order and payment endpoints — promocode, add-signages,
 * update-shipping-cost, update-payment — are not here. They move money and
 * belong with the payment work, once the Stripe decision is made.
 */
class ManageListingsController extends Controller
{
    /** Visibility a listing may be set to, as the old controller allowed. */
    private const VISIBILITY = ['public', 'private', 'inactive'];

    private const YES_NO = ['Yes', 'No'];

    // ---- reading -------------------------------------------------------

    /**
     * Every listing the caller owns or manages.
     *
     * Active and inactive both appear, because an owner still has to find a
     * listing they have taken down. `source IS NULL` keeps the imported
     * grazing-lease rows out, as before: those are not anybody's to manage.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->userId($request);

        $listings = DB::table('land')
            ->leftJoin('photos', 'photos.land_id', '=', 'land.land_id')
            ->leftJoin('land_managers', function ($join) use ($userId) {
                $join->on('land_managers.land_id', '=', 'land.land_id')
                    ->where('land_managers.user_id', '=', $userId)
                    ->where('land_managers.status', '=', 'accepted');
            })
            ->whereIn('land.active', ['Y', 'N'])
            ->whereNull('land.source')
            ->where(function ($q) use ($userId) {
                $q->where('land.owner', $userId)
                    ->orWhereNotNull('land_managers.user_id');
            })
            ->groupBy('land.land_id')
            ->orderByDesc('land.created_at')
            ->get(array_merge(ManagedListingRow::SUMMARY_COLUMNS, [
                DB::raw('GROUP_CONCAT(photos.photo_name) AS photos'),
            ]))
            ->map(fn ($row) => ManagedListingRow::format(
                (array) $row, KeyFeature::activeOrdered(), withGeometry: false,
            ))
            ->all();

        return ApiResponse::data(['items' => $listings]);
    }

    /**
     * One listing in full, for the management screens.
     *
     * This is the endpoint that had no ownership check. A listing the caller
     * does not manage answers 404, the same as one that does not exist.
     */
    public function show(Request $request): JsonResponse
    {
        $landId = $this->landId($request);

        if ($landId instanceof JsonResponse) {
            return $landId;
        }

        $row = DB::table('land')->where('land.land_id', $landId)
            ->first(ManagedListingRow::COLUMNS);

        if ($row === null) {
            return ApiResponse::notFound('No such listing.');
        }

        $item = ManagedListingRow::format((array) $row, KeyFeature::activeOrdered());

        $item['photos'] = DB::table('photos')
            ->where('land_id', $landId)
            ->where('active', 'Y')
            ->orderBy('photo_id')
            ->get(['photo_id', 'land_id', 'photo_name', 'is_titlephoto', 'size', 'type'])
            ->map(fn ($photo) => ManagedListingRow::photo($photo))
            ->all();

        $item['signages'] = $this->signages($landId);
        $item['managers'] = $this->managers($landId);

        $item['is_subscribed'] = DB::table('land_notification_subscriptions')
            ->where('land_id', $landId)
            ->where('user_id', $this->userId($request))
            ->exists();

        return ApiResponse::data(['item' => $item]);
    }

    /**
     * Which days are blocked, and how full the rest are.
     *
     * `percent_filled` buckets each day to 25, 50, 75 or 100 against
     * people_allowed, as before. The old version divided by people_allowed
     * without checking it: a listing created through `add` starts at 0, so
     * the first request against a listing whose limit was never set divided
     * by zero. A limit of zero now reports every requested day as full,
     * which is what a limit of zero means.
     */
    public function availability(Request $request): JsonResponse
    {
        $landId = $this->landId($request);

        if ($landId instanceof JsonResponse) {
            return $landId;
        }

        $allowed = (int) DB::table('land')->where('land_id', $landId)->value('people_allowed');

        $counts = DB::table('requests')
            ->where('land_id', $landId)
            ->whereIn('status', ['Pending', 'Accepted', 'Checked In', 'Checked Out'])
            ->where('request_date', '>=', now()->format('Y-m-d'))
            ->groupBy('request_date')
            ->pluck(DB::raw('COUNT(*)'), 'request_date');

        $filled = [];

        foreach ($counts as $date => $count) {
            $percent = $allowed > 0 ? ceil(($count / $allowed) * 100) : 100;

            $filled[$date] = match (true) {
                $percent >= 100 => '100',
                $percent > 50 => '75',
                $percent > 25 => '50',
                default => '25',
            };
        }

        $blocked = DB::table('land_blocked_days')
            ->where('land_id', $landId)
            ->where('blocked_date', '>=', now()->format('Y-m-d'))
            ->orderBy('blocked_date')
            ->pluck('blocked_date')
            ->all();

        return ApiResponse::data([
            'blocked' => $blocked,
            // Always an object, keyed by date. An empty PHP array encodes as
            // [], so a client typing this field would see its type change
            // between a listing with requests and one without.
            'percent_filled' => (object) $filled,
        ]);
    }

    // ---- the listing lifecycle -----------------------------------------

    /**
     * Start a listing.
     *
     * The caller becomes the owner; `visibility_status` is only honoured for
     * an account with a dark mode subscription, as before.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'land_name' => ['required', 'string', 'max:255'],
            'land_description' => ['required', 'string', 'max:20000'],
            'land_keywords' => ['nullable', 'string', 'max:1000'],
            'visibility_status' => ['nullable', 'string', 'in:'.implode(',', self::VISIBILITY)],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $userId = $this->userId($request);
        $now = time();

        $attributes = [
            'land_name' => trim((string) $request->input('land_name')),
            'land_keywords' => trim((string) $request->input('land_keywords', '')),
            'land_description' => trim((string) $request->input('land_description')),
            'owner' => $userId,
            'people_allowed' => 0,
            'barcode' => $this->barcode(),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($this->mayChooseVisibility($request, $userId)) {
            $attributes['visibility_status'] = (string) $request->input('visibility_status');
        }

        $landId = (int) DB::table('land')->insertGetId($attributes);

        return $this->listingResponse($landId, 201);
    }

    /** Name, keywords and description. */
    public function updateDescription(Request $request): JsonResponse
    {
        $landId = $this->landId($request, post: true);

        if ($landId instanceof JsonResponse) {
            return $landId;
        }

        $validator = Validator::make($request->all(), [
            'land_name' => ['required', 'string', 'max:255'],
            'land_description' => ['required', 'string', 'max:20000'],
            'land_keywords' => ['nullable', 'string', 'max:1000'],
            'visibility_status' => ['nullable', 'string', 'in:'.implode(',', self::VISIBILITY)],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $attributes = [
            'land_name' => trim((string) $request->input('land_name')),
            'land_keywords' => trim((string) $request->input('land_keywords', '')),
            'land_description' => trim((string) $request->input('land_description')),
        ];

        if ($this->mayChooseVisibility($request, $this->userId($request))) {
            $attributes['visibility_status'] = (string) $request->input('visibility_status');
        }

        return $this->write($landId, $attributes);
    }

    /** Address, coordinates and boundary. */
    public function updateLocation(Request $request): JsonResponse
    {
        $landId = $this->landId($request, post: true);

        if ($landId instanceof JsonResponse) {
            return $landId;
        }

        $validator = Validator::make($request->all(), [
            'address' => ['required', 'string', 'max:500'],
            'coordinates' => ['required', 'string', 'max:100'],
            'geofencing' => ['nullable', 'string', 'in:'.implode(',', self::YES_NO)],
            'total_deeded' => ['nullable', 'numeric'],
            'area' => ['nullable', 'string'],
            'points' => ['nullable', 'string'],
            'kml_data' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $coordinates = trim((string) $request->input('coordinates'));
        $parts = array_map('trim', explode(',', $coordinates));

        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return ApiResponse::invalid([
                'coordinates' => ['Coordinates must be a latitude and longitude, comma separated.'],
            ]);
        }

        $attributes = [
            'address' => trim((string) $request->input('address')),
            'coordinates' => $coordinates,
            'land_lat' => (float) $parts[0],
            'land_lng' => (float) $parts[1],
            'geofencing' => (string) $request->input('geofencing', 'No'),
            'total_deeded' => $request->input('total_deeded'),
        ];

        // A KML boundary and a hand-drawn one are alternatives: supplying KML
        // clears the drawn area and points, as before.
        if ($request->filled('kml_data')) {
            $attributes['kml'] = (string) $request->input('kml_data');
            $attributes['area'] = '';
            $attributes['points'] = '';
        } else {
            if ($request->has('area')) {
                $attributes['area'] = (string) $request->input('area');
            }

            if ($request->has('points')) {
                $attributes['points'] = (string) $request->input('points');
            }
        }

        return $this->write($landId, $attributes);
    }

    /** The feature checkboxes and the activity rules. */
    public function updateFeaturesRules(Request $request): JsonResponse
    {
        $landId = $this->landId($request, post: true);

        if ($landId instanceof JsonResponse) {
            return $landId;
        }

        $posted = $request->input('key_features', []);

        if (! is_array($posted) || $posted === []) {
            return ApiResponse::invalid([
                'key_features' => ['Please select your property features.'],
            ]);
        }

        $selection = FeatureList::selection($posted);
        $keyFeatures = KeyFeature::activeOrdered();

        $known = $keyFeatures->pluck('feature_id')->map(fn ($id) => (int) $id)->all();
        $unknown = array_diff(FeatureList::featureIds($selection), $known);

        if (FeatureList::featureIds($selection) === [] || $unknown !== []) {
            return ApiResponse::invalid([
                'key_features' => ['One of those features does not exist.'],
            ]);
        }

        $attributes = [
            'key_features' => $selection,
            'features_list' => FeatureList::html($selection, $keyFeatures),
        ];

        $activities = $request->input('allowed_activities', []);

        if (is_array($activities) && $activities !== []) {
            $selected = implode(',', array_map(fn ($entry) => (string) $entry, $activities));

            $attributes['allowed_activities'] = $selected;
            $attributes['allowed_activities_text'] = FeatureList::rulesHtml(
                $selected,
                Rule::orderBy('rule_id')->get(['rule_id', 'rule']),
            );
        }

        return $this->write($landId, $attributes);
    }

    /** Party size, auto-approval, guides and the supersede addresses. */
    public function updateClasControls(Request $request): JsonResponse
    {
        $landId = $this->landId($request, post: true);

        if ($landId instanceof JsonResponse) {
            return $landId;
        }

        $yesNo = ['nullable', 'string', 'in:'.implode(',', self::YES_NO)];

        $validator = Validator::make($request->all(), [
            'people_allowed' => ['required', 'integer', 'min:0', 'max:10000'],
            'auto_approve_requests' => $yesNo,
            'allow_unlimited_access' => $yesNo,
            'allow_guide' => $yesNo,
            'guide_1_auto_approval' => $yesNo,
            'guide_2_auto_approval' => $yesNo,
            'guide_3_auto_approval' => $yesNo,
            'guide_number_1' => ['nullable', 'string', 'max:60'],
            'guide_number_2' => ['nullable', 'string', 'max:60'],
            'guide_number_3' => ['nullable', 'string', 'max:60'],
            // These receive a copy of every request for the listing, so they
            // are real addresses and the old endpoint never checked them.
            'supersede_emails_1' => ['nullable', 'email', 'max:255'],
            'supersede_emails_2' => ['nullable', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $attributes = [
            'people_allowed' => (int) $request->input('people_allowed'),
            'auto_approve_requests' => (string) $request->input('auto_approve_requests', 'No'),
            'allow_unlimited_access' => (string) $request->input('allow_unlimited_access', 'No'),
            'allow_guide' => (string) $request->input('allow_guide', 'No'),
            'supersede_emails_1' => $request->input('supersede_emails_1'),
            'supersede_emails_2' => $request->input('supersede_emails_2'),
        ];

        foreach ([1, 2, 3] as $slot) {
            $attributes['guide_number_'.$slot] = $request->input('guide_number_'.$slot);
            $attributes['guide_'.$slot.'_auto_approval'] =
                (string) $request->input('guide_'.$slot.'_auto_approval', 'No');
        }

        return $this->write($landId, $attributes);
    }

    /**
     * Choose between a geofence-only listing and physical signage.
     *
     * Type 1 is the geofence, which costs nothing, so it marks the listing
     * paid and turns the geofence on. Type 2 means signs, which start a sign
     * order; the order itself and its payment are group 7.
     *
     * Worth stating plainly: switching a listing back to type 1 marks it
     * Completed and clears the promocode, discount and shipping method, but
     * leaves any `land_signages` rows and the amounts on the listing alone.
     * The old endpoint did the same. So a listing can end up marked paid with
     * an unpaid sign order still attached to it, and settling that belongs
     * with the payment work rather than here.
     */
    public function updateSignage(Request $request): JsonResponse
    {
        $landId = $this->landId($request, post: true);

        if ($landId instanceof JsonResponse) {
            return $landId;
        }

        $validator = Validator::make($request->all(), [
            'signage_type' => ['required', 'integer', 'in:1,2'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $type = (int) $request->input('signage_type');

        $attributes = [
            'signage_type' => $type,
            'signage_promocode' => '',
            'signage_discount_amount' => 0.00,
            'signage_shipping_method' => '',
        ];

        if ($type === 1) {
            $attributes['required_signages'] = 0;
            $attributes['geofencing'] = 'Yes';
            $attributes['payment_status'] = 'Completed';
        } else {
            $attributes['required_signages'] = 1;
        }

        return $this->write($landId, $attributes);
    }

    /** Remove one line from the sign order and restate the totals. */
    public function deleteSignage(Request $request): JsonResponse
    {
        $landId = $this->landId($request, post: true);

        if ($landId instanceof JsonResponse) {
            return $landId;
        }

        $validator = Validator::make($request->all(), [
            'signage_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $signageId = (int) $request->input('signage_id');

        $exists = DB::table('land_signages')
            ->where('id', $signageId)
            ->where('land_id', $landId)
            ->exists();

        if (! $exists) {
            return ApiResponse::notFound('No such signage on this listing.');
        }

        DB::transaction(function () use ($landId, $signageId) {
            DB::table('land_signages')->where('id', $signageId)->delete();

            $remaining = DB::table('land_signages')->where('land_id', $landId)
                ->get(['qty', 'price']);

            DB::table('land')->where('land_id', $landId)->update([
                'required_signages' => (int) $remaining->sum('qty'),
                'signage_amount' => (float) $remaining->sum(fn ($row) => $row->price * $row->qty),
                'signage_promocode' => '',
                'signage_discount_amount' => 0.00,
                'signage_shipping_method' => '',
                'updated_at' => time(),
            ]);
        });

        return $this->listingResponse($landId);
    }

    /** Where physical signs should be sent. */
    public function updateShippingInfo(Request $request): JsonResponse
    {
        $landId = $this->landId($request, post: true);

        if ($landId instanceof JsonResponse) {
            return $landId;
        }

        $validator = Validator::make($request->all(), [
            'street' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'province' => ['required', 'string', 'max:60'],
            'country' => ['required', 'string', 'max:60'],
            'postal_code' => ['required', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        // The column holds the whole address as JSON, which is how the old
        // endpoint wrote it and how the web admin reads it.
        return $this->write($landId, [
            'signage_shipping_info' => json_encode([
                'street' => (string) $request->input('street'),
                'city' => (string) $request->input('city'),
                'province' => (string) $request->input('province'),
                'country' => (string) $request->input('country'),
                'postal_code' => (string) $request->input('postal_code'),
                'phone' => (string) $request->input('phone', ''),
            ]),
        ]);
    }

    /**
     * Make a listing visible.
     *
     * Only a paid listing that is not already live can be published, as
     * before — but the old endpoint answered "Invalid Request!" for both
     * halves of that, so an owner could not tell an unpaid listing from one
     * already published. The two now say which.
     */
    public function publish(Request $request): JsonResponse
    {
        $landId = $this->landId($request, post: true);

        if ($landId instanceof JsonResponse) {
            return $landId;
        }

        $listing = DB::table('land')->where('land_id', $landId)
            ->first(['payment_status', 'active', 'visibility_status']);

        if ($listing->payment_status !== 'Completed') {
            return ApiResponse::error('This listing has not been paid for yet.', 409);
        }

        if ($listing->active === 'Y') {
            return ApiResponse::error('This listing is already published.', 409);
        }

        $attributes = ['active' => 'Y', 'status' => 'active'];

        if ($listing->visibility_status === 'inactive') {
            $attributes['visibility_status'] = 'public';
        }

        return $this->write($landId, $attributes);
    }

    /** Take a listing down without deleting it. */
    public function unpublish(Request $request): JsonResponse
    {
        $landId = $this->landId($request, post: true);

        if ($landId instanceof JsonResponse) {
            return $landId;
        }

        return $this->write($landId, [
            'active' => 'N',
            'status' => 'inactive',
            'visibility_status' => 'inactive',
        ]);
    }

    /**
     * Delete a listing, which marks it rather than removing the row.
     *
     * Owner only, as before — a manager cannot delete somebody's property.
     * The row stays so that the requests and bookings against it keep their
     * reference.
     */
    public function destroy(Request $request): JsonResponse
    {
        $landId = $this->landId($request, post: true, ownerOnly: true);

        if ($landId instanceof JsonResponse) {
            return $landId;
        }

        DB::table('land')->where('land_id', $landId)->update([
            'active' => 'DELETED',
            'updated_at' => time(),
        ]);

        return ApiResponse::data(['deleted' => true]);
    }

    // ---- photos --------------------------------------------------------

    /**
     * Add a photo.
     *
     * The upload has to be a real image of an allowed type and within the
     * size limit before anything is written; App\Services\ListingPhotos has
     * what the old endpoint did instead, and why it mattered.
     *
     * The first photo on a listing becomes its title photo, as before.
     */
    public function uploadPhoto(Request $request, ListingPhotos $photos): JsonResponse
    {
        $landId = $this->landId($request, post: true);

        if ($landId instanceof JsonResponse) {
            return $landId;
        }

        $validator = Validator::make($request->all(), [
            // `file` only: the mime and extension checks happen on the bytes.
            'image' => ['required', 'file'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        try {
            $stored = $photos->store($request->file('image'), $landId);
        } catch (RuntimeException $e) {
            return ApiResponse::invalid(['image' => [$e->getMessage()]]);
        }

        $isFirst = ! DB::table('photos')->where('land_id', $landId)->exists();

        $photoId = (int) DB::table('photos')->insertGetId([
            'land_id' => $landId,
            'photo_name' => $stored['name'],
            // The column is a blob that the old code filled with the client's
            // original filename rather than the image; kept, because the web
            // admin reads it and the column is NOT NULL.
            'photo' => $request->file('image')->getClientOriginalName(),
            'size' => $stored['size'],
            'type' => $stored['type'],
            'is_titlephoto' => $isFirst ? 'Y' : 'N',
        ]);

        $photo = DB::table('photos')->where('photo_id', $photoId)
            ->first(['photo_id', 'land_id', 'photo_name', 'is_titlephoto', 'size', 'type']);

        return ApiResponse::data(['item' => ManagedListingRow::photo($photo)], 201);
    }

    /**
     * Remove a photo.
     *
     * The row goes whether or not the file is still on disk. The old endpoint
     * refused when the original was missing, so a row left behind by a failed
     * upload could not be cleared through the API at all.
     */
    public function deletePhoto(Request $request, ListingPhotos $photos): JsonResponse
    {
        $landId = $this->landId($request, post: true);

        if ($landId instanceof JsonResponse) {
            return $landId;
        }

        $validator = Validator::make($request->all(), [
            'photo_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $photo = DB::table('photos')
            ->where('photo_id', (int) $request->input('photo_id'))
            ->where('land_id', $landId)
            ->first(['photo_id', 'photo_name', 'is_titlephoto']);

        if ($photo === null) {
            return ApiResponse::notFound('No such photo on this listing.');
        }

        DB::transaction(function () use ($photo, $landId) {
            DB::table('photos')->where('photo_id', $photo->photo_id)->delete();

            // Keep a title photo: the old endpoint left a listing with none
            // when the title photo was the one removed.
            if ($photo->is_titlephoto === 'Y') {
                $next = DB::table('photos')->where('land_id', $landId)
                    ->orderBy('photo_id')->value('photo_id');

                if ($next !== null) {
                    DB::table('photos')->where('photo_id', $next)
                        ->update(['is_titlephoto' => 'Y']);
                }
            }
        });

        $photos->remove($photo->photo_name, $landId);

        return ApiResponse::data(['deleted' => true]);
    }

    // ---- managers ------------------------------------------------------

    /**
     * Invite somebody to manage the listing.
     *
     * Owner only. In the old API this was owner-or-manager with no check on
     * the invitation's status, so anybody who had been invited — accepted or
     * not — could appoint further managers to a property that was not theirs.
     *
     * The invitation is answered in the web application, which requires the
     * invited person to be signed in as the account the invitation names. That
     * account is resolved here, when the invitation is sent, so an invitation
     * to an address with no account yet can never be accepted even after they
     * sign up: 4 of the 7 pending invitations are in that state. Fixing it
     * needs an acceptance endpoint of this API's own, which is noted in
     * docs/build-order.md rather than guessed at here.
     */
    public function addManager(Request $request): JsonResponse
    {
        $landId = $this->landId($request, post: true, ownerOnly: true);

        if ($landId instanceof JsonResponse) {
            return $landId;
        }

        $validator = Validator::make($request->all(), [
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'user_email' => ['required', 'email', 'max:100'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $email = (string) $request->input('user_email');

        $already = DB::table('land_managers')
            ->where('land_id', $landId)
            ->where('user_email', $email)
            ->exists();

        if ($already) {
            return ApiResponse::error('That person has already been invited to this listing.', 409);
        }

        $listing = DB::table('land')->where('land_id', $landId)->first(['land_name', 'owner']);
        $ownerName = $this->displayName((int) $listing->owner);

        $managerId = (int) DB::table('land_managers')->insertGetId([
            'land_id' => $landId,
            'first_name' => (string) $request->input('first_name'),
            'last_name' => (string) $request->input('last_name'),
            'user_email' => $email,
            'user_id' => DB::table('user')->where('email', $email)->value('id'),
            'status' => 'pending',
        ]);

        Mail::to($email)->send(new ManagerInvited(
            managerId: $managerId,
            managerName: trim($request->input('first_name').' '.$request->input('last_name')),
            ownerName: $ownerName,
            listingName: (string) $listing->land_name,
        ));

        return ApiResponse::data([
            'id' => $managerId,
            'managers' => $this->managers($landId),
        ], 201);
    }

    /** Withdraw an invitation, or remove a manager. Owner only. */
    public function deleteManager(Request $request): JsonResponse
    {
        $landId = $this->landId($request, post: true, ownerOnly: true);

        if ($landId instanceof JsonResponse) {
            return $landId;
        }

        $validator = Validator::make($request->all(), [
            'manager_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $removed = DB::table('land_managers')
            ->where('id', (int) $request->input('manager_id'))
            ->where('land_id', $landId)
            ->delete();

        if ($removed === 0) {
            return ApiResponse::notFound('No such manager on this listing.');
        }

        return ApiResponse::data(['managers' => $this->managers($landId)]);
    }

    // ---- shared --------------------------------------------------------

    /**
     * The listing the request names, once the caller's access is established.
     *
     * Returns the id, or the response to send instead. Both "no such listing"
     * and "not yours" answer 404, so the API does not confirm that a land id
     * is in use to somebody who cannot manage it.
     */
    private function landId(Request $request, bool $post = false, bool $ownerOnly = false): int|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $landId = (int) $request->input('id');
        $userId = $this->userId($request);

        $allowed = $ownerOnly
            ? ListingAccess::isOwner($userId, $landId)
            : ListingAccess::canManage($userId, $landId);

        if (! $allowed) {
            return ApiResponse::notFound('No such listing.');
        }

        return $landId;
    }

    /** Apply a change and answer with the listing. */
    private function write(int $landId, array $attributes): JsonResponse
    {
        $attributes['updated_at'] = time();

        DB::table('land')->where('land_id', $landId)->update($attributes);

        return $this->listingResponse($landId);
    }

    private function listingResponse(int $landId, int $status = 200): JsonResponse
    {
        $row = DB::table('land')->where('land.land_id', $landId)
            ->first(ManagedListingRow::COLUMNS);

        $item = ManagedListingRow::format((array) $row, KeyFeature::activeOrdered());
        $item['signages'] = $this->signages($landId);
        $item['managers'] = $this->managers($landId);

        return ApiResponse::data(['item' => $item], $status);
    }

    /** @return list<array<string,mixed>> */
    private function managers(int $landId): array
    {
        return DB::table('land_managers')
            ->where('land_id', $landId)
            ->orderBy('id')
            ->get(['id', 'first_name', 'last_name', 'user_email', 'user_id', 'status', 'created'])
            ->map(fn ($manager) => ManagedListingRow::manager($manager))
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function signages(int $landId): array
    {
        return DB::table('land_signages')
            ->where('land_id', $landId)
            ->orderBy('id')
            ->get(['id', 'signage_type', 'qty', 'price', 'created_at'])
            ->map(fn ($signage) => ManagedListingRow::signage($signage))
            ->all();
    }

    /**
     * Whether this caller may set the visibility.
     *
     * A private or inactive listing is a dark mode feature, so the choice is
     * only honoured for an account that holds that subscription — as before.
     */
    private function mayChooseVisibility(Request $request, int $userId): bool
    {
        if (! $request->filled('visibility_status')) {
            return false;
        }

        return DB::table('darkmode_subscription')
            ->where('user_id', $userId)
            ->where('status', 'Active')
            ->exists();
    }

    /** The owner's name for the invitation mail. */
    private function displayName(int $userId): string
    {
        $profile = DB::table('profile')->where('user_id', $userId)
            ->first(['name', 'first_name', 'last_name']);

        if ($profile === null) {
            return (string) DB::table('user')->where('id', $userId)->value('username');
        }

        $name = trim((string) $profile->name);

        return $name !== ''
            ? $name
            : trim($profile->first_name.' '.$profile->last_name);
    }

    /**
     * A barcode no other listing has.
     *
     * The old helper looped until `Land::find()->where(['barcode' => $code])`
     * came back empty, with nothing stopping two requests picking the same
     * code between the check and the insert. Twelve random bytes make that
     * not worth guarding against, and the loop is kept for the same reason
     * the old one existed.
     */
    private function barcode(): string
    {
        do {
            $code = strtoupper(bin2hex(random_bytes(6)));
        } while (DB::table('land')->where('barcode', $code)->exists());

        return $code;
    }
}
