<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifies the caller when a token is supplied, and allows them through
 * when one is not.
 *
 * Only user/send-confirmation-code needs this: the app calls it both from the
 * profile screen, where there is a token, and from the sign-in screen, where
 * there is not. The endpoint answers a signed-in caller specifically and an
 * anonymous one uniformly, and this is what lets it tell them apart.
 *
 * Deliberately not an authentication fallback. A token that is present but
 * wrong leaves the caller anonymous rather than rejected, because rejecting
 * would make the endpoint report whether a token is valid to someone who is
 * allowed to call it without one. Nothing here grants access; the endpoint
 * decides what to do with whoever it turns out to be talking to.
 */
class IdentifyToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->token($request);

        if ($token !== '') {
            $user = User::where('auth_key', $token)->first();

            if ($user !== null && ! $user->isBlocked()) {
                $request->setUserResolver(fn () => $user);
                $request->attributes->set('api_user', $user);
            }
        }

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
