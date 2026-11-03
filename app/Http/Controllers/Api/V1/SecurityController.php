<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\Land;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild of modules/api/controllers/v7/SecurityController.php.
 *
 * Two shipped mobile apps call these endpoints and cannot be patched from the
 * server, so the response must match the old API field for field and type for
 * type.
 * The responses captured from it in tests/Fixtures/v1 are the specification; the
 * contract tests fail if this drifts from them.
 *
 * Note the deliberate inconsistency carried over from it: `id` is an integer
 * while `notifications_unread_count` is a string, because the old code passed
 * a PDO COUNT straight through. Making both integers would be tidier and would
 * break the apps.
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

        return ApiResponse::data($this->loginPayload($user));
    }

    /**
     * The old API keeps one device token per user per OS: the old rows for that OS are
     * deleted and a new row inserted, rather than updated in place.
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

    private function loginPayload(User $user): array
    {
        $profile = $user->profile;

        $data = [
            'id' => (int) $user->id,
            'name' => $profile?->name,
            'username' => $user->username,
            'email' => $user->email,
            'token' => $user->auth_key,
            'confirmed' => $user->isConfirmed(),
            'deactivation_date' => $user->isConfirmed()
                ? false
                : $this->sevenDaysAfter((int) $user->created_at),
            'send_notifications' => ! (int) ($profile?->mute_user ?? 0),
            'profile_info_exists' => ($profile?->addressPartsCount() ?? 0) > 1,
            'vehicle_info_exists' => ($profile?->vehiclePartsCount() ?? 0) > 0,
            'free_user' => true,
            'subscriber' => false,
            'property_access_subscription' => false,
            'dark_mode_subscription' => false,
            'wmu_alberta_subscription' => false,
            'wmu_sk_subscription' => false,
            'fishing_lands_subscription' => false,
        ];

        if ($listing = $user->lastListingSubscription()) {
            $data['property_access_subscription'] = [
                'type' => $listing->subscription_type,
                'info' => $this->subscriptionInfo($listing->subscription_type),
                'start_date' => $listing->start_date,
                'expiration_date' => $listing->expiration_date,
                'status' => $listing->status,
            ];
            $data['subscriber'] = true;
            $data['free_user'] = false;
        }

        // The old API uses the plural key here but the singular one in the defaults
        // above, so both appear in the response for a subscribed user.
        if ($wmu = $user->wmuSubscription()) {
            $data['wmu_subscriptions'] = $this->subscriptionWindow($wmu);
        }

        if ($darkMode = $user->darkModeSubscription()) {
            $data['dark_mode_subscription'] = $this->subscriptionWindow($darkMode);
        }

        if ($fishing = $user->fishingLandsSubscription()) {
            $data['fishing_lands_subscription'] = $this->subscriptionWindow($fishing);
        }

        $listingsCount = Land::where('owner', $user->id)->count();

        $data['land_owner'] = false;

        // listings_count is present only for owners; absent entirely otherwise.
        if ($listingsCount) {
            $data['land_owner'] = true;
            $data['listings_count'] = $listingsCount;
        }

        $data['today_bookings'] = DB::table('requests')
            ->join('land', 'requests.land_id', '=', 'land.land_id')
            ->where('requests.requester_id', $user->id)
            ->whereIn('requests.status', ['Pending', 'Accepted', 'Checked In'])
            ->whereRaw('requests.request_date = CURDATE()')
            ->get(['requests.land_id', 'land.land_name', 'requests.request_id', 'requests.status'])
            ->map(fn ($row) => (array) $row)
            ->all();

        $data['notifications_unread_count'] = (int) DB::table('notifications_users_mapping')
            ->where('user_id', $user->id)
            ->where('is_read', 0)
            ->count();

        return $data;
    }

    private function subscriptionWindow(object $subscription): array
    {
        return [
            'start_date' => $subscription->start_date,
            'expiration_date' => $subscription->expiration_date,
            'status' => $subscription->status,
        ];
    }

    private function subscriptionInfo(?string $type): string
    {
        return match ($type) {
            'monthly' => 'Monthly Subscription',
            'yearly' => 'Yearly Subscription',
            default => (string) $type,
        };
    }

    private function sevenDaysAfter(int $createdAt): string
    {
        return date('Y-m-d', strtotime('+7 days', $createdAt));
    }
}
