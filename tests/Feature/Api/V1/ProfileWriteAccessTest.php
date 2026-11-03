<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Nobody may write to a profile that is not their own.
 *
 * This is the regression test for the worst defect found in the old API. All
 * three of its profile update actions were written like this:
 *
 *     $user = User::findOne(['auth_key' => $auth_key, 'id' => $uid]);
 *     $profile = Profile::findOne(['user_id' => $uid]);
 *     $profile->load($postedData);
 *     $profile->save();
 *
 * `$user` is assigned and never read, and the controller carries no access
 * filter, so the token was never checked by anything. The profile written was
 * whichever one `uid` named. A request with any `uid` and a junk token
 * rewrote that person's name, address, phone and driver's licence number.
 *
 * The rebuilt endpoints take the account from the token and nothing else, so
 * the cases below are about proving that `uid` cannot steer a write. Each one
 * asserts on the victim's stored row rather than on the status code, because
 * a refusal that still wrote would otherwise pass.
 */
class ProfileWriteAccessTest extends TestCase
{
    private object $caller;

    private int $victimId;

    private array $victimBefore;

    /** The endpoints that take profile writes, and a valid body for each. */
    private const WRITES = [
        'user/update-address' => [
            'first_name' => 'Mallory', 'last_name' => 'Rewrite',
            'street' => '1 Attack Lane', 'city' => 'Calgary', 'province' => 'AB',
            'country' => 'Canada', 'postal_code' => 'T2P1J9', 'phone' => '4035551234',
        ],
        'user/update-vehicle-info' => [
            'driver_license_number' => 'TAMPERED-0001',
        ],
        'user/update-profile' => [
            'first_name' => 'Mallory', 'last_name' => 'Rewrite',
            'street' => '1 Attack Lane', 'city' => 'Calgary', 'province' => 'AB',
            'country' => 'Canada', 'postal_code' => 'T2P1J9', 'phone' => '4035551234',
            'driver_license_number' => 'TAMPERED-0002',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => env('CONTRACT_DB', 'clas'),
        ]);

        try {
            $caller = DB::table('user')->where('username', 'contract_fixture')->first();
        } catch (\Throwable $e) {
            $this->markTestSkipped('clas database not reachable: '.$e->getMessage());
        }

        if ($caller === null) {
            $this->markTestSkipped('Fixture account missing; see tests/Fixtures/v1/README.md.');
        }

        $victim = DB::table('profile')
            ->where('user_id', '!=', $caller->id)
            ->whereNotNull('name')
            ->first();

        if ($victim === null) {
            $this->markTestSkipped('No second profile to attempt a write against.');
        }

        $this->caller = $caller;
        $this->victimId = (int) $victim->user_id;
        $this->victimBefore = (array) $victim;
    }

    protected function tearDown(): void
    {
        // Nothing here should have changed the victim's row. If a test fails
        // because something did, restore it rather than leaving the damage.
        if (isset($this->victimId)) {
            DB::table('profile')->where('user_id', $this->victimId)->update($this->victimBefore);
        }

        parent::tearDown();
    }

    /**
     * The old exploit exactly: no token at all, somebody else's uid.
     *
     * The middleware refuses this before the controller runs, which is the
     * structural part of the fix — there is no code path to a profile write
     * that has not identified the caller first.
     */
    public function test_an_unauthenticated_request_cannot_write_to_a_profile(): void
    {
        foreach (self::WRITES as $endpoint => $body) {
            $this->postJson('/v1/'.$endpoint, $body + ['uid' => $this->victimId])
                ->assertStatus(401);

            $this->assertVictimUnchanged($endpoint);
        }
    }

    /** A made-up token is not a token. */
    public function test_a_forged_token_cannot_write_to_a_profile(): void
    {
        foreach (self::WRITES as $endpoint => $body) {
            $this->postJson('/v1/'.$endpoint, $body + [
                'uid' => $this->victimId,
                'token' => 'not-a-real-token-0000000000000000',
            ])->assertStatus(401);

            $this->assertVictimUnchanged($endpoint);
        }
    }

    /**
     * A real token belonging to somebody else.
     *
     * The mismatch is rejected outright rather than quietly applied to the
     * token holder, so a client sending the wrong pair is told so.
     */
    public function test_a_valid_token_cannot_write_to_another_users_profile(): void
    {
        foreach (self::WRITES as $endpoint => $body) {
            $this->postJson('/v1/'.$endpoint, $body + [
                'uid' => $this->victimId,
                'token' => $this->caller->auth_key,
            ])->assertStatus(401);

            $this->assertVictimUnchanged($endpoint);
        }
    }

    /**
     * And with no uid at all, the write still lands on the token holder
     * rather than anywhere else.
     */
    public function test_a_write_without_a_uid_applies_to_the_token_holder(): void
    {
        $before = DB::table('profile')->where('user_id', $this->caller->id)->first();

        $this->postJson('/v1/user/update-vehicle-info', [
            'token' => $this->caller->auth_key,
            'driver_license_number' => 'OWN-WRITE-OK',
        ])->assertOk();

        $this->assertSame('OWN-WRITE-OK', DB::table('profile')
            ->where('user_id', $this->caller->id)
            ->value('driver_license_number'));

        $this->assertVictimUnchanged('user/update-vehicle-info');

        if ($before !== null) {
            DB::table('profile')->where('user_id', $this->caller->id)->update((array) $before);
        }
    }

    private function assertVictimUnchanged(string $endpoint): void
    {
        $now = (array) DB::table('profile')->where('user_id', $this->victimId)->first();

        $this->assertSame(
            $this->victimBefore,
            $now,
            $endpoint.' wrote to a profile belonging to somebody else.',
        );
    }
}
