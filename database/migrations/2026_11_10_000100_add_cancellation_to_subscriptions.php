<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a subscriber has asked not to renew.
 *
 * Until now there was no way to say so. Neither application has a cancel
 * path: no endpoint, no screen, no setting. A subscriber's only way to stop
 * renewing was to let their card fail, and the renewal reminder made that
 * gap visible — it tells people a charge is coming, so it has to tell them
 * what to do if they do not want it, and the only honest answer was "email
 * support".
 *
 * **Why not `auto_renew`.** That column already means something else here.
 * Every row this API creates carries `auto_renew = 0` so the old Yii renewal
 * cron, which filters `auto_renew = 1`, leaves it alone — the invariant the
 * whole coexistence arrangement rests on. So on a Stripe row the column reads
 * "not renewed by the old cron", not "not renewing", and overloading it would
 * make a cancelled Stripe subscription indistinguishable from an ordinary
 * one.
 *
 * `cancelled_at` is therefore the only truth about intent, for both gateways.
 * On a *Moneris* row the cancellation also sets `auto_renew = 0`, because
 * that is the one thing the old cron reads and it is what actually stops the
 * charge; on a Stripe row it is already 0 and this API's scheduler reads
 * `cancelled_at` instead. One column means one thing in each application.
 *
 * Additive and nullable. The old application writes `auto_renew` in three
 * places and reads it in one, selects these tables with `SELECT *`, and will
 * ignore a column it does not know about.
 */
return new class extends Migration
{
    /* The shared CLAS database. Run with: php artisan migrate --database=clas_api */
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection($this->connection)->table('user_subscription', function (Blueprint $table) {
            // When the subscriber asked to stop. Null means they have not.
            // Access is not revoked here: a cancelled subscription stays
            // Active until its expiration_date, which is what the customer
            // has already paid for, and the ordinary expiry sweep ends it.
            $table->dateTime('cancelled_at')->nullable()->after('auto_renew');

            // Optional, free text, whatever the app chose to offer. Worth
            // having the first time anyone asks why people are leaving.
            $table->string('cancellation_reason', 255)->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('user_subscription', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
