<?php

namespace Tests\Feature\Api\V7;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Checks the rebuilt endpoints against the Yii v7 originals.
 *
 * The apps in the field cannot be patched from the server, so this compares
 * the key set and the JSON type of every value, not just the status code.
 * A count returned as 0 instead of "0" is a broken app, and is a failure here.
 *
 * Requires the legacy app to be reachable. Point CONTRACT_OLD_BASE at it
 * (defaults to the local Laragon copy); the test skips if it is not up, so
 * CI without the old stack still runs green rather than red.
 */
class ContractParityTest extends TestCase
{
    private const VOLATILE = ['token', 'deactivation_date'];

    private string $oldBase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml points the suite at in-memory sqlite, which has none of
        // the legacy schema. Parity checks read the real tables instead — read
        // only: nothing in these tests may write to the clas database.
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => env('CONTRACT_DB', 'clas'),
        ]);

        $this->oldBase = rtrim(env('CONTRACT_OLD_BASE', 'http://clas.test'), '/');

        if (! $this->legacyIsReachable()) {
            $this->markTestSkipped(
                'Legacy v7 not reachable at '.$this->oldBase.' - set CONTRACT_OLD_BASE.'
            );
        }
    }

    public static function caseProvider(): array
    {
        // Data providers run before the application boots, so base_path() is
        // not available here.
        $cases = require dirname(__DIR__, 4).'/tools/contract-cases.php';

        return array_map(fn ($case) => [$case], $cases);
    }

    #[DataProvider('caseProvider')]
    public function test_rebuilt_endpoint_matches_v7(array $case): void
    {
        $old = $this->callLegacy($case);

        $new = $case['method'] === 'POST'
            ? $this->postJson('/'.$case['path'], $case['body'] ?? [])
            : $this->getJson('/'.$case['path']);

        $expected = $this->shape($old);
        $actual = $this->shape($new->json());

        $this->assertSame(
            array_keys($expected),
            array_keys($actual),
            'Top-level keys differ for '.$case['path']
        );

        $this->assertSame(
            $expected,
            $actual,
            'Response shape or value types differ from v7 for '.$case['path']
        );
    }

    /** Replace each value with its JSON type so types are compared, not just keys. */
    private function shape(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $inner) {
                $out[$key] = in_array($key, self::VOLATILE, true)
                    ? '<volatile>'
                    : $this->shape($inner);
            }

            return $out;
        }

        return match (true) {
            is_bool($value) => 'bool:'.($value ? 'true' : 'false'),
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_null($value) => 'null',
            default => 'string',
        };
    }

    private function callLegacy(array $case): array
    {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL => $this->oldBase.'/'.ltrim($case['path'], '/'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ];

        if (($case['method'] ?? 'GET') === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($case['body'] ?? []);
        }

        curl_setopt_array($ch, $opts);
        $body = (string) curl_exec($ch);

        return json_decode($body, true) ?? [];
    }

    private function legacyIsReachable(): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->oldBase,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_NOBODY => true,
        ]);
        curl_exec($ch);

        return curl_getinfo($ch, CURLINFO_HTTP_CODE) > 0;
    }
}
