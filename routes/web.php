<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Readiness, not liveness.
 *
 * This is what deploy.sh checks, so it has to fail when the application is
 * running but unusable. Reporting the configured database name proves nothing:
 * a server pointed at the wrong schema answers that happily and then 500s on
 * every endpoint. So this queries a table the API actually depends on.
 *
 * Returns 503 when it cannot, which is what makes a bad deploy stop.
 */
Route::get('/health', function () {
    $checks = [];
    $ok = true;

    try {
        $checks['database'] = DB::connection()->getDatabaseName();
        // `land` is the table every listing endpoint reads. If this fails the
        // connection is up but pointed somewhere without the legacy schema.
        $checks['listings'] = DB::table('land')->count();
    } catch (Throwable $e) {
        $ok = false;
        $checks['error'] = $e->getMessage();
    }

    return response()->json([
        'ok' => $ok,
        'laravel' => app()->version(),
        'php' => PHP_VERSION,
        'env' => config('app.env'),
    ] + $checks, $ok ? 200 : 503);
});
