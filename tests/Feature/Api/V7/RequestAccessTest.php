<?php

namespace Tests\Feature\Api\V7;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Who may see a request.
 *
 * The old API answered `requests/detail` for any request to any authenticated
 * caller, returning the requester's name, phone, street, postcode,
 * coordinates, vehicle details and driver licence number. These tests exist so
 * that does not come back.
 *
 * Read-only: nothing here writes.
 */
class RequestAccessTest extends TestCase
{
    private ?object $outsider = null;

    private ?int $foreignRequestId = null;

    private ?int $foreignLandId = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => env('CONTRACT_DB', 'clas'),
        ]);

        try {
            $this->outsider = DB::table('user')->where('username', 'contract_fixture')->first();
        } catch (\Throwable $e) {
            $this->markTestSkipped('clas database not reachable: '.$e->getMessage());
        }

        if ($this->outsider === null) {
            $this->markTestSkipped('Fixture account missing; see tests/Fixtures/v7/README.md.');
        }

        // A request the fixture account neither made nor has any listing claim to.
        $row = DB::table('requests')
            ->join('land', 'requests.land_id', '=', 'land.land_id')
            ->leftJoin('land_managers', function ($join) {
                $join->on('land_managers.land_id', '=', 'land.land_id')
                    ->where('land_managers.user_id', '=', $this->outsider->id);
            })
            ->where('requests.requester_id', '!=', $this->outsider->id)
            ->where('land.owner', '!=', $this->outsider->id)
            ->whereNull('land_managers.user_id')
            ->select(['requests.request_id', 'requests.land_id'])
            ->first();

        if ($row === null) {
            $this->markTestSkipped('No unrelated request available to test against.');
        }

        $this->foreignRequestId = (int) $row->request_id;
        $this->foreignLandId = (int) $row->land_id;
    }

    public function test_a_request_belonging_to_someone_else_is_not_readable(): void
    {
        $this->getJson($this->url('detail', ['request_id' => $this->foreignRequestId]))
            ->assertStatus(404)
            ->assertJsonPath('data', null);
    }

    public function test_bookings_for_a_listing_the_caller_cannot_access_are_hidden(): void
    {
        $this->getJson($this->url('listing-bookings', ['id' => $this->foreignLandId]))
            ->assertStatus(404);
    }

    public function test_a_requester_can_read_their_own_request(): void
    {
        $own = DB::table('requests')
            ->where('requester_id', $this->outsider->id)
            ->value('request_id');

        if ($own === null) {
            $this->markTestSkipped('The fixture account has made no requests.');
        }

        $this->getJson($this->url('detail', ['request_id' => $own]))
            ->assertOk()
            ->assertJsonPath('data.item.requester_id', (int) $this->outsider->id);
    }

    public function test_unread_counts_are_integers(): void
    {
        $data = $this->getJson($this->url('unread-counts', []))->assertOk()->json('data');

        $this->assertIsInt($data['requester_unread'], 'The old API returned a string.');
        $this->assertIsInt($data['owner_unread']);
    }

    public function test_the_caller_only_sees_their_own_requests_in_their_list(): void
    {
        $items = $this->getJson($this->url('list', ['limit' => 25]))->assertOk()->json('data.items');

        foreach ($items as $item) {
            $this->assertSame((int) $this->outsider->id, $item['requester_id'] ?? (int) $this->outsider->id);
        }

        $ids = array_column($items, 'request_id');
        $this->assertNotContains($this->foreignRequestId, $ids,
            'Another user\'s request must not appear in this list.');
    }

    public function test_request_rows_use_native_types(): void
    {
        $items = $this->getJson($this->url('bookings', ['limit' => 5]))->assertOk()->json('data.items');

        foreach ($items as $item) {
            $this->assertIsInt($item['request_id']);
            $this->assertIsInt($item['land_id']);
            $this->assertIsBool($item['is_user_read']);
            $this->assertIsBool($item['is_owner_read']);
            $this->assertIsArray($item['photos']);
            $this->assertIsArray($item['area']);
            $this->assertIsArray($item['points']);
        }
    }

    public function test_detail_requires_a_request_id(): void
    {
        $this->getJson($this->url('detail', []))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['request_id']]);
    }

    private function url(string $path, array $params): string
    {
        return '/api/v7/requests/'.$path.'?'.http_build_query(
            $params + ['token' => $this->outsider->auth_key]
        );
    }
}
