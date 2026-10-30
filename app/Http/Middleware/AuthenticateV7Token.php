<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reproduces the auth check that every v7 endpoint performs inline.
 *
 * v7 has no middleware: each action reads `token` and `uid`, validates they
 * are present, then looks for a user matching both. This centralises that,
 * including the exact error strings and the fact that a failure is HTTP 200
 * with an error body rather than a 401.
 *
 * The source matters. Yii's Request::get() reads the query string only and
 * post() reads the body only, with no fall-through, so an endpoint that reads
 * the token from the query genuinely rejects a token sent in the body. v7 is
 * split roughly 37 query to 49 body across its actions, so each route declares
 * which it uses:
 *
 *     ->middleware('auth.v7:query')   // token and uid from the query string
 *     ->middleware('auth.v7:body')    // token and uid from the POST body
 *
 * Accepting either would be more permissive than v7 and would hide the
 * difference instead of preserving it.
 */
class AuthenticateV7Token
{
    public function handle(Request $request, Closure $next, string $source = 'query'): Response
    {
        [$token, $uid] = $this->credentials($request, $source);

        // v7 validates auth_key before uid, and reports only the first error.
        // The labels are Yii's: auth_key renders as "Auth Key", uid as "Uid".
        if (trim((string) $token) === '') {
            return $this->failure('Auth Key cannot be blank.');
        }

        if (trim((string) $uid) === '') {
            return $this->failure('Uid cannot be blank.');
        }

        // v7: User::findOne(['auth_key' => $auth_key, 'id' => $uid]).
        // uid is cast to int here. MySQL coerces a non-numeric string to 0 in
        // this comparison anyway, so the match is unchanged, but casting keeps
        // a tainted value from travelling further as a string — the old code
        // went on to concatenate $uid straight into later SQL.
        $user = User::where('auth_key', (string) $token)
            ->where('id', (int) $uid)
            ->first();

        if ($user === null) {
            return $this->failure('Invalid User!');
        }

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('v7_user', $user);

        return $next($request);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function credentials(Request $request, string $source): array
    {
        if ($source === 'body') {
            return [$request->post('token'), $request->post('uid')];
        }

        return [$request->query('token'), $request->query('uid')];
    }

    private function failure(string $message): JsonResponse
    {
        // Deliberately 200: v7 returned its auth failures in the body, and the
        // shipped apps branch on `success`, not on the status code.
        return new JsonResponse(['error' => $message, 'success' => false]);
    }
}
