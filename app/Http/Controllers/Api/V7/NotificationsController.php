<?php

namespace App\Http\Controllers\Api\V7;

use App\Http\Controllers\Controller;
use App\Models\NotificationCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild of modules/api/controllers/v7/NotificationsController.php.
 *
 * Authentication is handled by the auth.v7 middleware rather than repeated in
 * each action, but the responses are unchanged.
 *
 * These two endpoints differ in a way that matters. v7 served `categories`
 * through Yii ActiveRecord, which casts integer columns from the schema, so
 * `id` and `parent_id` come back as integers. It served `list` through a raw
 * yii\db\Query, where PDO hands back every column as a string — so `id` is
 * "56", `is_read` is "1". Laravel's query builder returns native integers for
 * those columns, so `list` casts them back to strings explicitly. Returning
 * integers there would be tidier and would break the shipped apps.
 */
class NotificationsController extends Controller
{
    /** Columns that v7's raw query returned as strings. */
    private const LIST_STRING_COLUMNS = [
        'id', 'sender_id', 'is_admin_notification', 'is_read',
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v7_user');

        $start = (int) $request->query('start', 0);
        $limit = $request->query('limit', '');

        $query = DB::table('notifications')
            ->distinct()
            ->select([
                'notifications.id as id',
                'notifications.message_body as message',
                'notifications.sender_id',
                'notifications.created',
                'notifications.is_admin_notification',
                'profile.name as sender',
                'parent_category.category_name as type',
                'sub_category.category_name as category',
                'notifications_users_mapping.is_read',
            ])
            ->join('notification_categories as parent_category', 'parent_category.id', '=', 'notifications.notification_category')
            ->leftJoin('notification_categories as sub_category', 'sub_category.id', '=', 'notifications.notification_sub_category')
            ->join('notifications_users_mapping', 'notifications_users_mapping.notification_id', '=', 'notifications.id')
            ->join('profile', 'profile.user_id', '=', 'notifications.sender_id')
            ->where('notifications_users_mapping.user_id', $user->id)
            ->orderByDesc('notifications.created');

        // v7 applies offset and limit only when truthy, so start=0 and an
        // absent limit both mean "no clause" rather than LIMIT 0.
        if ($start) {
            $query->offset($start);
        }

        if ($limit !== '' && (int) $limit !== 0) {
            $query->limit((int) $limit);
        }

        $items = $query->get()
            ->map(fn ($row) => $this->stringifyListRow((array) $row))
            ->all();

        return new JsonResponse([
            'error' => '',
            'success' => true,
            'data' => ['items' => $items],
        ]);
    }

    public function categories(): JsonResponse
    {
        $categories = NotificationCategory::query()
            ->where('status', 'active')
            ->orderBy('parent_id')
            ->orderByDesc('id')
            ->get();

        return new JsonResponse([
            'error' => '',
            'success' => true,
            'data' => ['items' => $categories],
        ]);
    }

    /**
     * PDO under Yii returned every column of a raw query as a string. Laravel
     * returns native types, so the numeric columns are cast back.
     */
    private function stringifyListRow(array $row): array
    {
        foreach (self::LIST_STRING_COLUMNS as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = (string) $row[$column];
            }
        }

        return $row;
    }
}
