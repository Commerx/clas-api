<?php

namespace App\Support\V1;

use Illuminate\Support\Facades\DB;

/**
 * Reading a notification back.
 *
 * The old `get-notification` selected `notifications.*` plus five joined
 * columns, so the response was whatever the schema happened to hold. These
 * are listed instead — the same fields the old response carried, named
 * explicitly, so adding a column to the table does not publish it.
 */
class NotificationRow
{
    /** Columns of `notifications` a recipient may read. */
    private const COLUMNS = [
        'notifications.id',
        'notifications.notification_category',
        'notifications.notification_sub_category',
        'notifications.land_id',
        'notifications.land_address',
        'notifications.province',
        'notifications.wmu_list',
        'notifications.species_id',
        'notifications.species_gender',
        'notifications.hunters_count',
        'notifications.tag_required',
        'notifications.message_body',
        'notifications.sender_id',
        'notifications.is_admin_notification',
        'notifications.created',
    ];

    /**
     * One notification, as the recipient identified by $userId sees it.
     *
     * The join to `notifications_users_mapping` is the access check: a row
     * comes back only when one exists for this user, so there is no path that
     * returns somebody else's notification. It also carries `is_read` and the
     * matched `tag_id`, which belong to the recipient rather than the
     * message.
     */
    public static function forRecipient(int $notificationId, int $userId): ?array
    {
        $row = DB::table('notifications')
            ->select(array_merge(self::COLUMNS, [
                'profile.name as sender',
                'parent_category.category_name as type',
                'sub_category.category_name as category',
                'species.species_name',
                'land.land_name',
                'land.address',
                'land.coordinates',
                'notifications_users_mapping.tag_id',
                'notifications_users_mapping.is_read',
            ]))
            ->join('notification_categories as parent_category', 'parent_category.id', '=', 'notifications.notification_category')
            ->leftJoin('notification_categories as sub_category', 'sub_category.id', '=', 'notifications.notification_sub_category')
            ->join('notifications_users_mapping', 'notifications_users_mapping.notification_id', '=', 'notifications.id')
            ->join('profile', 'profile.user_id', '=', 'notifications.sender_id')
            ->leftJoin('species', 'species.species_id', '=', 'notifications.species_id')
            ->leftJoin('land', 'land.land_id', '=', 'notifications.land_id')
            ->where('notifications.id', $notificationId)
            ->where('notifications_users_mapping.user_id', $userId)
            ->first();

        return $row === null ? null : self::format((array) $row);
    }

    /**
     * Native types, and `wmu_list` as the array it was stored from.
     *
     * A hunting alert stores the management units as a JSON string in a
     * varchar column. The old API returned that string and left the app to
     * parse it; returning the array is the same information in the shape it
     * was in before it was written.
     */
    private static function format(array $row): array
    {
        foreach (['id', 'notification_category', 'notification_sub_category',
            'land_id', 'species_id', 'hunters_count', 'sender_id', 'tag_id'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = $row[$column] === null ? null : (int) $row[$column];
            }
        }

        foreach (['is_admin_notification', 'is_read'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = (bool) (int) $row[$column];
            }
        }

        $row['tag_required'] = ($row['tag_required'] ?? 'No') === 'Yes';

        $decoded = json_decode((string) ($row['wmu_list'] ?? ''), true);
        $row['wmu_list'] = is_array($decoded) ? array_values($decoded) : [];

        return $row;
    }
}
