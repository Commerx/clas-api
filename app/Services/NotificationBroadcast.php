<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\UserSetting;
use App\Support\V1\Position;
use Illuminate\Support\Facades\DB;

/**
 * Who receives a notification, and how it reaches them.
 *
 * The old controller had this written out four times — once in
 * notifications/submit and once in each of the three dedicated endpoints —
 * and the four had drifted:
 *
 *  - submit pushed a category 1 alert without consulting the recipient's
 *    notification settings at all, while community-awareness, sent through
 *    the dedicated endpoint, honoured them. The same message respected the
 *    user's preferences or not depending on which endpoint the app called.
 *  - submit forwarded a category 3 alert to PEASI; hunting-alert, the
 *    endpoint built for category 3, did not.
 *  - hunting-alert pushed to every registered device for a user, the other
 *    three to the most recent one only.
 *  - the 25 km radius was a literal in four separate queries.
 *
 * One definition here, so a change to who gets notified is one change.
 *
 * Recipients get a `notifications_users_mapping` row whatever their settings
 * say — that row is their copy of the message in the app's list, and the
 * unread badge counts it. Settings govern the push only, which is what the
 * old code did where it checked them at all.
 */
class NotificationBroadcast
{
    /** How many mapping rows to insert per statement. */
    private const CHUNK = 500;

    public function __construct(
        private PushSender $push,
        private PeasiAlerts $peasi,
    ) {}

    /** The people subscribed to this property. @return array<int,?int> */
    public function landSubscribers(int $landId): array
    {
        return DB::table('land_notification_subscriptions')
            ->where('land_id', $landId)
            ->distinct()
            ->pluck('user_id')
            ->mapWithKeys(fn ($id) => [(int) $id => null])
            ->all();
    }

    /**
     * Everyone whose recorded position is within the radius of the property.
     *
     * The coordinates come from the `land` row and are validated before they
     * reach the expression. When either is missing, zero or out of range this
     * returns nobody rather than everybody — the guard must fail closed,
     * because dropping the HAVING while keeping the query selects every
     * profile in the database.
     *
     * Zero counts as missing, and 302 of the 16,844 active public listings
     * are in that state (332 of all 16,980 land rows). The
     * old code passed 0,0 through to the arithmetic, which put every real
     * position thousands of kilometres away and so matched nobody either; the
     * outcome is the same and the reason is now explicit rather than
     * incidental. Worth knowing on its own: an alert about any of those 302
     * listings reaches no one, and neither application says so.
     *
     * @return array<int,?int>
     */
    public function nearbyProfiles(?float $lat, ?float $lng): array
    {
        // The listing's own coordinate is only checked for validity, not for
        // being a placeholder: the default centre is also where the Waldron
        // listing really is, and an alert about it is a real alert.
        $lat = Position::coordinate($lat, 90);
        $lng = Position::coordinate($lng, 180);

        if ($lat === null || $lng === null) {
            return [];
        }

        $radius = (float) config('notifications.radius_km');

        $query = DB::table('profile')
            ->select('user_id')
            ->selectRaw($this->distanceExpression($lat, $lng))
            ->havingRaw('distance <= ?', [$radius]);

        // A profile holding a map's default centre has no position, so it is
        // not near anything. Without this, 88 active listings lie within the
        // radius of that one point and an alert on any of them reaches every
        // account in the database; see Position for how the data got there.
        Position::excludePlaceholders($query);

        return $query
            ->pluck('user_id')
            ->mapWithKeys(fn ($id) => [(int) $id => null])
            ->all();
    }

    /**
     * Hunters holding an active tag that matches the alert.
     *
     * Keyed by user id with the matching tag id as the value, because the
     * recipient's row records which of their tags matched and the app opens
     * that tag from the notification. One row per user: somebody with two
     * matching tags is notified once, as before.
     *
     * @param  list<int>  $wmuIds
     * @return array<int,?int>
     */
    public function matchingHunters(array $wmuIds, int $speciesId, string $gender, bool $tagRequired): array
    {
        if ($wmuIds === []) {
            return [];
        }

        $query = DB::table('hunters_match_tags as tags')
            ->join('hunters_match_tags_wmu_zones as zones', 'zones.tag_id', '=', 'tags.id')
            ->whereIn('zones.wmu_id', $wmuIds)
            ->where('tags.species_id', $speciesId)
            ->where('tags.species_gender', $gender)
            ->where('tags.status', 'Active')
            ->groupBy('tags.user_id')
            ->select(['tags.user_id', DB::raw('MIN(tags.id) AS tag_id')]);

        if ($tagRequired) {
            $query->whereNotNull('tags.tag_number')->where('tags.tag_number', '!=', '');
        }

        return $query->get()
            ->mapWithKeys(fn ($row) => [(int) $row->user_id => (int) $row->tag_id])
            ->all();
    }

