<?php

namespace Tests\Feature\Api\V7;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The contract this API promises, asserted against itself.
 *
 * This replaces the earlier parity test, which checked the rebuild byte for
 * byte against the old Yii API. That was the right test while the old apps
 * had to keep working against the rebuild; it stopped being the right test
 * when the new API moved to its own host and a new app build. The old API
 * is no longer the specification — these assertions are.
 *
 * Reads the live `clas` schema, because the whole API does. Nothing here
 * writes: the endpoints covered are all reads.
 */
class ApiContractTest extends TestCase
{
    private ?object $user = null;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml points the suite at in-memory sqlite, which has none of
        // the legacy schema.
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => env('CONTRACT_DB', 'clas'),
        ]);

        try {
            $this->user = \DB::table('user')
                ->where('username', 'contract_fixture')
                ->first();
        } catch (\Throwable $e) {
            $this->markTestSkipped('clas database not reachable: '.$e->getMessage());
        }

        if ($this->user === null) {
            $this->markTestSkipped(
                'Fixture account missing; see tests/Fixtures/v7/README.md.'
            );
        }
    }

    public static function authenticatedEndpoints(): array
    {
        return [
            'listings/all' => ['listings/all', ['limit' => 2]],
            'listings/geofence' => ['listings/geofence-listings', ['limit' => 2]],
            'listings/first-nations' => ['listings/first-nations-listings', []],
            'listings/search' => ['listings/search', ['q' => 'lake', 'limit' => 2]],
            'notifications/list' => ['notifications/list', ['limit' => 2]],
            'notifications/categories' => ['notifications/categories', []],
        ];
    }

    #[DataProvider('authenticatedEndpoints')]
    public function test_endpoint_returns_the_standard_envelope(string $path, array $params): void
    {
        $response = $this->getJson($this->url($path, $params));

        $response->assertOk();
        $this->assertSame(
            ['success', 'error', 'data'],
            array_keys($response->json()),
            'Envelope keys and their order are part of the contract.'
        );
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('error', '');
    }

    #[DataProvider('authenticatedEndpoints')]
    public function test_endpoint_rejects_a_missing_token(string $path, array $params): void
    {
        $this->getJson('/api/v7/'.$path.'?'.http_build_query($params))
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null);
    }

    #[DataProvider('authenticatedEndpoints')]
    public function test_endpoint_rejects_an_unknown_token(string $path, array $params): void
    {
        $this->getJson('/api/v7/'.$path.'?'.http_build_query(
            $params + ['token' => str_repeat('d', 32)]
        ))->assertStatus(401);
    }

    public function test_a_uid_that_disagrees_with_the_token_is_rejected(): void
    {
        $this->getJson($this->url('listings/all', ['uid' => 999999, 'limit' => 1]))
            ->assertStatus(401);
    }

    public function test_the_token_is_accepted_as_a_bearer_header(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->user->auth_key)
            ->getJson('/api/v7/listings/all?limit=1')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_missing_parameters_produce_422_with_field_detail(): void
    {
        $this->getJson($this->url('listings/detail', []))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'error', 'data', 'errors' => ['id']]);
    }

    public function test_an_unknown_listing_is_404_not_200(): void
    {
        $this->getJson($this->url('listings/detail', ['id' => 99999999]))
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_ids_are_integers_and_flags_are_booleans(): void
    {
        $items = $this->getJson($this->url('listings/all', ['limit' => 3]))
            ->assertOk()
            ->json('data.items');

        $this->assertNotEmpty($items, 'Expected some public listings to exist.');

        foreach ($items as $item) {
            $this->assertIsInt($item['land_id']);
            $this->assertIsInt($item['owner']);
            $this->assertIsBool($item['is_first_nations']);
            $this->assertIsBool($item['is_open_property']);
            $this->assertIsArray($item['photos'], 'photos is a list, not a joined string.');
            $this->assertIsArray($item['features']);
        }
    }

    /**
     * The old API returned only the last boundary point of each listing. Every
     * geometry field is now a list, for every row, whatever the column holds —
     * including the rows whose points column contains the string 'null', which
     * used to produce a 500.
     */
    public function test_geometry_fields_are_always_lists(): void
    {
        $items = $this->getJson($this->url('listings/geofence-listings', ['limit' => 25]))
            ->assertOk()
            ->json('data.items');

        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $this->assertIsArray($item['area'], 'area must be a list for land '.$item['land_id']);
            $this->assertIsArray($item['points'], 'points must be a list for land '.$item['land_id']);
            $this->assertIsBool($item['kml_data']);
        }
    }

    public function test_sun_details_are_computed_without_an_external_call(): void
    {
        $data = $this->getJson($this->url('listings/sun-details', [
            'lat' => 49.9, 'lng' => -114.1,
        ]))->assertOk()->json('data');

        $this->assertArrayHasKey('timezone', $data);

        foreach (['today', 'tomorrow'] as $day) {
            $this->assertMatchesRegularExpression(
                '/^\d{2}:\d{2}:\d{2}$/',
                $data[$day]['rise'],
                $day.' sunrise should be a local 24-hour time'
            );
            $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $data[$day]['set']);
        }
    }

    public function test_sun_details_rejects_out_of_range_coordinates(): void
    {
        $this->getJson($this->url('listings/sun-details', ['lat' => 999, 'lng' => -114.1]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['lat']]);
    }

    public function test_login_rejects_a_bad_password_with_401(): void
    {
        $this->postJson('/api/v7/security/login', [
            'login' => 'contract_fixture',
            'password' => 'definitely-wrong',
            'os' => 'android',
        ])->assertStatus(401)->assertJsonPath('success', false);
    }

    public function test_login_reports_missing_fields_as_422(): void
    {
        $this->postJson('/api/v7/security/login', ['login' => 'contract_fixture'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password', 'os']]);
    }

    public function test_login_returns_a_usable_token_and_native_types(): void
    {
        $data = $this->postJson('/api/v7/security/login', [
            'login' => 'contract_fixture',
            'password' => 'ContractTest123!',
            'os' => 'android',
        ])->assertOk()->json('data');

        $this->assertIsInt($data['id']);
        $this->assertIsBool($data['confirmed']);
        $this->assertIsBool($data['land_owner']);
        $this->assertIsInt($data['notifications_unread_count'],
            'The old API returned this as a string.');

        $this->withHeader('Authorization', 'Bearer '.$data['token'])
            ->getJson('/api/v7/listings/all?limit=1')
            ->assertOk();
    }

    private function url(string $path, array $params): string
    {
        return '/api/v7/'.$path.'?'.http_build_query(
            $params + ['token' => $this->user->auth_key]
        );
    }
}
