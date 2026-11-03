<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * What counts as an API path.
 *
 * There is no `/api` prefix to look for — the subdomain carries that — so what
 * marks an API request is the version segment every endpoint sits behind.
 *
 * This lives in one place because two very different things ask the question
 * and must not drift apart: the exception handler in bootstrap/app.php, which
 * decides whether a 404 or a 405 answers in the API envelope rather than with
 * a framework HTML page, and the hygiene guards in
 * tests/Feature/ResponseHygieneTest.php, which select the routes they check.
 * If the guards' idea of an API route ever narrowed to nothing they would pass
 * while checking no routes at all, so they share this definition and assert
 * that it matched something.
 */
class ApiPath
{
    /** Whether an incoming request is addressed to the API. */
    public static function matches(Request $request): bool
    {
        return self::isApiUri($request->path());
    }

    /** Whether a route URI, as the router reports it, belongs to the API. */
    public static function isApiUri(string $uri): bool
    {
        return (bool) preg_match('#^v[0-9]+(/|$)#', ltrim($uri, '/'));
    }
}
