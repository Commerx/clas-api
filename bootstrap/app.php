<?php

use App\Http\ApiResponse;
use App\Http\Middleware\AuthenticateToken;
use App\Http\Middleware\DocsEnabled;
use App\Http\Middleware\IdentifyToken;
use App\Support\ApiPath;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        then: function () {
            Route::middleware('web')->group(base_path('routes/docs.php'));
        },
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.token' => AuthenticateToken::class,
            'auth.identify' => IdentifyToken::class,
            'docs.enabled' => DocsEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => ApiPath::matches($request) || $request->expectsJson(),
        );

        // An unknown path or a wrong method should answer in the same shape
        // as everything else, rather than with a framework page.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! ApiPath::matches($request)) {
                return null;
            }

            return ApiResponse::error(match ($e->getStatusCode()) {
                404 => 'No such endpoint.',
                405 => 'That method is not allowed on this endpoint.',
                default => $e->getMessage() ?: 'Request failed.',
            }, $e->getStatusCode());
        });
    })->create();
