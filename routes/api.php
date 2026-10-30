<?php

use App\Http\Controllers\Api\V7\ListingsController;
use App\Http\Controllers\Api\V7\NotificationsController;
use App\Http\Controllers\Api\V7\SecurityController;
use Illuminate\Support\Facades\Route;

/*
 * The shipped mobile apps have /api/v7/... baked into them, so these paths are
 * fixed. The prefix comes from bootstrap/app.php, which strips nothing: the
 * URLs here must read exactly as v7 served them.
 *
 * v7 routed by Yii's controller/action convention, which turns
 * actionLogin on SecurityController into /api/v7/security/login, and
 * accepted any verb — the action itself checked for POST and returned
 * {"error":"Invalid Request!"} otherwise. `Route::any` preserves that,
 * rather than letting Laravel return a 405 the apps have never seen.
 */
Route::prefix('v7')->group(function () {
    // Unauthenticated: login issues the token the rest of these need.
    Route::any('security/login', [SecurityController::class, 'login']);

    /*
     * Authenticated. The middleware argument records where v7 read the token
     * from for that action — Yii's get() and post() do not fall through to
     * each other, so an endpoint that read the query string genuinely
     * rejected a token sent in the body. Check the original before porting.
     */
    Route::middleware('auth.v7:query')->group(function () {
        Route::any('notifications/list', [NotificationsController::class, 'index']);
        Route::any('notifications/categories', [NotificationsController::class, 'categories']);

        Route::any('listings/all', [ListingsController::class, 'all']);
        Route::any('listings/geofence-listings', [ListingsController::class, 'geofenceListings']);
        Route::any('listings/first-nations-listings', [ListingsController::class, 'firstNationsListings']);
    });
});
