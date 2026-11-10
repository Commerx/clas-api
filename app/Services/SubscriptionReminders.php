<?php

namespace App\Services;

use App\Mail\RenewalReminder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Telling a subscriber their card is about to be charged.
 *
 * The old application had this and switched it off. `sendExpiryReminder()` in
 * `commands/PaymentsController.php` has been commented out since 14 May 2024,
 * so for two years the first a subscriber heard about a renewal was the
 * receipt for a charge already taken. That is the complaint this exists to
 * answer.
 *
 * **It covers the whole book, not just this API's rows.** Everywhere else in
 * this application, a Moneris row is untouchable: charging one from here
 * would double-bill somebody the Yii cron is already charging, which is what
 * the `auto_renew = 0` invariant prevents. A reminder is different in kind —
 * it moves no money and writes nothing to the shared database — so there is
 * no reason to leave the existing subscribers, who are nearly all of them,
 * without one. Which gateways are covered is `clas.subscriptions.reminder.
 * gateways`, and the comment there records what has to stay true for it.
 *
 * Two things the old implementation got wrong, both avoided here:
 *
 * **A missed run lost the reminder.** It matched `expiration_date` against a
 * single date, seven days out, so a day the cron did not run was a cohort
 * that never heard anything. The window here is a range — any renewal inside
 * the next seven days that has not been reminded yet — so a missed run costs
 * a day.
 *
 * **Running twice mailed twice.** Nothing recorded what had been sent, so the
 * only thing stopping a second send was not running the command again. Here a
 * row in `clas_api.subscription_reminders` is claimed before the mail goes
 * out, under a unique key on the subscription and the renewal date.
 */
