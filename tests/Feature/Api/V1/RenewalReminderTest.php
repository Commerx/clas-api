<?php

namespace Tests\Feature\Api\V1;

use App\Mail\RenewalReminder;
use App\Services\SubscriptionReminders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The renewal reminder.
 *
 * Two things are being pinned down here, and they pull in opposite
 * directions. The reminder has to reach the Moneris book, because that is
 * where nearly every subscriber still is and the point of the exercise is
 * that they hear from somebody before their card is charged. And it must
 * reach *only* people who are actually about to be charged, because telling
 * somebody "renews on the 17th" when nothing is going to happen on the 17th
 * is worse than silence.
 *
 * Every row belongs to the fixture account and is removed in tearDown,
 * including the claim rows on the other connection.
 */
class RenewalReminderTest extends TestCase
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

        if (! DB::getSchemaBuilder()->hasColumn('user_subscription', 'gateway')) {
            $this->markTestSkipped('Run the gateway columns migration first.');
        }

        if (! DB::connection('clas_api')->getSchemaBuilder()->hasTable('subscription_reminders')) {
            $this->markTestSkipped('Run the subscription reminders migration first.');
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
        $ids = DB::table('user_subscription')
            ->where('user_id', $this->user->id)
            ->pluck('subscription_id');

        if ($ids->isNotEmpty()) {
            DB::table('subscription_transactions')->whereIn('subscription_id', $ids)->delete();
            DB::connection('clas_api')->table('subscription_reminders')
                ->whereIn('subscription_id', $ids)->delete();
        }

        DB::table('user_subscription')->where('user_id', $this->user->id)->delete();
    }

    /**
     * A subscription renewing in a week, on the gateway this API owns.
     *
     * Defaults are a row that *should* be reminded, so each test says only
     * what it is changing.
     */
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

    /** A row as the Yii application writes them: no gateway, auto_renew on. */
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

    private function reminders(): SubscriptionReminders
    {
        return app(SubscriptionReminders::class);
    }

    private function claimsFor(int $id): int
    {
        return DB::connection('clas_api')->table('subscription_reminders')
            ->where('subscription_id', $id)->count();
    }

    // ---- who gets one --------------------------------------------------

    public function test_a_stripe_renewal_inside_the_window_is_reminded(): void
    {
        $id = $this->subscription();

        $tally = $this->reminders()->run();

        $this->assertSame(1, $tally['sent']);
        $this->assertSame(1, $this->claimsFor($id));

        Mail::assertSent(RenewalReminder::class, function (RenewalReminder $mail) {
            return $mail->hasTo($this->user->email)
                && $mail->renewsOn === now()->addDays(7)->format('Y-m-d')
                && $mail->amount === '9.99';
        });
    }

    /**
     * The existing book hears about it too.
     *
     * This is the reason the reminder is not scoped to `gateway = 'stripe'`
     * the way the renewal scheduler is. Nearly every live subscription is a
     * Moneris row the Yii cron charges, and its own reminder has been
     * switched off since May 2024 — so scoping this one to Stripe would
     * "fix" the complaint for almost nobody.
     *
     * Reminding costs nothing and moves no money, which is what makes it
     * safe to cross the line that charging must never cross.
     */
    public function test_a_moneris_renewal_is_reminded_as_well(): void
    {
        $this->monerisSubscription();

        $this->assertSame(1, $this->reminders()->run()['sent']);

        Mail::assertSent(RenewalReminder::class);
    }

    /** And one that spells its gateway out, rather than leaving it null. */
    public function test_an_explicit_moneris_gateway_is_reminded(): void
    {
        $this->monerisSubscription(['gateway' => 'moneris']);

        $this->assertSame(1, $this->reminders()->run()['sent']);
    }

    // ---- who does not --------------------------------------------------

    /**
     * Nothing is going to be charged, so nothing is promised.
     *
     * The old cron selects `auto_renew = 1`. A Moneris row with it off is
     * never charged — it runs out — and a mail saying "renews on the 17th"
     * would be simply false.
     */
    public function test_a_moneris_row_the_old_cron_will_not_charge_is_left_alone(): void
    {
        $this->monerisSubscription(['auto_renew' => 0]);

        $this->assertSame(0, $this->reminders()->run()['sent']);
        Mail::assertNothingSent();
    }

    /**
     * Same again for the legacy plan types.
     *
     * The old cron selects types 3, 4 and 5 only. A type 1 or 2 row can have
     * `auto_renew = 1` and still never be charged, and 892 rows carry those
     * types — so this is a real cohort, not a hypothetical one.
     */
    public function test_a_legacy_plan_type_is_not_reminded(): void
    {
        $this->monerisSubscription(['subscription_type' => 1]);

        $this->assertSame(0, $this->reminders()->run()['sent']);
        Mail::assertNothingSent();
    }

    /**
     * A Stripe row with no card attached will not renew either.
     *
     * Deliberately left `auto_renew = 1` and given a Moneris `data_key`, so
     * the only thing excluding it is the missing payment method. Without
     * that the row would have been excluded by the gateway branch instead
     * and this would pass for the wrong reason.
     */
    public function test_a_stripe_row_with_no_payment_method_is_not_reminded(): void
    {
        $this->subscription([
            'stripe_payment_method_id' => null,
            'auto_renew' => 1,
            'data_key' => 'moneris-vault-token',
        ]);

        $this->assertSame(0, $this->reminders()->run()['sent']);
        Mail::assertNothingSent();
    }

    public function test_a_renewal_beyond_the_window_is_not_reminded_yet(): void
    {
        $this->subscription(['expiration_date' => now()->addDays(8)->format('Y-m-d')]);

        $this->assertSame(0, $this->reminders()->run()['sent']);
    }

    /**
     * A renewal due today belongs to the scheduler, not to this.
     *
     * The charge happens this morning; a "renews on" notice arriving in the
     * same hour as the receipt is noise at best and alarming at worst.
     */
    public function test_a_renewal_due_today_is_not_reminded(): void
    {
        $this->subscription(['expiration_date' => now()->format('Y-m-d')]);

        $this->assertSame(0, $this->reminders()->run()['sent']);
    }

    public function test_an_inactive_subscription_is_not_reminded(): void
    {
        $this->subscription(['status' => 'Expired']);

        $this->assertSame(0, $this->reminders()->run()['sent']);
    }

    public function test_a_blocked_account_is_not_reminded(): void
    {
        $this->subscription();

        DB::table('user')->where('id', $this->user->id)
            ->update(['blocked_at' => now()->timestamp]);

        try {
            $this->assertSame(0, $this->reminders()->run()['sent']);
            Mail::assertNothingSent();
        } finally {
            DB::table('user')->where('id', $this->user->id)
                ->update(['blocked_at' => null]);
        }
    }

    // ---- once, and only once -------------------------------------------

    /**
     * The failure the old implementation had: running twice mailed twice.
     *
     * It matched a date and kept no record, so the only thing stopping a
     * second send was not running the command again.
     */
    public function test_a_second_run_does_not_mail_the_same_renewal_again(): void
    {
        $id = $this->subscription();

        $first = $this->reminders()->run();
        $second = $this->reminders()->run();

        $this->assertSame(1, $first['sent']);
        $this->assertSame(0, $second['sent'], 'The second run must send nothing.');
        $this->assertSame(0, $second['due'],
            'And it should not even consider the row, having already mailed it.');

        Mail::assertSentCount(1);
        $this->assertSame(1, $this->claimsFor($id));
    }

    /**
     * The claim is per renewal, not per subscriber.
     *
     * Keying it on the subscription alone would silence that subscriber for
     * good after their first reminder — a bug that only shows up a year
     * later, when the second renewal arrives unannounced and we are back
     * where we started.
     *
     * Moving the date rather than travelling a year forward, because the
     * window is evaluated by MySQL's `CURDATE()`: the database's clock is
     * not the one PHP's time helpers move. What this pins down is the shape
     * of the key, which is the part that could be got wrong.
     */
    public function test_a_different_renewal_date_is_reminded_again(): void
    {
        $id = $this->subscription();

        $this->reminders()->run();

        DB::table('user_subscription')->where('subscription_id', $id)
            ->update(['expiration_date' => now()->addDays(5)->format('Y-m-d')]);

        $this->assertSame(1, $this->reminders()->run()['sent'],
            'A renewal on a date nobody has been told about is a new reminder.');

        Mail::assertSentCount(2);
        $this->assertSame(2, $this->claimsFor($id));
    }

    /**
     * A send that fails is retried, not swallowed.
     *
     * The claim is taken before the mail goes out, so a failure has to give
     * it back — otherwise one SMTP hiccup silently costs that subscriber
     * their only warning.
     */
    public function test_a_failed_send_releases_its_claim(): void
    {
        $id = $this->subscription();

        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()
            ->andThrow(new \RuntimeException('smtp refused the connection'));

        $tally = $this->reminders()->run();

        $this->assertSame(1, $tally['failed']);
        $this->assertSame(0, $tally['sent']);
        $this->assertSame(0, $this->claimsFor($id),
            'A reminder that was never sent must not be recorded as sent.');
    }

    // ---- what it says ---------------------------------------------------

    /**
     * The date and the amount, in the subject as well as the body.
     *
     * The complaint being answered is that a charge arrived unannounced, and
     * a reminder whose subject line says only "your subscription" does not
     * answer it — the date has to survive being skimmed in an inbox.
     */
    public function test_the_mail_names_the_date_and_the_amount(): void
    {
        $this->subscription();

        $this->reminders()->run();

        $when = now()->addDays(7)->format('M j, Y');

        Mail::assertSent(RenewalReminder::class, function (RenewalReminder $mail) use ($when) {
            $rendered = $mail->render();

            return str_contains($mail->envelope()->subject, $when)
                && str_contains($rendered, $when)
                && str_contains($rendered, '9.99');
        });
    }

    /**
     * It never names a gateway.
     *
     * The same mail goes to a Stripe subscriber and a Moneris one, and which
     * processor takes the money is not the customer's business — naming it
     * would also make the silent migration visible, which was a deliberate
     * decision to avoid.
     */
    public function test_the_mail_says_nothing_about_the_gateway(): void
    {
        $this->monerisSubscription();

        $this->reminders()->run();

        Mail::assertSent(RenewalReminder::class, function (RenewalReminder $mail) {
            $text = strtolower($mail->render().' '.$mail->envelope()->subject);

            return ! str_contains($text, 'moneris')
                && ! str_contains($text, 'stripe');
        });
    }

    // ---- configuration --------------------------------------------------

    /**
     * The default covers both gateways.
     *
     * Stated as a test because it is the whole point: a default of 'stripe'
     * alone would leave the existing book — nearly every subscriber — with
     * no reminder, and the change would look done while fixing nothing.
     */
    public function test_both_gateways_are_covered_by_default(): void
    {
        $this->assertEqualsCanonicalizing(
            ['stripe', 'moneris'],
            (array) config('clas.subscriptions.reminder.gateways'),
        );

        $this->assertSame(7, (int) config('clas.subscriptions.reminder.days_before'));
    }

    /**
     * And dropping a gateway from the list really drops it.
     *
     * This is the switch to reach for if the Yii reminder is ever turned
     * back on, so it needs to work.
     */
    public function test_removing_moneris_from_the_config_stops_those_reminders(): void
    {
        $this->monerisSubscription();

        config(['clas.subscriptions.reminder.gateways' => ['stripe']]);

        $this->assertSame(0, $this->reminders()->run()['sent']);
        Mail::assertNothingSent();
    }

    public function test_a_dry_run_sends_nothing_and_records_nothing(): void
    {
        $id = $this->subscription();

        $tally = $this->reminders()->run(dryRun: true);

        $this->assertSame(1, $tally['due'], 'A dry run still reports what is due.');
        $this->assertSame(0, $tally['sent']);
        $this->assertSame(0, $this->claimsFor($id));
        Mail::assertNothingSent();
    }
}
