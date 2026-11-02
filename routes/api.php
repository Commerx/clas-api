<?php

use App\Http\Controllers\Api\V7\ListingsController;
use App\Http\Controllers\Api\V7\NotificationsController;
use App\Http\Controllers\Api\V7\SecurityController;
use Illuminate\Support\Facades\Route;

/*
 * The rebuilt API.
 *
 * This runs on its own subdomain and is consumed by a new app build; the old
 * domain keeps serving the old Yii application unchanged for builds already
 * in the field. So these endpoints are free to be consistent rather than
 * bug-compatible — see App\Support\V7\ListingRow and App\Http\ApiResponse for
 * what changed and why.
 *
 * Paths are kept identical to the old ones so the app's only change is the
 * base URL. Methods are now meaningful: a GET endpoint is a GET.
 */
Route::prefix('v7')->group(function () {
    Route::post('security/login', [SecurityController::class, 'login']);

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
    });
});