class SubscriptionReminders
{
    /**
     * Remind everyone whose renewal falls inside the window.
     *
     * @return array{due: int, sent: int, skipped: int, failed: int}
     */
    public function run(bool $dryRun = false): array
    {
        $tally = ['due' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($this->due() as $subscription) {
            $tally['due']++;

            if ($dryRun) {
                continue;
            }

            if (! $this->claim($subscription)) {
                // Another run of the command got there first. The unique key
                // is doing the work, so concurrent runs are safe rather than
                // merely unlikely.
                $tally['skipped']++;

                continue;
            }

            $this->send($subscription)
                ? $tally['sent']++
                : $tally['failed']++;
        }

        return $tally;
    }

    /**
     * Renewals coming up that nobody has been told about yet.
     *
     * Only subscriptions that really will be charged: a Stripe row with no
     * payment method attached, and a Moneris row the old cron does not select,
     * are both going to expire rather than renew, and telling their owner
     * "renews on the 17th" would be a lie. They are left out, which does mean
     * nothing warns someone that access is about to lapse — worth building,
     * but it is a different email saying the opposite thing.
     *
     * @return list<object>
     */
    public function due(): array
    {
        $days = (int) config('clas.subscriptions.reminder.days_before');
        $gateways = (array) config('clas.subscriptions.reminder.gateways');

        if ($days < 1 || $gateways === []) {
            return [];
        }

        $stripe = in_array('stripe', $gateways, true);
        $moneris = in_array('moneris', $gateways, true);

        if (! $stripe && ! $moneris) {
            return [];
        }

        // The types the old cron actually renews. It selects 3, 4 and 5; a
        // type 1 or 2 row is legacy and simply runs out.
        $renewing = array_keys(array_filter(
            (array) config('clas.subscriptions.types'),
            static fn (array $type): bool => ($type['renews'] ?? false) === true,
        ));

        $candidates = DB::table('user_subscription as us')
            ->join('user as u', 'u.id', '=', 'us.user_id')
            ->leftJoin('profile as p', 'p.user_id', '=', 'u.id')
            ->where('us.status', 'Active')
            ->whereNull('u.blocked_at')
            // Nothing is coming, so there is nothing to warn about. Sending
            // "we will charge your card" to somebody who has just cancelled
            // is the one way this feature could make things worse.
            ->whereNull('us.cancelled_at')
            // Strictly in the future: a renewal due today is the scheduler's
            // business, and a reminder about it would arrive alongside the
            // receipt.
            ->whereRaw('us.expiration_date > CURDATE()')
            ->whereRaw('us.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)', [$days])
            ->where(function ($group) use ($stripe, $moneris, $renewing) {
                if ($stripe) {
                    $group->orWhere(function ($own) {
                        $own->where('us.gateway', 'stripe')
                            ->whereNotNull('us.stripe_payment_method_id');
                    });
                }

                if ($moneris) {
                    $group->orWhere(function ($legacy) use ($renewing) {
                        $legacy->where(function ($gateway) {
                            // NULL is the legacy value: every row written
                            // before the gateway column existed.
                            $gateway->whereNull('us.gateway')
                                ->orWhere('us.gateway', 'moneris');
                        })
                            ->where('us.auto_renew', 1)
                            ->whereIn('us.subscription_type', $renewing);
                    });
                }
            })
            ->get([
                'us.subscription_id', 'us.user_id', 'us.subscription_type',
                'us.expiration_date', 'us.subscription_amount', 'us.gateway',
                'u.email', 'p.name',
            ])
            ->all();

        return $this->withoutAlreadyReminded($candidates);
    }

    /**
     * Drop the ones a previous run has already mailed.
     *
     * Done in PHP rather than as a join because the reminders live on the
     * other connection. One query for the whole batch, keyed the same way the
     * unique index is.
     *
     * @param  list<object>  $candidates
     * @return list<object>
     */
    private function withoutAlreadyReminded(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $sent = DB::connection('clas_api')
            ->table('subscription_reminders')
            ->whereIn('subscription_id', array_map(
                static fn (object $row): int => (int) $row->subscription_id,
                $candidates,
            ))
            ->get(['subscription_id', 'renews_on']);

        $already = [];
        foreach ($sent as $row) {
            $already[$this->key((int) $row->subscription_id, (string) $row->renews_on)] = true;
        }

        return array_values(array_filter(
            $candidates,
            fn (object $row): bool => ! isset($already[$this->key(
                (int) $row->subscription_id,
                (string) $row->expiration_date,
            )]),
        ));
    }

    /** The pair the unique index is on, as one string. */
    private function key(int $subscriptionId, string $renewsOn): string
    {
        return $subscriptionId.'@'.substr($renewsOn, 0, 10);
    }

    /**
     * Take the slot before sending, so two runs cannot both send.
     *
     * `insertOrIgnore` turns the unique key into the decision: it returns the
     * number of rows written, and zero means somebody else holds the claim.
     */
    private function claim(object $subscription): bool
    {
        return DB::connection('clas_api')
            ->table('subscription_reminders')
            ->insertOrIgnore([
                'subscription_id' => (int) $subscription->subscription_id,
                'renews_on' => substr((string) $subscription->expiration_date, 0, 10),
                'gateway' => $subscription->gateway ?: 'moneris',
                'email' => (string) $subscription->email,
                'sent_at' => now(),
            ]) === 1;
    }

    /** Release a claim whose mail never went out, so tomorrow retries it. */
    private function release(object $subscription): void
    {
        DB::connection('clas_api')
            ->table('subscription_reminders')
            ->where('subscription_id', (int) $subscription->subscription_id)
            ->where('renews_on', substr((string) $subscription->expiration_date, 0, 10))
            ->delete();
    }

    private function send(object $subscription): bool
    {
        $type = (array) config('clas.subscriptions.types.'.$subscription->subscription_type);

        try {
            Mail::to($subscription->email)->send(new RenewalReminder(
                subscriberName: trim((string) ($subscription->name ?? '')) ?: 'there',
                subscriptionName: (string) ($type['name'] ?? 'Annual'),
                amount: (string) ($type['amount'] ?? $subscription->subscription_amount),
                renewsOn: substr((string) $subscription->expiration_date, 0, 10),
            ));

            return true;
        } catch (\Throwable $e) {
            $this->release($subscription);

            Log::warning('Renewal reminder failed to send.', [
                'subscription_id' => $subscription->subscription_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
