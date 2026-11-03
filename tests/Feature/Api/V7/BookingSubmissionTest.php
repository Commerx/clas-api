<?php

namespace Tests\Feature\Api\V7;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The rules governing who may book what, and when.
 *
 * These are the substance of requests/submit, and they are the old
 * application's rules rather than new ones: both write the same `requests`
 * table, so if the two disagree about whether a property is full they will
 * overbook it between them.
 *
 * Everything runs against the fixture listing, which the fixture account owns,
 * and its shape is reset per test. Dates are deliberately far in the future so
 * they cannot collide with real bookings.
 */
class BookingSubmissionTest extends TestCase
{
    private object $user;

    private int $landId;

    /** Another real account, for bookings that are not the caller's. */
    private int $otherUserId;

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
            $this->markTestSkipped('Fixture account missing; see tests/Fixtures/v7/README.md.');
        }

        $landId = DB::table('land')->where('land_name', 'Contract Fixture Land')->value('land_id');

        if ($landId === null) {
            $this->markTestSkipped('Fixture listing missing; see tests/Fixtures/v7/README.md.');
        }

        $this->user = $user;
        $this->landId = (int) $landId;

        $this->otherUserId = (int) DB::table('user')
            ->where('id', '!=', $user->id)
            ->value('id');

        $this->reset();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->landId)) {
            $this->reset();
        }

        parent::tearDown();
    }

    /** A private listing taking two people, managed by its owner. */
    private function reset(array $overrides = []): void
    {
        DB::table('requests')->where('requester_id', $this->user->id)->delete();
        DB::table('requests')->where('land_id', $this->landId)->delete();
        DB::table('land_blocked_days')->where('land_id', $this->landId)->delete();
        DB::table('land_access_limits')->where('land_id', $this->landId)->delete();

        DB::table('land')->where('land_id', $this->landId)->update($overrides + [
            'people_allowed' => 2,
            'is_open_property' => 0,
            'auto_approve_requests' => 'No',
            'source' => '',
            'contact_email' => '',
            'contact_phone' => '',
            'allow_guide' => 'No',
            'allow_unlimited_access' => 'No',
            'supersede_emails_1' => null,
            'supersede_emails_2' => null,
            'guide_number_1' => null,
        ]);
    }

    private function submit(array $fields): TestResponse
    {
        return $this->postJson('/api/v7/requests/submit', $fields + [
            'token' => $this->user->auth_key,
            'land_id' => $this->landId,
            'reason' => 'Hunting',
        ]);
    }

    public function test_a_stay_creates_one_request_per_day(): void
    {
        $this->submit(['start' => '2031-07-01', 'end' => '2031-07-03'])
            ->assertStatus(201)
            ->assertJsonPath('data.days', 3);

        $this->assertSame(3, DB::table('requests')
            ->where('requester_id', $this->user->id)
            ->where('land_id', $this->landId)
            ->count());
    }

    public function test_a_blocked_day_refuses_the_whole_stay(): void
    {
        DB::table('land_blocked_days')->insert([
            'land_id' => $this->landId,
            'blocked_date' => '2031-07-02',
        ]);

        $this->submit(['start' => '2031-07-01', 'end' => '2031-07-03'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'This land is not available on the day Jul 02, 2031');

        $this->assertSame(0, DB::table('requests')->where('requester_id', $this->user->id)->count(),
            'A stay must not be half-booked when one of its days is unavailable.');
    }

    public function test_a_full_day_is_refused(): void
    {
        // people_allowed is 2, and this booking takes both.
        DB::table('requests')->insert([
            'requester_id' => $this->otherUserId, 'land_id' => $this->landId, 'reason' => 'Hunting',
            'request_date' => '2031-07-05', 'status' => 'Accepted', 'people_count' => 2,
            'requested_at' => time(),
        ]);

        $this->submit(['start' => '2031-07-05', 'end' => '2031-07-05'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'This land is fully booked on Jul 05, 2031');

        DB::table('requests')->where('requester_id', $this->otherUserId)->delete();
    }

    public function test_auto_approving_listings_accept_immediately(): void
    {
        $this->reset(['auto_approve_requests' => 'Yes']);

        $this->submit(['start' => '2031-07-07', 'end' => '2031-07-07'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'Accepted');

        $row = DB::table('requests')->where('requester_id', $this->user->id)->first();
        $this->assertNotNull($row->decision_at, 'An auto-approved request records when it was decided.');
    }

    /**
     * An open property counts vehicles against a per-activity allowance rather
     * than people against one total.
     */
    public function test_an_open_property_limits_each_activity_separately(): void
    {
        $this->reset(['is_open_property' => 1]);

        DB::table('land_access_limits')->insert([
            'land_id' => $this->landId, 'hunting' => 1, 'fishing' => 5,
        ]);

        // The single hunting unit is taken.
        DB::table('requests')->insert([
            'requester_id' => $this->otherUserId, 'land_id' => $this->landId, 'reason' => 'Hunting',
            'request_date' => '2031-08-01', 'status' => 'Accepted', 'units_count' => 1,
            'requested_at' => time(),
        ]);

        $this->submit(['start' => '2031-08-01', 'end' => '2031-08-01', 'units_count' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error', 'This land is fully booked for Hunting on Aug 01, 2031');

        // Fishing is a different allowance and is still open.
        $this->submit(['reason' => 'Fishing', 'start' => '2031-08-01', 'end' => '2031-08-01', 'units_count' => 1])
            ->assertStatus(201);

        DB::table('requests')->where('requester_id', $this->otherUserId)->delete();
    }

    /** A blocked day on an open property replaces its allowances rather than closing it. */
    public function test_a_blocked_day_on_an_open_property_overrides_the_allowance(): void
    {
        $this->reset(['is_open_property' => 1]);

        DB::table('land_access_limits')->insert(['land_id' => $this->landId, 'hunting' => 5]);
        DB::table('land_blocked_days')->insert([
            'land_id' => $this->landId,
            'blocked_date' => '2031-08-10',
            'open_property_limits' => json_encode(['hunting' => 0]),
        ]);

        $this->submit(['start' => '2031-08-10', 'end' => '2031-08-10', 'units_count' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error', 'This land is not available for Hunting on Aug 10, 2031');
    }

    /** A caller on the supersede list skips availability altogether. */
    public function test_a_supersede_caller_books_a_blocked_day(): void
    {
        $this->reset([
            'allow_unlimited_access' => 'Yes',
            'supersede_emails_2' => $this->user->email,
        ]);

        DB::table('land_blocked_days')->insert([
            'land_id' => $this->landId, 'blocked_date' => '2031-09-01',
        ]);

        $this->submit(['start' => '2031-09-01', 'end' => '2031-09-01'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'Pending');

        $row = DB::table('requests')->where('requester_id', $this->user->id)->first();
        $this->assertSame(1, (int) $row->is_supersede_request,
            'A supersede booking is flagged as one, not as a guide request.');
    }

    /** The first supersede list auto-approves; the second does not. */
    public function test_the_first_supersede_list_auto_approves(): void
    {
        $this->reset([
            'allow_unlimited_access' => 'Yes',
            'supersede_emails_1' => $this->user->email,
        ]);

        $this->submit(['start' => '2031-09-05', 'end' => '2031-09-05'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'Accepted');
    }

    public function test_an_unknown_listing_is_404(): void
    {
        $this->postJson('/api/v7/requests/submit', [
            'token' => $this->user->auth_key, 'land_id' => 99999999,
            'reason' => 'Hunting', 'start' => '2031-10-01', 'end' => '2031-10-01',
        ])->assertStatus(404);
    }

    public function test_submitting_requires_a_token(): void
    {
        $this->postJson('/api/v7/requests/submit', [
            'land_id' => $this->landId, 'reason' => 'Hunting',
            'start' => '2031-10-01', 'end' => '2031-10-01',
        ])->assertStatus(401);
    }
}
