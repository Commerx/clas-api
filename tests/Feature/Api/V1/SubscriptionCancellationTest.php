<?php

namespace Tests\Feature\Api\V1;

use App\Mail\SubscriptionCancelled;
use App\Services\SubscriptionReminders;
use App\Services\SubscriptionRenewals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Cancelling a subscription, and putting it back.
 *
 * The asymmetry is the thing to get right here, and it is not obvious.
 * Cancelling a Moneris row has to write `auto_renew = 0`, because that is
 * the only signal the Yii cron reads. Resuming one has to write it back.
 * But resuming a *Stripe* row must **not**, because `auto_renew = 0` is what
 * keeps that cron away from rows this API owns — restoring it would hand the
 * row to an application that still holds the customer's Moneris card, and
 * they would be charged twice, a year later, by two different processors.
 *
 * Everything written belongs to the fixture account and is removed in
 * tearDown.
 */
class SubscriptionCancellationTest extends TestCase
{
    private object $user;

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

        if (! DB::getSchemaBuilder()->hasColumn('user_subscription', 'cancelled_at')) {
            $this->markTestSkipped('Run the cancellation migration first: '
                .'php artisan migrate --database=clas_api');
        }

        $this->user = $user;
        $this->clear();

        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->user)) {
            $this->clear();
        }

        parent::tearDown();
    }

    private function clear(): void
    {
        $ids = DB::table('user_subscription')->where('user_id', $this->user->id)
            ->pluck('subscription_id');

        if ($ids->isNotEmpty()) {
            DB::table('subscription_transactions')->whereIn('subscription_id', $ids)->delete();
            DB::connection('clas_api')->table('subscription_reminders')
                ->whereIn('subscription_id', $ids)->delete();
        }

        DB::table('user_subscription')->where('user_id', $this->user->id)->delete();
    }

    private function token(): string
    {
        return $this->user->auth_key;
    }

    /** A Stripe subscription, renewing in a week. */
    private function subscription(array $overrides = []): int
    {
        return (int) DB::table('user_subscription')->insertGetId(array_merge([
            'user_id' => $this->user->id,
            'subscription_type' => 5,
            'start_date' => now()->subYear()->format('Y-m-d'),
            'expiration_date' => now()->addDays(7)->format('Y-m-d'),
            'auto_renew' => 0,
            'gateway' => 'stripe',
            'stripe_customer_id' => 'cus_fixture',
            'stripe_payment_method_id' => 'pm_fixture',
            'status' => 'Active',
            'subscription_amount' => '9.99',
            'amount_paid' => '9.99',
            'customer_id' => 'CLAS-USER-'.$this->user->id,
        ], $overrides));
    }

    /** A row as the Yii application writes them. */
    private function monerisSubscription(array $overrides = []): int
    {
        return $this->subscription(array_merge([
            'gateway' => null,
            'auto_renew' => 1,
            'stripe_customer_id' => null,
            'stripe_payment_method_id' => null,
            'data_key' => 'moneris-vault-token',
        ], $overrides));
    }

    private function row(int $id): object
    {
        return DB::table('user_subscription')->where('subscription_id', $id)->first();
    }

    // ---- cancelling ----------------------------------------------------

    public function test_cancelling_records_the_intent_and_stops_renewal(): void
    {
        $id = $this->subscription();

        $this->postJson('/v1/user/cancel-subscription', [
            'token' => $this->token(),
            'reason' => 'Too expensive',
        ])->assertStatus(200)
            ->assertJsonPath('data.cancelled', true)
            ->assertJsonPath('data.renews', false);

        $row = $this->row($id);

        $this->assertNotNull($row->cancelled_at);
        $this->assertSame('Too expensive', $row->cancellation_reason);

        Mail::assertSent(SubscriptionCancelled::class);
    }

    /**
     * Access is not revoked. This is not a refund.
     *
     * The subscription runs to the date already paid for, and the ordinary
     * expiry sweep ends it. Revoking on cancellation would be taking back
     * something the customer has bought.
     */
    public function test_cancelling_leaves_the_subscription_active_until_it_expires(): void
    {
        $id = $this->subscription();
        $before = $this->row($id);

        $this->postJson('/v1/user/cancel-subscription', ['token' => $this->token()])
            ->assertStatus(200);

        $row = $this->row($id);

        $this->assertSame('Active', $row->status);
        $this->assertSame($before->expiration_date, $row->expiration_date);
    }

    /**
     * A Moneris row also needs `auto_renew = 0`.
     *
     * It is the only column the Yii cron looks at. Writing `cancelled_at`
     * alone would record the customer's wish and then charge them anyway.
     */
    public function test_cancelling_a_moneris_row_clears_auto_renew(): void
    {
        $id = $this->monerisSubscription();

        $this->postJson('/v1/user/cancel-subscription', ['token' => $this->token()])
            ->assertStatus(200);

        $row = $this->row($id);

        $this->assertSame(0, (int) $row->auto_renew,
            'The old cron selects auto_renew = 1 and knows nothing about cancelled_at.');
        $this->assertNotNull($row->cancelled_at);
    }

    public function test_cancelling_twice_is_refused(): void
    {
        $this->subscription();

        $this->postJson('/v1/user/cancel-subscription', ['token' => $this->token()])
            ->assertStatus(200);

        $this->postJson('/v1/user/cancel-subscription', ['token' => $this->token()])
            ->assertStatus(409);
    }

    public function test_cancelling_without_a_subscription_is_refused(): void
    {
        $this->postJson('/v1/user/cancel-subscription', ['token' => $this->token()])
            ->assertStatus(409);
    }

    public function test_cancelling_needs_a_token(): void
    {
        $this->subscription();

        $this->postJson('/v1/user/cancel-subscription', [])->assertStatus(401);
    }

    // ---- what cancelling actually prevents -----------------------------

    /** The scheduler does not charge a cancelled subscription. */
    public function test_a_cancelled_subscription_is_not_renewed(): void
    {
        $id = $this->subscription([
            'expiration_date' => now()->format('Y-m-d'),
        ]);

        $this->assertSame(1, count(app(SubscriptionRenewals::class)->due()),
            'Due before cancelling, or the assertion below proves nothing.');

        DB::table('user_subscription')->where('subscription_id', $id)
            ->update(['cancelled_at' => now()]);

        $this->assertSame(0, count(app(SubscriptionRenewals::class)->due()));
    }

    /**
     * And nobody is warned about a charge that is not coming.
     *
     * Sending "we will charge your card on the 17th" to somebody who
     * cancelled yesterday is the one way the reminder could make things
     * worse than silence.
     */
    public function test_a_cancelled_subscription_is_not_reminded(): void
    {
        $id = $this->subscription();

        $this->assertSame(1, count(app(SubscriptionReminders::class)->due()),
            'Due before cancelling, or the assertion below proves nothing.');

        DB::table('user_subscription')->where('subscription_id', $id)
            ->update(['cancelled_at' => now()]);

        $this->assertSame(0, count(app(SubscriptionReminders::class)->due()));
    }

    // ---- resuming ------------------------------------------------------

    public function test_resuming_puts_a_subscription_back_on_renewal(): void
    {
        $id = $this->subscription();

        $this->postJson('/v1/user/cancel-subscription', ['token' => $this->token()]);

        $this->postJson('/v1/user/resume-subscription', ['token' => $this->token()])
            ->assertStatus(200)
            ->assertJsonPath('data.cancelled', false)
            ->assertJsonPath('data.renews', true);

        $row = $this->row($id);

        $this->assertNull($row->cancelled_at);
        $this->assertNull($row->cancellation_reason);
    }

    /** A Moneris row gets `auto_renew` back, because that is what charges it. */
    public function test_resuming_a_moneris_row_restores_auto_renew(): void
    {
        $id = $this->monerisSubscription();

        $this->postJson('/v1/user/cancel-subscription', ['token' => $this->token()]);
        $this->assertSame(0, (int) $this->row($id)->auto_renew);

        $this->postJson('/v1/user/resume-subscription', ['token' => $this->token()])
            ->assertStatus(200);

        $this->assertSame(1, (int) $this->row($id)->auto_renew);
    }

    /**
     * A Stripe row does **not**, and this is the sharp edge.
     *
     * `auto_renew = 0` is the invariant that hides a Stripe row from the Yii
     * cron. Restoring it as the obvious mirror of cancelling would hand the
     * row to an application that still has the customer's Moneris card on
     * file — and a year later they would be charged twice, once by each
     * processor, with nothing in either application connecting the two.
     */
    public function test_resuming_a_stripe_row_leaves_auto_renew_at_zero(): void
    {
        $id = $this->subscription();

        $this->postJson('/v1/user/cancel-subscription', ['token' => $this->token()]);
        $this->postJson('/v1/user/resume-subscription', ['token' => $this->token()])
            ->assertStatus(200);

        $this->assertSame(0, (int) $this->row($id)->auto_renew,
            'Setting this to 1 would hand a Stripe row to the old Yii cron.');

        // And it is renewing again as far as this API is concerned, so the
        // assertion above is not passing because the resume did nothing.
        $this->assertNull($this->row($id)->cancelled_at);
    }

    public function test_resuming_something_that_is_not_cancelled_is_refused(): void
    {
        $this->subscription();

        $this->postJson('/v1/user/resume-subscription', ['token' => $this->token()])
            ->assertStatus(409);
    }

    // ---- what the app is told ------------------------------------------

    /**
     * `renews` is not the opposite of `cancelled`.
     *
     * A legacy plan type runs out whatever anybody does, and so does a
     * Moneris row the old cron does not select. The app has to know whether
     * to say "renews on" or "access ends on", and `cancelled` alone cannot
     * tell it.
     */
    public function test_status_separates_cancelled_from_will_not_renew(): void
    {
        $this->monerisSubscription(['subscription_type' => 1]);

        $this->getJson('/v1/user/subscription?token='.$this->token())
            ->assertStatus(200)
            ->assertJsonPath('data.cancelled', false)
            ->assertJsonPath('data.renews', false);
    }

    public function test_status_reports_a_renewing_subscription(): void
    {
        $this->subscription();

        $this->getJson('/v1/user/subscription?token='.$this->token())
            ->assertStatus(200)
            ->assertJsonPath('data.renews', true)
            ->assertJsonPath('data.cancelled', false);
    }

    public function test_status_with_no_subscription_says_so(): void
    {
        $this->getJson('/v1/user/subscription?token='.$this->token())
            ->assertStatus(200)
            ->assertJsonPath('data.subscription_id', null)
            ->assertJsonPath('data.renews', false);
    }
}
