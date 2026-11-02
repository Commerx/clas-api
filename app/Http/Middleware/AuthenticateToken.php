<?php

namespace App\Http\Middleware;

use App\Http\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token authentication for the rebuilt API.
 *
 * The old API checked this inline in all 100 actions, with three different
 * error strings ("Invalid User!", "Invalid Request!", "Invalid user
 * credentials!") and HTTP 200 on failure. This is one check, one message,
 * and a 401 — so the app can tell an expired token from a server fault
 * without parsing prose.
 *
 * It also accepts the token from either the query string or the body. The old
 * API was split 37/49 between the two and rejected the wrong one, which was
 * a trap rather than a feature; a client should not have to remember which
 * endpoint wants it where. An Authorization: Bearer header is accepted too,
 * which is where this should end up.
 *
 * `uid` remains accepted for compatibility with existing callers but is no
 * longer required: the token alone identifies the user. When it is supplied
 * it must agree with the token, so a mismatched pair is still rejected.
 */
class AuthenticateToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->token($request);

        if ($token === '') {
            return ApiResponse::unauthenticated('A token is required.');
        }

        $user = User::where('auth_key', $token)->first();

        if ($user === null) {
            return ApiResponse::unauthenticated();
        }

        // Old clients send uid alongside the token. Honour it as a check
        // rather than as the lookup, so a stale pairing cannot authenticate.
        $uid = $request->input('uid');

        if ($uid !== null && $uid !== '' && (int) $uid !== (int) $user->id) {
            return ApiResponse::unauthenticated();
        }

        if ($user->isBlocked()) {
            return ApiResponse::unauthenticated('This account has been deactivated.');
        }

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_user', $user);

        return $next($request);
    }

    private function token(Request $request): string
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        return trim((string) $request->input('token', ''));
    }
}
