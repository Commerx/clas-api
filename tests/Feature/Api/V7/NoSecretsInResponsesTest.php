<?php

namespace Tests\Feature\Api\V7;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * No response may carry a secret or an internal field.
 *
 * The old API selected `land.*` and `requests.* , profile.*` straight into its
 * responses, which published whatever those tables happened to contain. Two
 * consequences were live:
 *
 *  - `claim_code`, set on 6,131 listings, is the secret that lets whoever
 *    presents it take ownership of a listing. It was readable by any
 *    authenticated caller through listings/detail.
 *  - requests/detail returned any request to any caller, including the
 *    requester's name, phone, address, coordinates and driver licence number.
 *
 * Both are closed. This test exists so a future `select('*')` does not quietly
 * reopen them — it sweeps the read endpoints and fails on any field whose name
 * says it should not be there.
 */
class NoSecretsInResponsesTest extends TestCase
{
    /** Never acceptable in a response, whatever the endpoint. */
    private const FORBIDDEN = [
        'claim_code',
        'password_hash',
        'auth_key',
        'supersede_emails_1',
        'supersede_emails_2',
        'txn_number',
        'customer_id',
        'order_id',
        'signage_promocode',
        'driver_license_number',
        'registration_ip',
    ];

    private ?object $user = null;

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
            $this->markTestSkipped('Fixture account missing; see tests/Fixtures/v7/README.md.');
        }
    }

    public static function readEndpoints(): array
    {
        return [
            'listings/all' => ['listings/all', ['limit' => 5]],
            'listings/geofence' => ['listings/geofence-listings', ['limit' => 5]],
            'listings/search' => ['listings/search', ['limit' => 5]],
            'requests/list' => ['requests/list', ['limit' => 5]],
            'requests/bookings' => ['requests/bookings', ['limit' => 5]],
            'notifications/list' => ['notifications/list', ['limit' => 5]],
        ];
    }

    #[DataProvider('readEndpoints')]
    public function test_no_forbidden_field_appears(string $path, array $params): void
    {
        $body = $this->getJson($this->url($path, $params))->assertOk()->json();

        foreach ($this->keysIn($body) as $key) {
            $this->assertNotContains(
                $key,
                self::FORBIDDEN,
                sprintf('%s returned the field "%s", which must never be published.', $path, $key)
            );
        }
    }

    /**
     * listings/detail is the one that leaked claim_code, so it gets its own
     * check against a listing that actually has one.
     */
    public function test_listing_detail_withholds_the_claim_code(): void
    {
        $landId = DB::table('land')
            ->where('active', 'Y')
            ->whereNotNull('claim_code')
            ->where('claim_code', '!=', '')
            ->value('land_id');

        if ($landId === null) {
            $this->markTestSkipped('No listing with a claim_code to test against.');
        }

        $item = $this->getJson($this->url('listings/detail', ['id' => $landId]))
            ->assertOk()
            ->json('data.item');

        $this->assertArrayNotHasKey('claim_code', $item,
            'claim_code grants ownership of a listing and must never be returned.');

        foreach (['payment_status', 'total_amount', 'paid_amount', 'current_step'] as $internal) {
            $this->assertArrayNotHasKey($internal, $item,
                sprintf('"%s" is internal and should not be published.', $internal));
        }
    }

    /** @return list<string> every key appearing anywhere in the structure */
    private function keysIn(mixed $value, array $found = []): array
    {
        if (! is_array($value)) {
            return $found;
        }

        foreach ($value as $key => $inner) {
            if (is_string($key)) {
                $found[] = $key;
            }

            $found = $this->keysIn($inner, $found);
        }

        return array_values(array_unique($found));
    }

    private function url(string $path, array $params): string
    {
        return '/api/v7/'.$path.'?'.http_build_query(
            $params + ['token' => $this->user->auth_key]
        );
    }
}
