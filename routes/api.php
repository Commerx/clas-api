<?php

use App\Http\Controllers\Api\V1\ListingsController;
use App\Http\Controllers\Api\V1\NotificationsController;
use App\Http\Controllers\Api\V1\RequestsController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserSettingsController;
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
    });
});
