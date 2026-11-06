<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe webhook events, so one is never acted on twice.
 *
 * Stripe retries a webhook until it gets a 2xx, and will happily deliver the
 * same event more than once even after a success. So the handler has to be
 * idempotent, and the only honest way to do that is to remember which event
 * ids have been dealt with.
 *
 * This lives on `clas_api`, **not** the shared CLAS database. It is entirely
 * this application's bookkeeping — the Yii application has no webhook handler
 * and no interest in these rows — so there is no reason to put another table
 * into a schema two applications share. This is what the second connection
 * added in config/database.php is for.
 */
return new class extends Migration
{
    protected $connection = 'clas_api';

    public function up(): void
    {
        Schema::connection($this->connection)->create('stripe_events', function (Blueprint $table) {
            // Stripe's own event id is the primary key: that is what makes a
            // redelivery a duplicate rather than a second event.
            $table->string('id', 64)->primary();

            $table->string('type', 100)->index();

            // Kept whole. When something goes wrong with a payment, the
            // event as Stripe sent it is the only authoritative record of
            // what it said, and reconstructing it later is impossible.
            $table->json('payload');

            $table->timestamp('received_at');

            // Null until the handler has finished with it. A row with a
            // received_at and no handled_at is an event that arrived and
            // then something failed, which is the set worth looking at.
            $table->timestamp('handled_at')->nullable()->index();

            $table->string('outcome', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('stripe_events');
    }
};
