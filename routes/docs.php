<?php

use App\Http\Docs\OpenApi;
use Illuminate\Support\Facades\Route;

/*
 * Interactive API documentation.
 *
 * Served from the application itself rather than as a static page, so the
 * "Test request" button issues same-origin calls and actually works. A page
 * hosted elsewhere could show the spec but could not call the API.
 *
 * Disabled when APP_ENV is production unless DOCS_ENABLED is set, so the
 * schema is not published by accident.
 */
Route::middleware('docs.enabled')->group(function () {
    Route::get('docs/openapi.json', fn () => response()->json(OpenApi::spec()))
        ->name('docs.spec');

    Route::get('docs', fn () => view('docs', [
        // Changes whenever the spec source does, so the browser refetches.
        'specVersion' => substr(md5_file(app_path('Http/Docs/OpenApi.php')), 0, 8),
    ]))->name('docs');
});