    /**
     * Record every recipient, then push to those who want it.
     *
     * @param  array<int,?int>  $recipients  user id => matching tag id or null
     * @return array{recipients: int, pushed: int}
     */
    public function deliver(Notification $notification, array $recipients, ?object $land = null): array
    {
        if ($recipients === []) {
            return ['recipients' => 0, 'pushed' => 0];
        }

        $rows = [];

        foreach ($recipients as $userId => $tagId) {
            $rows[] = [
                'notification_id' => $notification->id,
                'user_id' => $userId,
                'tag_id' => $tagId,
                'is_read' => 0,
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('notifications_users_mapping')->insert($chunk);
        }

        $pushed = $this->pushAll($notification, $recipients, $land);

        if ((int) $notification->notification_category === Notification::HUNTING_ALERT) {
            // Reached through submit but not through hunting-alert in the old
            // code. Now it does not depend on which endpoint was called.
            $this->peasi->forward($notification, $land);
        }

        return ['recipients' => count($recipients), 'pushed' => $pushed];
    }

    private function pushAll(Notification $notification, array $recipients, ?object $land): int
    {
        $category = (int) $notification->notification_category;
        $setting = Notification::SETTING_FOR_CATEGORY[$category] ?? null;

        $allowed = $this->recipientsWantingPush(array_keys($recipients), $setting);

        if ($allowed === []) {
            return 0;
        }

        // Every registered device, not just the most recent. The old code did
        // this for hunting alerts and not for the other two, so a user with a
        // phone and a tablet got some notifications on one device only.
        $tokens = DB::table('device_tokens')
            ->whereIn('user_id', $allowed)
            ->where('device_token', '!=', '')
            ->orderByDesc('device_token_id')
            ->get(['user_id', 'device_token']);

        $pushed = 0;

        foreach ($tokens as $row) {
            $delivered = $this->push->send(
                deviceToken: (string) $row->device_token,
                title: 'Notification',
                body: (string) $notification->message_body,
                data: [
                    'id' => (int) $notification->id,
                    'title' => 'Notification',
                    'message' => (string) $notification->message_body,
                    'type' => $category,
                    'tag_id' => $recipients[(int) $row->user_id] ?? '',
                    'land_id' => (int) $notification->land_id,
                    'land_name' => (string) ($land->land_name ?? ''),
                    'land_address' => (string) ($land->address ?? ''),
                    'link' => ' ',
                ],
            );

            $pushed += $delivered ? 1 : 0;
        }

        return $pushed;
    }

    /**
     * Of these users, the ones whose settings permit a push of this kind.
     *
     * `all_notifications` is treated as a master switch that must be on. The
     * old condition was `all_notifications == 'Yes' || <specific> == 'Yes'`,
     * which meant turning "all notifications" off did nothing at all unless
     * every specific switch happened to be off too. A user who set it to No
     * kept being pushed.
     *
     * Users with no settings row — almost everyone, 11 of 5,600 have one —
     * are treated as wanting notifications, which is what UserSetting::
     * DEFAULTS says and what the old code did not do: it required a row to
     * exist and so pushed nothing at all to anyone without one.
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function recipientsWantingPush(array $userIds, ?string $setting): array
    {
        if ($setting === null) {
            return $userIds;
        }

        $rows = DB::table('user_settings')
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'all_notifications', $setting])
            ->keyBy('user_id');

        return array_values(array_filter($userIds, function ($userId) use ($rows, $setting) {
            $row = $rows->get($userId);

            if ($row === null) {
                return UserSetting::DEFAULTS['all_notifications']
                    && UserSetting::DEFAULTS[$setting];
            }

            return $row->all_notifications === 'Yes' && $row->{$setting} === 'Yes';
        }));
    }

    /**
     * Great-circle distance in kilometres.
     *
     * The coordinates are validated floats by the time they reach here, never
     * caller text — this expression cannot be parameterised because the same
     * value appears three times, and PDO will not reuse a named placeholder
     * under native prepares.
     */
    private function distanceExpression(float $lat, float $lng): string
    {
        return sprintf(
            '(6371 * acos(cos(radians(%1$F)) * cos(radians(user_lat))'
            .' * cos(radians(user_lng) - radians(%2$F))'
            .' + sin(radians(%1$F)) * sin(radians(user_lat)))) AS distance',
            $lat,
            $lng,
        );
    }
}
