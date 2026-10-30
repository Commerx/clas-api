<?php

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
    Route::any('security/login', [SecurityController::class, 'login']);
});
