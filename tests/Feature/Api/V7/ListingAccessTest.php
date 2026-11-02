<?php

namespace Tests\Feature\Api\V7;

use App\Support\V7\ListingAccess;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Who counts as able to manage a listing.
 *
 * `land_managers` rows carry a status. An invitation that was sent and never
 * taken up is `pending`, and must not grant anything — only `accepted` does,
 * as Land::isManager() defined it in the old application. There are pending
 * rows in the database, so this is a live distinction and not a hypothetical.
 *
 * Read-only.
 */
class ListingAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => env('CONTRACT_DB', 'clas'),
        ]);

        try {
            DB::table('land')->limit(1)->first();
        } catch (\Throwable $e) {
            $this->markTestSkipped('clas database not reachable: '.$e->getMessage());
        }
    }

    public function test_a_pending_manager_invitation_grants_nothing(): void
    {
        $pending = DB::table('land_managers')
            ->where('status', 'pending')
            ->first();

        if ($pending === null) {
            $this->markTestSkipped('No pending manager invitation to test against.');
        }

        $isOwner = DB::table('land')
            ->where('land_id', $pending->land_id)
            ->where('owner', $pending->user_id)
            ->exists();

        if ($isOwner) {
            $this->markTestSkipped('That pending manager also owns the listing.');
        }

        $this->assertFalse(
            ListingAccess::canManage((int) $pending->user_id, (int) $pending->land_id),
            'A pending invitation must not grant access to the listing.'
        );
    }

    public function test_an_accepted_manager_can_manage(): void
    {
        $accepted = DB::table('land_managers')
            ->where('status', 'accepted')
            ->first();

        if ($accepted === null) {
            $this->markTestSkipped('No accepted manager to test against.');
        }

        $this->assertTrue(
            ListingAccess::canManage((int) $accepted->user_id, (int) $accepted->land_id),
            'An accepted manager must be able to manage the listing.'
        );
    }

    public function test_an_owner_can_manage_their_own_listing(): void
    {
        $land = DB::table('land')->whereNotNull('owner')->where('owner', '>', 0)->first();

        $this->assertTrue(ListingAccess::canManage((int) $land->owner, (int) $land->land_id));
        $this->assertTrue(ListingAccess::isOwner((int) $land->owner, (int) $land->land_id));
    }

    /**
     * Managing is not owning. Writes stay owner-only, so an accepted manager
     * must pass canManage and fail isOwner.
     */
    public function test_a_manager_is_not_an_owner(): void
    {
        $accepted = DB::table('land_managers')
            ->join('land', 'land.land_id', '=', 'land_managers.land_id')
            ->where('land_managers.status', 'accepted')
            ->whereColumn('land.owner', '!=', 'land_managers.user_id')
            ->select(['land_managers.user_id', 'land_managers.land_id'])
            ->first();

        if ($accepted === null) {
            $this->markTestSkipped('No manager who is not also the owner.');
        }

        $this->assertTrue(ListingAccess::canManage((int) $accepted->user_id, (int) $accepted->land_id));
        $this->assertFalse(ListingAccess::isOwner((int) $accepted->user_id, (int) $accepted->land_id),
            'A manager must not pass the owner-only check used by the write endpoints.');
    }

    public function test_an_unrelated_user_can_manage_nothing(): void
    {
        $outsider = DB::table('user')->where('username', 'contract_fixture')->first();

        if ($outsider === null) {
            $this->markTestSkipped('Fixture account missing.');
        }

        $foreign = DB::table('land')
            ->where('owner', '!=', $outsider->id)
            ->whereNotIn('land_id', function ($q) use ($outsider) {
                $q->select('land_id')->from('land_managers')->where('user_id', $outsider->id);
            })
            ->value('land_id');

        $this->assertFalse(ListingAccess::canManage((int) $outsider->id, (int) $foreign));
    }
}
