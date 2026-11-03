<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\NotificationCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild of modules/api/controllers/v7/NotificationsController.php.
 *
 * Authentication is handled by the auth.token middleware rather than repeated in
 * each action, but the responses are unchanged.
 *
 * These two endpoints differ in a way that matters. The old API served `categories`
 * through Yii ActiveRecord, which casts integer columns from the schema, so
 * `id` and `parent_id` come back as integers. It served `list` through a raw
 * yii\db\Query, where PDO hands back every column as a string — so `id` is
 * "56", `is_read` is "1". Laravel's query builder returns native integers for
 * those columns, so `list` casts them back to strings explicitly. Returning
 * integers there would be tidier and would break the shipped apps.
 */
class NotificationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('api_user');

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

        // The old API applies offset and limit only when truthy, so start=0 and an
        // absent limit both mean "no clause" rather than LIMIT 0.
        if ($start) {
            $query->offset($start);
        }

        if ($limit !== '' && (int) $limit !== 0) {
            $query->limit((int) $limit);
        }

        $items = $query->get()
            ->map(fn ($row) => $this->castRow((array) $row))
            ->all();

        return ApiResponse::data(['items' => $items]);
    }

    public function categories(): JsonResponse
    {
        $categories = NotificationCategory::query()
            ->where('status', 'active')
            ->orderBy('parent_id')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::data(['items' => $categories]);
    }

    /** Native types: the old API returned these as strings from a raw query. */
    private function castRow(array $row): array
    {
        foreach (['id', 'sender_id'] as $column) {
            if (isset($row[$column])) {
                $row[$column] = (int) $row[$column];
            }
        }

        foreach (['is_admin_notification', 'is_read'] as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = (bool) (int) $row[$column];
            }
        }

        return $row;
    }
}
