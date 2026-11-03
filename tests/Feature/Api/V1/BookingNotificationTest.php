<?php

namespace Tests\Feature\Api\V1;

use App\Mail\RequestCancelled;
use App\Mail\RequestDecided;
use App\Mail\RequestMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Every booking transition tells someone.
 *
 * This matters more than it looks. Both applications write the same database,
 * so if a decision made through this API sends nothing, an owner sees a
 * booking change with no word about it — and a cancelled day looks occupied.
 *
 * Mail is faked, so nothing leaves the machine. The assertions are about who
 * is addressed, not what the body says.
 */
class BookingNotificationTest extends TestCase
{
    private object $user;

    private int $landId;

    private int $requestId;

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

        $landId = DB::table('land')
            ->where('owner', $user->id)
            ->where('land_name', 'Contract Fixture Land')
            ->value('land_id');

        if ($landId === null) {
            $this->markTestSkipped('Fixture listing missing; see tests/Fixtures/v1/README.md.');
        }

        $this->user = $user;
        $this->landId = (int) $landId;

        // The fixture account owns the listing and makes the request, so one
        // account can drive both sides of the lifecycle.
        DB::table('requests')->where('requester_id', $user->id)->where('land_id', $landId)->delete();

        $this->requestId = (int) DB::table('requests')->insertGetId([
            'requester_id' => $user->id,
            'land_id' => $landId,
            'reason' => 'Hunting',
            'request_date' => date('Y-m-d'),
            'status' => 'Pending',
            'requested_at' => time(),
        ]);

        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->requestId)) {
            DB::table('requests')->where('request_id', $this->requestId)->delete();
        }

        parent::tearDown();
    }

    public function test_accepting_notifies_the_requester(): void
    {
        $this->post('/v1/requests/accept', $this->body())->assertOk();

        Mail::assertSent(RequestDecided::class, function ($mail) {
            return $mail->hasTo($this->user->email)
                && $mail->decision === 'accepted';
        });
    }

    public function test_declining_notifies_the_requester(): void
    {
        $this->post('/v1/requests/decline', $this->body())->assertOk();

        Mail::assertSent(RequestDecided::class,
            fn ($mail) => $mail->decision === 'declined');
    }

    public function test_cancelling_notifies_the_owner(): void
    {
        $this->post('/v1/requests/cancel', $this->body())->assertOk();

        Mail::assertSent(RequestCancelled::class,
            fn ($mail) => $mail->hasTo($this->user->email));
    }

    public function test_checking_in_notifies_the_owner(): void
    {
        DB::table('requests')->where('request_id', $this->requestId)->update(['status' => 'Accepted']);

        $this->post('/v1/requests/checkin', $this->body())->assertOk();

        Mail::assertSent(RequestMovement::class, fn ($mail) => $mail->movement === 'in');
    }

    public function test_checking_out_notifies_the_owner_and_carries_the_report(): void
    {
        DB::table('requests')->where('request_id', $this->requestId)->update(['status' => 'Checked In']);

        $this->post('/v1/requests/checkout', $this->body([
            'harvest_animal' => 'Yes',
            'suspicious_activity' => 'Gate left open',
            'category' => 'Big Game',
        ]))->assertOk();

        Mail::assertSent(RequestMovement::class, function ($mail) {
            return $mail->movement === 'out'
                && $mail->huntingCategory === 'Big Game';
        });
    }

    /** A failed transition must not notify anyone. */
    public function test_a_rejected_transition_sends_nothing(): void
    {
        // Checking in before acceptance is refused.
        $this->post('/v1/requests/checkin', $this->body())->assertStatus(409);

        Mail::assertNothingSent();
    }

    public function test_marking_read_sends_nothing(): void
    {
        $this->post('/v1/requests/read', $this->body())->assertOk();

        Mail::assertNothingSent();
    }

    /**
     * The blind-copy list is configuration now, not two addresses buried in a
     * controller. Empty by default, so nobody is copied unless asked for.
     */
    public function test_the_movement_bcc_comes_from_config(): void
    {
        config(['notifications.movement_bcc' => ['ops@example.invalid']]);

        DB::table('requests')->where('request_id', $this->requestId)->update(['status' => 'Accepted']);
        $this->post('/v1/requests/checkin', $this->body())->assertOk();

        Mail::assertSent(RequestMovement::class,
            fn ($mail) => $mail->hasBcc('ops@example.invalid'));
    }

    private function body(array $extra = []): array
    {
        return $extra + [
            'token' => $this->user->auth_key,
            'request_id' => $this->requestId,
        ];
    }
}
