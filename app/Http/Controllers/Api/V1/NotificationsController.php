<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Mail\NotificationReported;
use App\Models\Notification;
use App\Models\NotificationCategory;
use App\Services\NotificationBroadcast;
use App\Support\V1\ListingAccess;
use App\Support\V1\NotificationRow;
use App\Support\V1\Wmu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * Rebuild of modules/api/controllers/v7/NotificationsController.php.
 *
 * Authentication is handled by the auth.token middleware rather than repeated in
 * each action, but the responses are unchanged.
 *
 * Types are native throughout, which is a change from the old API rather than
 * a port of it: `categories` went through Yii ActiveRecord and returned
 * integers, while `list` went through a raw yii\db\Query where PDO hands back
 * every column as a string, so the same `id` was 56 in one response and "56"
 * in the other. Both are integers here.
 *
 * Who receives a notification is decided in App\Services\NotificationBroadcast,
 * not here. The old controller had that logic written out four times and the
 * four had drifted; the differences are listed there.
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

    /**
     * Post a community awareness alert: everyone near the property, pushed.
     */
    public function communityAwareness(Request $request, NotificationBroadcast $broadcast): JsonResponse
    {
        return $this->broadcastAbout(
            $request,
            $broadcast,
            Notification::COMMUNITY_AWARENESS,
        );
    }

    /**
     * Post a notification to a property's subscribers.
     *
     * Owner or accepted manager only. The old endpoint checked that the
     * listing existed and nothing else, so any authenticated caller could
     * broadcast to the subscribers of any property — and a subscriber reads
     * these as coming from the property they followed. With the sub-category
     * list including "Product/Service Advertisement", that was an open route
     * to pushing advertising to another owner's followers.
     */
    public function subscriberNotification(Request $request, NotificationBroadcast $broadcast): JsonResponse
    {
        return $this->broadcastAbout(
            $request,
            $broadcast,
            Notification::SUBSCRIBER,
            ownerOnly: true,
        );
    }

    /**
     * Post a hunting alert to hunters whose active tags match.
     *
     * Recipients are matched on management unit, species, gender and
     * optionally holding a tag number — not on proximity. When nothing
     * matches, nothing is written: the old endpoint returned an error in that
     * case and created no notification, which is right, because an alert
     * nobody can receive is not a draft to keep.
     */
    public function huntingAlert(Request $request, NotificationBroadcast $broadcast): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'land_id' => ['required', 'integer'],
            'province' => ['required', 'string', 'max:60'],
            'wmus' => ['required'],
            'species_id' => ['required', 'integer'],
            'species_gender' => ['required', 'string', 'max:60'],
            'hunters_count' => ['required', 'integer', 'min:1'],
            'message_body' => ['required', 'string', 'max:5000'],
            'tag_required' => ['nullable'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $land = $this->listing((int) $request->input('land_id'));

        if ($land === null) {
            return ApiResponse::notFound('No such listing.');
        }

        $province = (string) $request->input('province');
        $wmus = Wmu::numbers($request->input('wmus'));
        $wmuIds = Wmu::idsFor($province, $wmus);

        if ($wmuIds === []) {
            return ApiResponse::invalid([
                'wmus' => ['No management units match that province and list.'],
            ]);
        }

        $tagRequired = $request->boolean('tag_required');

        $recipients = $broadcast->matchingHunters(
            $wmuIds,
            (int) $request->input('species_id'),
            (string) $request->input('species_gender'),
            $tagRequired,
        );

        if ($recipients === []) {
            return ApiResponse::error(
                'No users are matching your hunting alert criterias!',
                409,
            );
        }

        $notification = Notification::create([
            'notification_category' => Notification::HUNTING_ALERT,
            'notification_sub_category' => (int) $request->input('notification_sub_category', 0),
            'land_id' => $land->land_id,
            'province' => $province,
            // Stored as JSON in a varchar, as before, so the Yii application
            // reads what it expects.
            'wmu_list' => json_encode($wmus),
            'species_id' => (int) $request->input('species_id'),
            'species_gender' => (string) $request->input('species_gender'),
            'hunters_count' => (int) $request->input('hunters_count'),
            'tag_required' => $tagRequired ? 'Yes' : 'No',
            'message_body' => (string) $request->input('message_body'),
            'sender_id' => $this->userId($request),
            'created' => now(),
        ]);

        $sent = $broadcast->deliver($notification, $recipients, $land);

        return ApiResponse::data([
            'id' => (int) $notification->id,
        ] + $sent, 201);
    }

    /**
     * The generic entry point, kept because the apps in the field use it.
     *
     * The category decides the fan-out, so this dispatches to the same code
     * the three dedicated endpoints use. In the old API it was a fourth
     * implementation that behaved differently from all of them.
     */
    public function submit(Request $request, NotificationBroadcast $broadcast): JsonResponse
    {
        $category = (int) $request->input('notification_category', 0);

        if ($category === Notification::HUNTING_ALERT) {
            return $this->huntingAlert($request, $broadcast);
        }

        if (! in_array($category, [Notification::COMMUNITY_AWARENESS, Notification::SUBSCRIBER], true)) {
            return ApiResponse::invalid([
                'notification_category' => ['Must be 1 (community awareness), 2 (subscriber) or 3 (hunting alert).'],
            ]);
        }

        return $this->broadcastAbout(
            $request,
            $broadcast,
            $category,
            ownerOnly: $category === Notification::SUBSCRIBER,
        );
    }

    /** One notification the caller received. */
    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'notification_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $row = NotificationRow::forRecipient(
            (int) $request->input('notification_id'),
            $this->userId($request),
        );

        // 404 whether it does not exist or was sent to somebody else, so the
        // existence of a message is not confirmed to someone who cannot read
        // it.
        return $row === null
            ? ApiResponse::notFound('No such notification.')
            : ApiResponse::data(['item' => $row]);
    }

    /**
     * Mark one as read.
     *
     * A POST. The old endpoint was a GET that wrote, so anything that
     * prefetched a link marked it read.
     */
    public function markRead(Request $request): JsonResponse
    {
        return $this->onOwnCopy($request, function (int $notificationId, int $userId) {
            DB::table('notifications_users_mapping')
                ->where('notification_id', $notificationId)
                ->where('user_id', $userId)
                ->update(['is_read' => 1]);

            return ApiResponse::data(['read' => true]);
        });
    }

    /**
     * Remove the caller's copy.
     *
     * The mapping row goes; the notification and everyone else's copies stay.
     */
    public function destroy(Request $request): JsonResponse
    {
        return $this->onOwnCopy($request, function (int $notificationId, int $userId) {
            DB::table('notifications_users_mapping')
                ->where('notification_id', $notificationId)
                ->where('user_id', $userId)
                ->delete();

            return ApiResponse::data(['deleted' => true]);
        });
    }

    /** Report a notification to the administrators. */
    public function report(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'notification_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:5000'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $notificationId = (int) $request->input('notification_id');
        $user = $this->caller($request);

        $mapping = DB::table('notifications_users_mapping')
            ->where('notification_id', $notificationId)
            ->where('user_id', $user->id)
            ->first();

        if ($mapping === null) {
            return ApiResponse::notFound('No such notification.');
        }

        $notification = Notification::find($notificationId);

        if ($notification === null) {
            return ApiResponse::notFound('No such notification.');
        }

        $reason = (string) $request->input('reason');

        DB::table('report_notifications')->insert([
            'notification_id' => $notificationId,
            'user_id' => $user->id,
            'message_body' => $reason,
            'created' => now(),
        ]);

        // The reporter's own copy is flagged, which the old endpoint left
        // alone even though the column exists for it — so a user could report
        // the same message repeatedly and the app could not show that they
        // had already done so.
        DB::table('notifications_users_mapping')
            ->where('notification_id', $notificationId)
            ->where('user_id', $user->id)
            ->update(['is_reported' => 1]);

        Mail::to(config('clas.admin_email'))->send(new NotificationReported(
            reporterName: (string) ($user->profile?->name ?: $user->username),
            reporterEmail: (string) $user->email,
            posterName: (string) DB::table('profile')
                ->where('user_id', $notification->sender_id)
                ->value('name'),
            notificationId: $notificationId,
            notificationBody: (string) $notification->message_body,
            reason: $reason,
        ));

        return ApiResponse::data(['reported' => true]);
    }

    /**
     * Create a notification about a listing and fan it out.
     *
     * Shared by community-awareness, subscriber-notification and submit.
     */
    private function broadcastAbout(
        Request $request,
        NotificationBroadcast $broadcast,
        int $category,
        bool $ownerOnly = false,
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'land_id' => ['required', 'integer'],
            'message_body' => ['required', 'string', 'max:5000'],
            'notification_sub_category' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $userId = $this->userId($request);
        $land = $this->listing((int) $request->input('land_id'));

        if ($land === null) {
            return ApiResponse::notFound('No such listing.');
        }

        if ($ownerOnly && ! ListingAccess::canManage($userId, (int) $land->land_id)) {
            // 404 rather than 403: the caller is told no more about a listing
            // they do not manage than that they cannot post about it.
            return ApiResponse::notFound('No such listing.');
        }

        $notification = Notification::create([
            'notification_category' => $category,
            'notification_sub_category' => (int) $request->input('notification_sub_category', 0),
            'land_id' => $land->land_id,
            'land_address' => $land->address,
            'message_body' => (string) $request->input('message_body'),
            'sender_id' => $userId,
            'created' => now(),
        ]);

        $recipients = $category === Notification::SUBSCRIBER
            ? $broadcast->landSubscribers((int) $land->land_id)
            : $broadcast->nearbyProfiles(
                $land->land_lat === null ? null : (float) $land->land_lat,
                $land->land_lng === null ? null : (float) $land->land_lng,
            );

        $sent = $broadcast->deliver($notification, $recipients, $land);

        return ApiResponse::data([
            'id' => (int) $notification->id,
        ] + $sent, 201);
    }

    /**
     * Run an action against the caller's own copy of a notification.
     *
     * The mapping row is both the thing being changed and the access check,
     * so there is one lookup and no path that acts on somebody else's copy.
     */
    private function onOwnCopy(Request $request, callable $action): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'notification_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $notificationId = (int) $request->input('notification_id');
        $userId = $this->userId($request);

        $exists = DB::table('notifications_users_mapping')
            ->where('notification_id', $notificationId)
            ->where('user_id', $userId)
            ->exists();

        return $exists
            ? $action($notificationId, $userId)
            : ApiResponse::notFound('No such notification.');
    }

    private function listing(int $landId): ?object
    {
        return DB::table('land')
            ->where('land_id', $landId)
            ->first(['land_id', 'land_name', 'address', 'land_lat', 'land_lng']);
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
