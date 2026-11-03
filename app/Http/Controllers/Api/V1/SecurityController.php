<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Mail\ResetPassword;
use App\Models\AppVersion;
use App\Models\DeviceToken;
use App\Models\KeyFeature;
use App\Models\Rule;
use App\Models\Token;
use App\Models\User;
use App\Support\V1\AccountPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * Rebuild of modules/api/controllers/v7/SecurityController.php.
 *
 * Sign-in, sign-out, password recovery, and the reference data the app
 * downloads when it starts.
 *
 * The account block these return is shared with signup and user/get — see
 * App\Support\V1\AccountPayload, which is the single definition of it. The old
 * API wrote it out separately in each action and the three had drifted.
 */
class SecurityController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $login = (string) $request->input('login', '');
        $password = (string) $request->input('password', '');
        $os = (string) $request->input('os', '');
        $deviceToken = (string) $request->input('device_token', '');

        $errors = [];

        foreach (['login' => $login, 'password' => $password, 'os' => $os] as $field => $value) {
            if (trim($value) === '') {
                $errors[$field] = [ucfirst($field).' is required.'];
            }
        }

        if ($errors !== []) {
            return ApiResponse::invalid($errors);
        }

        $user = User::findByLogin(trim($login));

        if ($user === null || ! $user->validatePassword($password)) {
            return ApiResponse::unauthenticated('Invalid login or password.');
        }

        if (! $user->allowsGuest()) {
            if (! $user->isConfirmed()) {
                return ApiResponse::error(
                    'Your account is not activated. Request a new confirmation link to verify your email.',
                    403
                );
            }

            if ($user->isBlocked()) {
                return ApiResponse::error(
                    'Your account has been deactivated. Please contact support.',
                    403
                );
            }
        }

        $this->rememberDeviceToken($user, $os, $deviceToken);

        return ApiResponse::data(AccountPayload::identity($user));
    }

    /**
     * Forget this device, so it stops receiving push notifications.
     *
     * What this does not do is invalidate the token, and that is worth being
     * explicit about. `auth_key` is one value per account, shared by every
     * signed-in device and by the old API, so clearing it here would sign the
     * user out everywhere including on app builds still talking to the old
     * host. Until that host is retired, signing out is a client-side act and
     * this endpoint's job is only to stop the push messages.
     *
     * Deactivating an account does rotate the token — see user/deactivate —
     * and so does changing a password.
     */
    public function logout(Request $request): JsonResponse
    {
        $os = strtolower((string) $request->input('os', ''));
        $deviceToken = (string) $request->input('device_token', '');

        if (! in_array($os, ['ios', 'android'], true)) {
            return ApiResponse::invalid(['os' => ['os must be ios or android.']]);
        }

        $query = DeviceToken::where('user_id', $this->userId($request))->where('os', $os);

        // The old endpoint required the exact device_token and silently did
        // nothing when it did not match, so a client that had lost track of
        // its token could not stop its own push messages. Without one, forget
        // every token for that OS.
        if ($deviceToken !== '') {
            $query->where('device_token', $deviceToken);
        }

        return ApiResponse::data(['forgotten' => $query->delete()]);
    }

    /**
     * Start a password reset.
     *
     * Always reports success. The old endpoint returned dektrium's validation
     * error, which said "There is no user with this email address" — so anyone
     * could check whether an address had an account here by asking. The reply
     * is now the same either way and the difference is only whether a message
     * is sent.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $user = User::where('email', trim((string) $request->input('email')))->first();

        if ($user !== null && ! $user->isBlocked()) {
            $token = Token::issue((int) $user->id, Token::TYPE_RECOVERY);

            try {
                Mail::to($user->email)->send(new ResetPassword(
                    name: (string) ($user->profile?->name ?: $user->username),
                    url: $token->url(),
                    hours: (int) round(((int) config('clas.recover_within')) / 3600),
                ));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return ApiResponse::data([
            'message' => 'If that email address has an account, a reset link is on its way.',
        ]);
    }

    /**
     * Whether the caller's build is current, plus the app's reference data.
     *
     * Unauthenticated, because the app calls it before anyone has signed in.
     *
     * The reference lists changed shape. The old API built them as PHP arrays
     * keyed by their own values, so they serialised as objects —
     * {"Hunting":"Hunting"} — and signage_options came back keyed "1" to "4".
     * They are arrays here. Two category names were also cleaned: one carried
     * a stray leading character and another a trailing tab.
     */
    public function versionCheck(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'os' => ['required', 'string'],
            'version' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $os = (string) $request->input('os');
        $app = AppVersion::forOs($os);

        if ($app === null || ! $app->status) {
            return ApiResponse::notFound('No such app platform.');
        }

        $hasUpdate = $app->hasUpdateFrom((string) $request->input('version'));

        return ApiResponse::data([
            'hasUpdate' => $hasUpdate,
            'current_version' => (string) $app->version_number,

            // The old API returned `message` only when an update existed and
            // `alert` only when one did not, so neither key could be relied
            // on. Both are always present now. `alert` is always null: the
            // old code built an alert and then overwrote it with an empty
            // object before sending, which the app would have read as a
            // truthy value and could have rendered as a blank banner.
            'message' => $hasUpdate ? (string) $app->message : null,
            'alert' => null,

            'features' => KeyFeature::activeOrdered(),
            'land_rules' => Rule::all_(),
            'booking_reasons' => config('clas.request_reasons'),
            'property_request_reasons' => config('clas.request_reasons'),
            'open_property_request_reasons' => config('clas.open_property_request_reasons'),
            'open_property_request_activity_types' => config('clas.open_property_activity_types'),
            'checkout_categories' => config('clas.checkout_categories'),
            'signage_options' => config('clas.signage_options'),
            'subscription_types' => config('clas.prices.property_access'),
            'wmu_subscription_payments' => config('clas.prices.wmu'),
            'darkmode_subscription_payments' => config('clas.prices.dark_mode'),
            'fishing_lands_subscription_payments' => config('clas.prices.fishing_lands'),
        ]);
    }

    /**
     * The old API keeps one device token per user per OS: the old rows for
     * that OS are deleted and a new row inserted, rather than updated in
     * place.
     */
    private function rememberDeviceToken(User $user, string $os, string $deviceToken): void
    {
        $os = strtolower($os);

        if ($deviceToken === '' || ! in_array($os, ['ios', 'android'], true)) {
            return;
        }

        DeviceToken::where('user_id', $user->id)->where('os', $os)->delete();

        DeviceToken::create([
            'user_id' => $user->id,
            'os' => $os,
            'device_token' => $deviceToken,
        ]);
    }
}
