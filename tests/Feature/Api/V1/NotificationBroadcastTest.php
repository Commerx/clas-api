<?php

namespace Tests\Feature\Api\V1;

use App\Mail\NotificationReported;
use App\Models\Notification;
use App\Services\PeasiAlerts;
use App\Services\PushSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Who receives a notification, and who may send one.
 *
 * The fan-out is tested against controlled coordinates rather than the real
 * ones. Two reasons. A community alert about the fixture listing would write
 * 5,610 mapping rows, because 5,610 of 5,611 profiles sit at exactly the
 * map's default centre — the old API persists whatever coordinates a client
 * sends, and a client with no location sends that default. And for the same
 * reason the real data cannot exercise the radius at all: every real listing
 * has nobody within 25 km of it. So these tests move the fixture listing and
 * one profile to a chosen point, and put both back afterwards.
 *
 * PushSender and PeasiAlerts are bound as instances so what would have been
 * delivered can be inspected without a Firebase project or a call to an
 * emergency alerting service.
 */
class NotificationBroadcastTest extends TestCase
{
    private object $user;

    private int $landId;

    private array $landBefore;

    private array $profileBefore;

    private int $otherUserId;

    private PushSender $push;

    private PeasiAlerts $peasi;

    /** Somewhere no real profile or listing sits, so the radius is ours. */
    private const REMOTE_LAT = 62.5;

