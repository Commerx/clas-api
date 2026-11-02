<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the API documentation off production unless it is asked for.
 *
 * The spec describes every endpoint and parameter, which is exactly what a
 * reviewer wants and exactly what an attacker wants. Open in local and
 * staging; on production it needs DOCS_ENABLED=true set deliberately.
 */
class DocsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = config('app.env') !== 'production' || config('app.docs_enabled');

        abort_unless($enabled, 404);

        return $next($request);
    }
}
