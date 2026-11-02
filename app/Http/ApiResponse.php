<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;

/**
 * The single response envelope for the rebuilt API.
 *
 * The old API was inconsistent about this: most endpoints returned
 * {error, success, data}, get-blocked-days returned {success, error, total,
 * blocked_days} with no data wrapper, and every failure — including auth
 * failures — came back as HTTP 200 with success:false. Three different
 * strings meant "your token is wrong" depending on which action you called.
 *
 * The shape is kept deliberately close to the old one so the app's parsing
 * does not have to be reinvented, but it is now the same everywhere and the
 * HTTP status carries the outcome as well:
 *
 *   200  {"success": true,  "error": "", "data": {...}}
 *   401  {"success": false, "error": "...", "data": null}
 *   404  {"success": false, "error": "...", "data": null}
 *   422  {"success": false, "error": "...", "data": null, "errors": {...}}
 *
 * `error` stays a plain string because that is what the old clients showed to
 * the user; `errors` is the per-field detail, added rather than substituted.
 */
class ApiResponse
{
    public static function data(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'error' => '',
            'data' => $data,
        ], $status);
    }

    public static function error(string $message, int $status = 400, ?array $errors = null): JsonResponse
    {
        $body = [
            'success' => false,
            'error' => $message,
            'data' => null,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return new JsonResponse($body, $status);
    }

    /** A request that is missing or malforming parameters. */
    public static function invalid(array $errors): JsonResponse
    {
        return self::error(
            (string) (reset($errors)[0] ?? 'The request is invalid.'),
            422,
            $errors
        );
    }

    public static function unauthenticated(string $message = 'Invalid or expired token.'): JsonResponse
    {
        return self::error($message, 401);
    }

    public static function notFound(string $message = 'Not found.'): JsonResponse
    {
        return self::error($message, 404);
    }
}
