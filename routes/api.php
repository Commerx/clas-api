<?php

use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\FishingLandsController;
use App\Http\Controllers\Api\V1\HuntersTagsController;
use App\Http\Controllers\Api\V1\ListingsController;
use App\Http\Controllers\Api\V1\NotificationsController;
use App\Http\Controllers\Api\V1\RequestsController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserSettingsController;
use App\Http\Controllers\Api\V1\WmuController;
use Illuminate\Support\Facades\Route;

/*
 * The rebuilt API.
 *
 * This runs on its own subdomain and is consumed by a new app build; the old
 * domain keeps serving the old Yii application unchanged for builds already
 * in the field. So these endpoints are free to be consistent rather than
 * bug-compatible — see App\Support\V1\ListingRow and App\Http\ApiResponse for
 * what changed and why.
 *
 * The version restarts at v1: this is a different API from the old /api/v7,
 * not a successor to it, and sharing that number would make a logged
 * response impossible to attribute. There is no /api segment either, since
 * the subdomain already says so. Methods are now meaningful: a GET
 * endpoint is a GET.
 */
Route::prefix('v1')->group(function () {
    /*
     * Open endpoints.
     *
     * Everything here is reachable without a token, so each one is listed
     * deliberately and the hygiene test in tests/Feature/ResponseHygieneTest
     * holds this list as the complete set — adding a route outside the
     * guarded group below fails that test until it is named there too.
     *
     * The three that send email are rate limited per IP. The old API had no
     * limit on any of them, and two would send a message to any address
     * given, which makes them a way to have mail from this domain delivered
     * to someone who did not ask for it.
     */
    Route::post('security/login', [SecurityController::class, 'login']);
    Route::get('security/version-check', [SecurityController::class, 'versionCheck']);

    Route::middleware('throttle:6,1')->group(function () {
        Route::post('security/forgot-password', [SecurityController::class, 'forgotPassword']);
        Route::post('user/signup', [UserController::class, 'signup']);

        // Both signed in and not: see App\Http\Middleware\IdentifyToken.
        Route::post('user/send-confirmation-code', [UserController::class, 'sendConfirmationCode'])
            ->middleware('auth.identify');
    });

    Route::middleware('auth.token')->group(function () {
        Route::get('notifications/list', [NotificationsController::class, 'index']);
        Route::get('notifications/categories', [NotificationsController::class, 'categories']);
        Route::get('notifications/get-notification', [NotificationsController::class, 'show']);

        /*
         * Broadcasts. Who receives one is decided by the category, in
         * App\Services\NotificationBroadcast.
         *
         * subscriber-notification is owner-or-manager only; the old endpoint
         * let any caller post to any property's subscribers, which a
         * subscriber reads as coming from the property they followed.
         */
        Route::post('notifications/submit', [NotificationsController::class, 'submit']);
        Route::post('notifications/community-awareness', [NotificationsController::class, 'communityAwareness']);
        Route::post('notifications/subscriber-notification', [NotificationsController::class, 'subscriberNotification']);
        Route::post('notifications/hunting-alert', [NotificationsController::class, 'huntingAlert']);

        Route::post('notifications/read', [NotificationsController::class, 'markRead']);
        Route::post('notifications/delete-notification', [NotificationsController::class, 'destroy']);
        Route::post('notifications/report-notification', [NotificationsController::class, 'report']);

        Route::get('listings/all', [ListingsController::class, 'all']);
        Route::get('listings/geofence-listings', [ListingsController::class, 'geofenceListings']);
        Route::get('listings/first-nations-listings', [ListingsController::class, 'firstNationsListings']);
        Route::get('listings/search', [ListingsController::class, 'search']);
        Route::get('listings/detail', [ListingsController::class, 'detail']);
        Route::get('listings/availability', [ListingsController::class, 'availability']);
        Route::get('listings/get-blocked-days', [ListingsController::class, 'blockedDays']);
        Route::get('listings/sun-details', [ListingsController::class, 'sunDetails']);

        Route::get('requests/unread-counts', [RequestsController::class, 'unreadCounts']);
        Route::get('requests/list', [RequestsController::class, 'index']);
        Route::get('requests/mine', [RequestsController::class, 'mine']);
        Route::get('requests/bookings', [RequestsController::class, 'bookings']);
        Route::get('requests/listing-bookings', [RequestsController::class, 'listingBookings']);
        Route::get('requests/detail', [RequestsController::class, 'detail']);
        Route::get('requests/upcoming', [RequestsController::class, 'upcoming']);
        Route::get('requests/today-bookings', [RequestsController::class, 'todayBookings']);

        // Booking lifecycle. Decisions are owner-or-manager; the rest are the
        // requester's own. Rows written match the old application exactly.
        Route::post('requests/submit', [RequestsController::class, 'submit']);
        Route::post('requests/accept', [RequestsController::class, 'accept']);
        Route::post('requests/decline', [RequestsController::class, 'decline']);
        Route::post('requests/cancel', [RequestsController::class, 'cancel']);
        Route::post('requests/checkin', [RequestsController::class, 'checkin']);
        Route::post('requests/checkout', [RequestsController::class, 'checkout']);
        Route::post('requests/read', [RequestsController::class, 'markRead']);
        Route::post('requests/delete-request', [RequestsController::class, 'destroy']);

        /*
         * Writes. These share the clas database with the old application, so
         * the rows they create are identical to the ones it created — only
         * the responses and status codes differ.
         */
        Route::post('listings/subscribe', [ListingsController::class, 'subscribe']);
        Route::post('listings/unsubscribe', [ListingsController::class, 'unsubscribe']);
        Route::post('listings/block-day', [ListingsController::class, 'blockDay']);
        Route::post('listings/unblock-day', [ListingsController::class, 'unblockDay']);
        Route::post('listings/block-multiple-days', [ListingsController::class, 'blockMultipleDays']);

        Route::post('security/logout', [SecurityController::class, 'logout']);

        /*
         * The account.
         *
         * Every one of these acts on the caller the token identifies. The old
         * API's three update actions took the account from a `uid` parameter
         * and never checked the token against it — see
         * App\Http\Controllers\Api\V1\UserController.
         */
        Route::get('user/get', [UserController::class, 'get']);
        Route::post('user/update-address', [UserController::class, 'updateAddress']);
        Route::post('user/update-vehicle-info', [UserController::class, 'updateVehicleInfo']);
        Route::post('user/update-profile', [UserController::class, 'updateProfile']);
        Route::post('user/account', [UserController::class, 'account']);
        Route::post('user/deactivate', [UserController::class, 'deactivate']);
        Route::post('user/report-issue', [UserController::class, 'reportIssue']);

        Route::get('user-settings/get-notification-settings', [UserSettingsController::class, 'get']);
        Route::post('user-settings/save-notification-settings', [UserSettingsController::class, 'save']);

        /*
         * A hunter's tags, which are what a hunting alert matches on.
         *
         * The old controller's `list` and `categories` actions are not here:
         * both were copies of the Notifications endpoints of the same name
         * and both answer with a PHP fatal error on the live old API. See
         * App\Http\Controllers\Api\V1\HuntersTagsController.
         */
        Route::get('hunters-tags/get-all-tags', [HuntersTagsController::class, 'index']);
        Route::get('hunters-tags/get-tag', [HuntersTagsController::class, 'show']);
        Route::post('hunters-tags/add-tag', [HuntersTagsController::class, 'store']);
        Route::post('hunters-tags/update-tag', [HuntersTagsController::class, 'update']);
        Route::post('hunters-tags/delete-tag', [HuntersTagsController::class, 'destroy']);

        // Saved conversations with the in-app assistant: storage only.
        Route::get('chat/list', [ChatController::class, 'index']);
        Route::get('chat/get-history', [ChatController::class, 'show']);
        Route::post('chat/add-history', [ChatController::class, 'store']);
        Route::post('chat/update-history', [ChatController::class, 'update']);
        Route::post('chat/delete-history', [ChatController::class, 'destroy']);

        /*
         * Reference data. None of it is per-account; the token is required
         * only because the old API required one.
         */
        Route::get('wmu/games', [WmuController::class, 'games']);
        Route::get('wmu/game-season-details', [WmuController::class, 'gameSeasonDetails']);
        Route::get('wmu/species', [WmuController::class, 'species']);

        Route::get('fishing-lands/province-regulations', [FishingLandsController::class, 'index']);
        Route::get('fishing-lands/regulations', [FishingLandsController::class, 'show']);
    });
});