    private const REMOTE_LNG = -109.25;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => env('CONTRACT_DB', 'clas'),
        ]);

        try {
            $user = DB::table('user')->where('username', 'contract_fixture')->first();
        } catch (\Throwable $e) {
            $this->markTestSkipped('clas database not reachable: '.$e->getMessage());
        }

        if ($user === null) {
            $this->markTestSkipped('Fixture account missing; see tests/Fixtures/v1/README.md.');
        }

        $land = DB::table('land')->where('land_name', 'Contract Fixture Land')->first();

        if ($land === null) {
            $this->markTestSkipped('Fixture listing missing; see tests/Fixtures/v1/README.md.');
        }

        $this->user = $user;
        $this->landId = (int) $land->land_id;
        $this->landBefore = (array) $land;
        $this->profileBefore = (array) DB::table('profile')->where('user_id', $user->id)->first();
        $this->otherUserId = (int) DB::table('user')->where('id', '!=', $user->id)->value('id');

        // Move the listing somewhere empty, and the fixture user with it, so
        // the only profile in range is one we control.
        DB::table('land')->where('land_id', $this->landId)->update([
            'land_lat' => self::REMOTE_LAT,
            'land_lng' => self::REMOTE_LNG,
        ]);

        $this->push = new PushSender;
        $this->peasi = new PeasiAlerts;
        $this->app->instance(PushSender::class, $this->push);
        $this->app->instance(PeasiAlerts::class, $this->peasi);

        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->landId)) {
            $this->clearNotifications();
            DB::table('land')->where('land_id', $this->landId)->update($this->landBefore);
            DB::table('profile')->where('user_id', $this->user->id)->update($this->profileBefore);
            DB::table('land_notification_subscriptions')->where('land_id', $this->landId)->delete();
            DB::table('user_settings')->where('user_id', $this->user->id)->delete();
        }

        parent::tearDown();
    }

    /** Everything these tests created. */
    private function clearNotifications(): void
    {
        $ids = DB::table('notifications')->where('land_id', $this->landId)->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('report_notifications')->whereIn('notification_id', $ids)->delete();
            DB::table('notifications_users_mapping')->whereIn('notification_id', $ids)->delete();
            DB::table('notifications')->whereIn('id', $ids)->delete();
        }
    }

    private function placeUserNearTheListing(): void
    {
        DB::table('profile')->where('user_id', $this->user->id)->update([
            'user_lat' => self::REMOTE_LAT,
            'user_lng' => self::REMOTE_LNG,
        ]);
    }

    private function token(): string
    {
        return $this->user->auth_key;
    }

    // ---- community awareness: proximity --------------------------------

    public function test_a_community_alert_reaches_only_profiles_within_the_radius(): void
    {
        $this->placeUserNearTheListing();

        $data = $this->postJson('/v1/notifications/community-awareness', [
            'token' => $this->token(),
            'land_id' => $this->landId,
            'message_body' => 'Bear seen near the north gate.',
        ])->assertStatus(201)->json('data');

        $this->assertSame(1, $data['recipients'],
            'Only the one profile placed in range should receive it.');

        $this->assertSame(1, DB::table('notifications_users_mapping')
            ->where('notification_id', $data['id'])
            ->where('user_id', $this->user->id)
            ->count());

        $this->assertSame(Notification::COMMUNITY_AWARENESS, (int) DB::table('notifications')
            ->where('id', $data['id'])
            ->value('notification_category'));
    }

    public function test_a_listing_with_nobody_in_range_reaches_nobody(): void
    {
        // The fixture user stays at the default centre, 1,400 km away.
        $data = $this->postJson('/v1/notifications/community-awareness', [
            'token' => $this->token(),
            'land_id' => $this->landId,
            'message_body' => 'Nobody is near this one.',
        ])->assertStatus(201)->json('data');

        $this->assertSame(0, $data['recipients']);
        $this->assertSame(0, $data['pushed']);
    }

    /**
     * A listing with no coordinates must reach nobody, not everybody.
     *
     * 332 listings are in this state. The risk is in the guard: dropping the
     * distance condition while keeping the query selects every profile in the
     * database.
     */
    public function test_a_listing_without_coordinates_reaches_nobody(): void
    {
        $this->placeUserNearTheListing();

        DB::table('land')->where('land_id', $this->landId)
            ->update(['land_lat' => 0, 'land_lng' => 0]);

        $data = $this->postJson('/v1/notifications/community-awareness', [
            'token' => $this->token(),
            'land_id' => $this->landId,
            'message_body' => 'This listing has no position.',
        ])->assertStatus(201)->json('data');

        $this->assertSame(0, $data['recipients'],
            'No coordinates must mean no recipients, never all of them.');
    }

    // ---- notification settings -----------------------------------------

    /**
     * `all_notifications` is a master switch.
     *
     * The old condition was `all_notifications == 'Yes' || <specific> ==
     * 'Yes'`, so turning "all notifications" off did nothing unless every
     * specific switch was off too. A user who set it to No kept being pushed.
     */
    public function test_turning_all_notifications_off_stops_the_push(): void
    {
        $this->placeUserNearTheListing();

        DB::table('device_tokens')->insert([
            'user_id' => $this->user->id, 'os' => 'ios', 'device_token' => 'test-token-abc',
        ]);

        DB::table('user_settings')->insert([
            'user_id' => $this->user->id,
            'all_notifications' => 'No',
            'wildlife_notifications' => 'Yes',
            'awareness_notifications' => 'Yes',
            'subscriber_notifications' => 'Yes',
            'created' => now(),
        ]);

        $data = $this->postJson('/v1/notifications/community-awareness', [
            'token' => $this->token(),
            'land_id' => $this->landId,
            'message_body' => 'Should not be pushed.',
        ])->assertStatus(201)->json('data');

        DB::table('device_tokens')->where('device_token', 'test-token-abc')->delete();

        $this->assertSame(1, $data['recipients'],
            'The message is still recorded: settings govern the push, not the copy.');
        $this->assertSame(0, $data['pushed'],
            'all_notifications = No must suppress the push.');
        $this->assertSame([], $this->push->sent);
    }

    /**
     * And a user with no settings row is pushed.
     *
     * Almost nobody has one — 11 accounts of 5,600 — and the old code
     * required the row to exist before it would push at all, so everyone else
     * got no push from any of these endpoints.
     */
    public function test_a_user_with_no_settings_row_still_gets_the_push(): void
    {
        $this->placeUserNearTheListing();

        DB::table('user_settings')->where('user_id', $this->user->id)->delete();
        DB::table('device_tokens')->insert([
            'user_id' => $this->user->id, 'os' => 'ios', 'device_token' => 'test-token-def',
        ]);

        $data = $this->postJson('/v1/notifications/community-awareness', [
            'token' => $this->token(),
            'land_id' => $this->landId,
            'message_body' => 'Should be pushed.',
        ])->assertStatus(201)->json('data');

        DB::table('device_tokens')->where('device_token', 'test-token-def')->delete();

        $this->assertSame(1, $data['pushed']);
        $this->assertCount(1, $this->push->sent);
        $this->assertSame('test-token-def', $this->push->sent[0]['token']);
    }

    // ---- subscriber notification: who may send -------------------------

    /**
     * Posting to a property's subscribers is for whoever manages it.
     *
     * The old endpoint checked only that the listing existed, so any
     * authenticated caller could broadcast to any property's subscribers —
     * and a subscriber reads these as coming from the property they chose to
     * follow. One of the sub-categories is "Product/Service Advertisement".
     */
    public function test_only_the_owner_or_a_manager_may_post_to_subscribers(): void
    {
        $outsider = DB::table('user')
            ->where('id', '!=', $this->user->id)
            ->whereNotNull('auth_key')
            ->where('auth_key', '!=', '')
            // Not blocked, or the middleware refuses before the access check
            // runs and the test would pass for the wrong reason.
            ->whereNull('blocked_at')
            ->whereNotIn('id', DB::table('land')->where('land_id', $this->landId)->pluck('owner'))
            // Nor a manager of this listing.
            ->whereNotIn('id', DB::table('land_managers')
                ->where('land_id', $this->landId)->pluck('user_id'))
            ->first();

        if ($outsider === null) {
            $this->markTestSkipped('No second account with a token to test against.');
        }

        $this->postJson('/v1/notifications/subscriber-notification', [
            'token' => $outsider->auth_key,
            'land_id' => $this->landId,
            'message_body' => 'Buy my product.',
        ])->assertStatus(404);

        $this->assertSame(0, DB::table('notifications')->where('land_id', $this->landId)->count(),
            'A refused broadcast must not leave a notification behind.');
    }

    public function test_the_owner_may_post_to_subscribers(): void
    {
        DB::table('land_notification_subscriptions')->insert([
            'land_id' => $this->landId,
            'user_id' => $this->user->id,
            'created' => now(),
        ]);

        $data = $this->postJson('/v1/notifications/subscriber-notification', [
            'token' => $this->token(),
            'land_id' => $this->landId,
            'message_body' => 'Gate code changed.',
        ])->assertStatus(201)->json('data');

        $this->assertSame(1, $data['recipients']);
        $this->assertSame(Notification::SUBSCRIBER, (int) DB::table('notifications')
            ->where('id', $data['id'])->value('notification_category'));
    }

    // ---- hunting alert --------------------------------------------------

    public function test_a_hunting_alert_with_no_matching_hunters_creates_nothing(): void
    {
        $wmu = DB::table('wmus')->first();

        if ($wmu === null) {
            $this->markTestSkipped('No WMU rows.');
        }

        $this->postJson('/v1/notifications/hunting-alert', [
            'token' => $this->token(),
            'land_id' => $this->landId,
            'province' => $wmu->province,
            'wmus' => [$wmu->wmu_number],
            // No tag will match this species id.
            'species_id' => 99999999,
            'species_gender' => 'Male',
            'hunters_count' => 2,
            'message_body' => 'Nobody matches this.',
        ])->assertStatus(409);

        $this->assertSame(0, DB::table('notifications')->where('land_id', $this->landId)->count(),
            'An alert nobody can receive must not be stored.');
    }

    public function test_a_hunting_alert_rejects_unknown_management_units(): void
    {
        $this->postJson('/v1/notifications/hunting-alert', [
            'token' => $this->token(),
            'land_id' => $this->landId,
            'province' => 'Alberta',
            'wmus' => ['not-a-real-wmu'],
            'species_id' => 1,
            'species_gender' => 'Male',
            'hunters_count' => 1,
            'message_body' => 'x',
        ])->assertStatus(422);
    }

    // ---- reading, marking, deleting -------------------------------------

    public function test_a_notification_sent_to_somebody_else_is_not_readable(): void
    {
        $id = $this->notificationFor($this->otherUserId);

        $this->getJson('/v1/notifications/get-notification?token='.$this->token().'&notification_id='.$id)
            ->assertStatus(404);

        $this->postJson('/v1/notifications/read', [
            'token' => $this->token(), 'notification_id' => $id,
        ])->assertStatus(404);

        $this->postJson('/v1/notifications/delete-notification', [
            'token' => $this->token(), 'notification_id' => $id,
        ])->assertStatus(404);

        $this->assertSame(1, DB::table('notifications_users_mapping')
            ->where('notification_id', $id)->count(),
            "Another user's copy must survive.");
    }

    public function test_reading_and_deleting_the_callers_own_copy(): void
    {
        $id = $this->notificationFor((int) $this->user->id);

        $item = $this->getJson('/v1/notifications/get-notification?token='.$this->token().'&notification_id='.$id)
            ->assertOk()
            ->json('data.item');

        $this->assertFalse($item['is_read']);
        $this->assertSame([], $item['wmu_list'], 'wmu_list is an array even when empty.');
        $this->assertIsInt($item['id']);

        $this->postJson('/v1/notifications/read', [
            'token' => $this->token(), 'notification_id' => $id,
        ])->assertOk();

        $this->assertSame(1, (int) DB::table('notifications_users_mapping')
            ->where('notification_id', $id)->where('user_id', $this->user->id)->value('is_read'));

        $this->postJson('/v1/notifications/delete-notification', [
            'token' => $this->token(), 'notification_id' => $id,
        ])->assertOk();

        $this->assertSame(0, DB::table('notifications_users_mapping')
            ->where('notification_id', $id)->where('user_id', $this->user->id)->count());

        $this->assertSame(1, DB::table('notifications')->where('id', $id)->count(),
            'Deleting a copy must not delete the notification itself.');
    }

    /** Marking read is a write, so it is a POST. The old endpoint was a GET. */
    public function test_marking_read_is_not_reachable_by_get(): void
    {
        $this->getJson('/v1/notifications/read?token='.$this->token().'&notification_id=1')
            ->assertStatus(405);
    }

    public function test_reporting_a_notification_records_it_and_mails_the_admin(): void
    {
        $id = $this->notificationFor((int) $this->user->id);

        $this->postJson('/v1/notifications/report-notification', [
            'token' => $this->token(),
            'notification_id' => $id,
            'reason' => 'This is spam.',
        ])->assertOk();

        $this->assertSame(1, DB::table('report_notifications')
            ->where('notification_id', $id)->where('user_id', $this->user->id)->count());

        $this->assertTrue((bool) DB::table('notifications_users_mapping')
            ->where('notification_id', $id)->where('user_id', $this->user->id)->value('is_reported'),
            'The reporter\'s own copy is flagged, so the app can show it was reported.');

        Mail::assertSent(NotificationReported::class, function ($mail) {
            $this->assertSame(
                config('notifications.from.address'),
                $mail->envelope()->from->address,
                'From must be this application, not the reporting user.',
            );

            return true;
        });
    }

    public function test_reporting_somebody_elses_notification_is_refused(): void
    {
        $id = $this->notificationFor($this->otherUserId);

        $this->postJson('/v1/notifications/report-notification', [
            'token' => $this->token(),
            'notification_id' => $id,
            'reason' => 'This is spam.',
        ])->assertStatus(404);

        $this->assertSame(0, DB::table('report_notifications')
            ->where('notification_id', $id)->count());

        Mail::assertNothingSent();
    }

    /** A notification about the fixture listing, addressed to one user. */
    private function notificationFor(int $userId): int
    {
        $id = (int) DB::table('notifications')->insertGetId([
            'notification_category' => Notification::COMMUNITY_AWARENESS,
            'notification_sub_category' => 9,
            'land_id' => $this->landId,
            'message_body' => 'A test notification.',
            'sender_id' => $this->user->id,
            'created' => now(),
        ]);

        DB::table('notifications_users_mapping')->insert([
            'notification_id' => $id,
            'user_id' => $userId,
            'is_read' => 0,
        ]);

        return $id;
    }
}
