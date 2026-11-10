<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Renewals for the subscriptions this API owns, once a day.
 *
 * `withoutOverlapping` because a run that is still charging must not be
 * joined by a second one, and `onOneServer` so a multi-server deployment
 * charges each subscription once. The command is also safe to run by hand:
 * it counts attempts from the transaction rows already written, so running
 * it twice in a day cannot produce a second charge.
 *
 * The Yii application's own cron keeps renewing everything that is still on
 * Moneris. The two never select the same row — see
 * docs/moneris-to-stripe-migration.md.
 */
Schedule::command('clas:renew-subscriptions')
    ->dailyAt('07:15')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Reminders, an hour after the renewals.
 *
 * Later in the morning on purpose: a subscription renewing today is charged
 * by the run above, and the reminder window starts tomorrow, so by the time
 * this runs the day's renewals are settled and nobody can receive a "renews
 * on" notice and a receipt in the same hour.
 *
 * Unlike the renewal command this covers the Moneris book as well — the Yii
 * cron charges those, but its own reminder has been switched off since May
 * 2024 and this is what puts it back. `clas.subscriptions.reminder.gateways`
 * has the full note, including what must stay true in the Yii application
 * for it to be safe.
 */
Schedule::command('clas:send-renewal-reminders')
    ->dailyAt('08:15')
    ->withoutOverlapping()
    ->onOneServer();
