<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get("/health", fn () => response()->json([
    "ok" => true,
    "laravel" => app()->version(),
    "php" => PHP_VERSION,
    "db" => DB::connection()->getDatabaseName(),
]));
