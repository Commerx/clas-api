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
