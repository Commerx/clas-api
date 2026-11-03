<?php

namespace App\Support\V1;

use App\Models\Land;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The account block that login, signup and user/get all return.
 *
 * In the old API this was written out three times — once in each action —
 * which is why the three drifted: user/get reported `profile_info_exists`
 * from a freshly computed list while login reported it from a different
 * count, and only two of the three returned the subscription flags in the
 * same shape. Any screen that reads the account after a sign-in and then
 * refreshes it sees both versions, so they have to agree.
 *
 * `identity()` is the common part. `withProfile()` adds the editable profile
 * columns, which only user/get returns.
 */
class AccountPayload
{
    /**
     * Profile columns the owner of the profile may read back.
     *
     * An allowlist rather than the whole row. The old API did
     * `foreach ($user->profile as $key => $val)`, which returned
     * `pipedrive_person_id` — the account's id in the company's CRM — along
     * with the gravatar bookkeeping and an unused `question` column. None of
     * that is the user's business and all of it would appear in a bug report
     * or a proxy log.
     */
    public const PROFILE_COLUMNS = [
        'user_id', 'first_name', 'last_name', 'name', 'display_name',
        'brief_bio', 'bio', 'user_lat', 'user_lng', 'street', 'city',
        'province', 'country', 'postal_code', 'public_email', 'location',
        'website', 'phone', 'profile_image', 'occupation', 'guide',
        'guide_number', 'vehicle_description', 'vehicle_year', 'vehicle_make',
        'vehicle_model', 'vehilcle_color', 'license_plate',
        'driver_license_number', 'other_features',
    ];

    /** The account as login and signup report it. */
    public static function identity(User $user): array
    {
        $profile = $user->profile;

        return [
            'id' => (int) $user->id,
            'name' => $profile?->name,
            'username' => $user->username,
            'email' => $user->email,
            'token' => $user->auth_key,
            'confirmed' => $user->isConfirmed(),
            'deactivation_date' => $user->isConfirmed()
                ? false
                : self::deactivationDate((int) $user->created_at),
            'send_notifications' => ! (int) ($profile?->mute_user ?? 0),
            'profile_info_exists' => ($profile?->addressPartsCount() ?? 0) > 1,
            'vehicle_info_exists' => ($profile?->vehiclePartsCount() ?? 0) > 0,
        ] + self::subscriptions($user) + self::standing($user);
    }

    /** The account as user/get reports it: the same, plus the profile. */
    public static function withProfile(User $user): array
    {
        $profile = $user->profile;

        $columns = [];

        foreach (self::PROFILE_COLUMNS as $column) {
            $columns[$column] = $profile?->{$column};
        }

        // `mute_user` is stored inverted from how it reads, so it is reported
        // as send_notifications and the raw column is not returned at all.
        return $columns + self::identity($user);
    }

    /**
     * Subscription state.
     *
     * Every flag is present whether or not the user holds that subscription,
     * so the app never has to distinguish absent from false. The one
     * asymmetry is deliberate and carried over: a user with a WMU
     * subscription gets `wmu_subscriptions` describing it in addition to the
     * `wmu_alberta_subscription` and `wmu_sk_subscription` booleans, because
     * that is what the old API sent and the apps read.
     */
    public static function subscriptions(User $user): array
    {
        $data = [
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
                'info' => self::subscriptionInfo($listing->subscription_type),
                'start_date' => $listing->start_date,
                'expiration_date' => $listing->expiration_date,
                'status' => $listing->status,
            ];
            $data['subscriber'] = true;
            $data['free_user'] = false;
        }

        if ($wmu = $user->wmuSubscription()) {
            $data['wmu_subscriptions'] = self::window($wmu);
        }

        if ($darkMode = $user->darkModeSubscription()) {
            $data['dark_mode_subscription'] = self::window($darkMode);
        }

        if ($fishing = $user->fishingLandsSubscription()) {
            $data['fishing_lands_subscription'] = self::window($fishing);
        }

        return $data;
    }

    /** Whether the caller owns listings, what they have booked today, unread count. */
    public static function standing(User $user): array
    {
        $listingsCount = Land::where('owner', $user->id)->count();

        $data = ['land_owner' => (bool) $listingsCount];

        // listings_count is present only for owners, as before.
        if ($listingsCount) {
            $data['listings_count'] = $listingsCount;
        }

        $data['today_bookings'] = DB::table('requests')
            ->join('land', 'requests.land_id', '=', 'land.land_id')
            ->where('requests.requester_id', $user->id)
            ->whereIn('requests.status', ['Pending', 'Accepted', 'Checked In'])
            ->whereRaw('requests.request_date = CURDATE()')
            ->get(['requests.land_id', 'land.land_name', 'requests.request_id', 'requests.status'])
            ->map(fn ($row) => [
                'land_id' => (int) $row->land_id,
                'land_name' => $row->land_name,
                'request_id' => (int) $row->request_id,
                'status' => $row->status,
            ])
            ->all();

        $data['notifications_unread_count'] = (int) DB::table('notifications_users_mapping')
            ->where('user_id', $user->id)
            ->where('is_read', 0)
            ->count();

        return $data;
    }

    private static function window(object $subscription): array
    {
        return [
            'start_date' => $subscription->start_date,
            'expiration_date' => $subscription->expiration_date,
            'status' => $subscription->status,
        ];
    }

    private static function subscriptionInfo(?string $type): string
    {
        return match ($type) {
            'monthly' => 'Monthly Subscription',
            'yearly' => 'Yearly Subscription',
            default => (string) $type,
        };
    }

    /**
     * When an unconfirmed account stops working.
     *
     * Formatted as the old API formatted it ("Jan 08, 2026"), since the apps
     * display the string rather than parse it.
     */
    public static function deactivationDate(int $createdAt): string
    {
        return date('M d, Y', strtotime('+'.config('clas.guest_days').' days', $createdAt));
    }

    /** @return array<string,mixed> the profile columns, for a write */
    public static function profileDefaults(): array
    {
        return array_fill_keys(
            array_diff(self::PROFILE_COLUMNS, ['user_id']),
            null,
        );
    }

    public static function profileOf(int $userId): ?Profile
    {
        return Profile::where('user_id', $userId)->first();
    }
}
