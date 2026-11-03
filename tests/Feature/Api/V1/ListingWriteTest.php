<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The write endpoints.
 *
 * These share the `clas` database with the old application, so the rows they
 * create must be indistinguishable from the ones it creates — a booking or a
 * blocked day has to read correctly through either implementation.
 *
 * Unlike the read tests, these mutate. Everything they touch belongs to a
 * fixture account and a fixture listing, and tearDown removes it, so a failed
 * run does not leave state behind for the next one.
 */
class ListingWriteTest extends TestCase
{
    private ?object $user = null;

    private ?int $ownedLandId = null;

    /** A public listing the fixture account does not own. */
    private ?int $otherLandId = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => env('CONTRACT_DB', 'clas'),
        ]);

        try {
            $this->user = DB::table('user')->where('username', 'contract_fixture')->first();
        } catch (\Throwable $e) {
            $this->markTestSkipped('clas database not reachable: '.$e->getMessage());
        }

        if ($this->user === null) {
            $this->markTestSkipped('Fixture account missing; see tests/Fixtures/v1/README.md.');
        }

        $this->ownedLandId = DB::table('land')
            ->where('owner', $this->user->id)
            ->where('land_name', 'Contract Fixture Land')
            ->value('land_id');

        if ($this->ownedLandId === null) {
            $this->markTestSkipped('Fixture listing missing; see tests/Fixtures/v1/README.md.');
        }

        $this->otherLandId = DB::table('land')
            ->where('active', 'Y')
            ->where('visibility_status', 'public')
            ->where('owner', '!=', $this->user->id)
            ->value('land_id');

        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        if ($this->user !== null && $this->ownedLandId !== null) {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    private function cleanUp(): void
    {
        DB::table('land_blocked_days')
            ->where('land_id', $this->ownedLandId)
            ->where('blocked_date', '>=', '2030-01-01')
            ->delete();

        DB::table('land_notification_subscriptions')
            ->where('user_id', $this->user->id)
            ->delete();
    }

    public function test_subscribe_then_unsubscribe_round_trips(): void
    {
        $this->post('/v1/listings/subscribe', $this->body(['id' => $this->otherLandId]))
            ->assertOk()
            ->assertJsonPath('data.item.is_subscribed', true);

        $this->assertDatabaseHas('land_notification_subscriptions', [
            'land_id' => $this->otherLandId,
            'user_id' => $this->user->id,
        ]);

        $this->post('/v1/listings/unsubscribe', $this->body(['id' => $this->otherLandId]))
            ->assertOk()
            ->assertJsonPath('data.item.is_subscribed', false);

        $this->assertDatabaseMissing('land_notification_subscriptions', [
            'land_id' => $this->otherLandId,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_subscribing_twice_conflicts_rather_than_duplicating(): void
    {
        $this->post('/v1/listings/subscribe', $this->body(['id' => $this->otherLandId]))
            ->assertOk();

        $this->post('/v1/listings/subscribe', $this->body(['id' => $this->otherLandId]))
            ->assertStatus(409);

        $this->assertSame(1, DB::table('land_notification_subscriptions')
            ->where('land_id', $this->otherLandId)
            ->where('user_id', $this->user->id)
            ->count(), 'A conflicting subscribe must not insert a second row.');
    }

    public function test_unsubscribing_when_not_subscribed_conflicts(): void
    {
        $this->post('/v1/listings/unsubscribe', $this->body(['id' => $this->otherLandId]))
            ->assertStatus(409);
    }

    public function test_blocking_a_listing_you_do_not_own_is_forbidden(): void
    {
        $this->post('/v1/listings/block-day', $this->body([
            'land_id' => $this->otherLandId,
            'date' => '2030-03-01',
        ]))->assertStatus(403);

        $this->assertDatabaseMissing('land_blocked_days', [
            'land_id' => $this->otherLandId,
            'blocked_date' => '2030-03-01',
        ]);
    }

    public function test_block_and_unblock_a_day(): void
    {
        $this->post('/v1/listings/block-day', $this->body([
            'land_id' => $this->ownedLandId,
            'date' => '2030-03-02',
        ]))->assertStatus(201)->assertJsonPath('data.blocked_day.blocked_date', '2030-03-02');

        $this->assertDatabaseHas('land_blocked_days', [
            'land_id' => $this->ownedLandId,
            'blocked_date' => '2030-03-02',
        ]);

        $this->post('/v1/listings/unblock-day', $this->body([
            'land_id' => $this->ownedLandId,
            'date' => '2030-03-02',
        ]))->assertOk()->assertJsonPath('data.unblocked', 1);

        $this->assertDatabaseMissing('land_blocked_days', [
            'land_id' => $this->ownedLandId,
            'blocked_date' => '2030-03-02',
        ]);
    }

    public function test_blocking_the_same_day_twice_conflicts(): void
    {
        $body = $this->body(['land_id' => $this->ownedLandId, 'date' => '2030-03-03']);

        $this->post('/v1/listings/block-day', $body)->assertStatus(201);
        $this->post('/v1/listings/block-day', $body)->assertStatus(409);

        $this->assertSame(1, DB::table('land_blocked_days')
            ->where('land_id', $this->ownedLandId)
            ->where('blocked_date', '2030-03-03')
            ->count());
    }

    public function test_a_bad_date_format_is_rejected_before_any_write(): void
    {
        $this->post('/v1/listings/block-day', $this->body([
            'land_id' => $this->ownedLandId,
            'date' => '02-03-2030',
        ]))->assertStatus(422)->assertJsonStructure(['errors' => ['date']]);

        $this->assertSame(0, DB::table('land_blocked_days')
            ->where('land_id', $this->ownedLandId)
            ->where('blocked_date', '>=', '2030-01-01')
            ->count());
    }

    public function test_blocking_several_days_skips_the_ones_already_blocked(): void
    {
        $this->post('/v1/listings/block-multiple-days', $this->body([
            'land_id' => $this->ownedLandId,
            'dates' => ['2030-04-01', '2030-04-02', '2030-04-01'],
        ]))
            ->assertOk()
            ->assertJsonPath('data.blocked', ['2030-04-01', '2030-04-02'])
            ->assertJsonPath('data.skipped', []);

        // Retrying a partially applied batch should converge, not fail.
        $this->post('/v1/listings/block-multiple-days', $this->body([
            'land_id' => $this->ownedLandId,
            'dates' => ['2030-04-02', '2030-04-03'],
        ]))
            ->assertOk()
            ->assertJsonPath('data.blocked', ['2030-04-03'])
            ->assertJsonPath('data.skipped', ['2030-04-02']);

        $this->assertSame(3, DB::table('land_blocked_days')
            ->where('land_id', $this->ownedLandId)
            ->where('blocked_date', '>=', '2030-01-01')
            ->count(), 'A duplicate date in one batch must insert once.');
    }

    public function test_an_empty_date_list_is_rejected(): void
    {
        $this->post('/v1/listings/block-multiple-days', $this->body([
            'land_id' => $this->ownedLandId,
            'dates' => [],
        ]))->assertStatus(422)->assertJsonStructure(['errors' => ['dates']]);
    }

    /**
     * The old application also writes to this table. A row created here must
     * carry the same columns it would have set: land_id, blocked_date, no
     * limits for a private listing, and `created` from the column default.
     */
    public function test_the_row_written_matches_what_the_old_api_writes(): void
    {
        $this->post('/v1/listings/block-day', $this->body([
            'land_id' => $this->ownedLandId,
            'date' => '2030-05-01',
            // Sent deliberately: the fixture listing is not an open property,
            // so the limits must be ignored rather than stored.
            'open_property_limits' => ['hunting' => 3],
        ]))->assertStatus(201);

        $row = DB::table('land_blocked_days')
            ->where('land_id', $this->ownedLandId)
            ->where('blocked_date', '2030-05-01')
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row->open_property_limits,
            'Limits belong to open properties only.');
        $this->assertNotNull($row->created,
            '`created` comes from the column default, as it did before.');
    }

    private function body(array $fields): array
    {
        return $fields + ['token' => $this->user->auth_key];
    }
}
