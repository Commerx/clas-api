<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per renewal reminder sent, so a subscriber is never told twice.
 *
 * The old Yii reminder had no record of what it had sent. It selected the
 * subscriptions whose `expiration_date` was exactly seven days out and mailed
 * them, which means running that cron twice in a day mailed everybody twice,
 * and a day it did not run at all skipped those subscribers entirely with
 * nothing to show for it. Both failure modes come from the same cause: the
 * calendar was the only state.
 *
 * Here the reminder window is a range rather than a single date — so a missed
 * run costs a day, not the reminder — and this table is what stops the range
 * turning into a reminder every day for a week. A row is claimed before the
 * mail is sent and released if the send fails, so the claim is a promise that
 * somebody is sending it, not a record that somebody did.
 *
 * On `clas_api` rather than the shared CLAS database, for the same reason as
 * `stripe_events`: this is this application's bookkeeping, the Yii
 * application has no interest in it, and two applications sharing a schema is
 * already enough.
 */
return new class extends Migration
{
    protected $connection = 'clas_api';

    public function up(): void
    {
        Schema::connection($this->connection)->create('subscription_reminders', function (Blueprint $table) {
            $table->id();

            // `user_subscription.subscription_id`, in the shared database.
            // Deliberately no foreign key: it is a different connection, and
            // a reminder outliving its subscription row is harmless.
            $table->unsignedBigInteger('subscription_id');

            /*
             * The renewal this reminder is about, not the day it was sent.
             *
             * Pairing the subscription with the date it was due is what makes
             * the reminder once-per-renewal instead of once-per-subscriber:
             * next year's renewal has a different date and so gets its own
             * reminder, while a run today and a run tomorrow inside the same
             * window collide on this key and the second one is ignored.
             */
            $table->date('renews_on');

            $table->unique(['subscription_id', 'renews_on'], 'reminder_once_per_renewal');

            // Which gateway will take the money. Kept to measure the drain
            // from Moneris to Stripe, and to tell at a glance who a given
            // run reminded.
            $table->string('gateway', 20)->nullable()->index();

            // The address it went to, which is not always the address on the
            // account today.
            $table->string('email', 255);

            $table->timestamp('sent_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('subscription_reminders');
    }
};
