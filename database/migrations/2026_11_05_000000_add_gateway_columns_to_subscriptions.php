<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which payment processor owns each subscription.
 *
 * Phase 1 of the Moneris-to-Stripe move: this API takes new customers on
 * Stripe while the Yii application keeps charging the existing book through
 * Moneris. `gateway` is what tells the two apart, per subscription, and it is
 * what the phase 2 ramp flips one cohort at a time.
 * `docs/moneris-to-stripe-migration.md` has the reasoning.
 *
 * Everything here is additive and nullable, and `data_key` is deliberately
 * left alone. The old application selects these tables with `SELECT *`,
 * ignores columns it does not know and never writes these, so it is
 * unaffected — and keeping the Moneris token intact is what makes a rollback
 * to Moneris possible for any row that has already moved.
 *
 * Existing rows stay NULL rather than being backfilled to 'moneris'. NULL is
 * the truth for a row created before the column existed, and it reads the
 * same everywhere it matters: the old cron never consults the column, and
 * this API's renewal scheduler only ever selects `gateway = 'stripe'`.
 */
return new class extends Migration
{
    /*
     * The CLAS database, shared with the Yii application — stated here rather
     * than inherited from whatever .env happens to say, because this
     * migration alters a schema another application depends on and that
     * should not be ambiguous. Run it with:
     *
     *   php artisan migrate --database=clas_api
     *
     * which keeps the migration history in this application's own schema
     * while these changes land in the shared one.
     */
    protected $connection = 'mysql';

    /** The subscription tables, each of which carries one Moneris token. */
    private const SUBSCRIPTION_TABLES = [
        'user_subscription',
        'wmu_subscription',
        'darkmode_subscription',
        'fishing_lands_subscription',
    ];

    public function up(): void
    {
        foreach (self::SUBSCRIPTION_TABLES as $table) {
            Schema::connection($this->connection)->table($table, function (Blueprint $blueprint) {
                $blueprint->string('stripe_customer_id', 64)->nullable()->after('data_key');
                $blueprint->string('stripe_payment_method_id', 64)->nullable()->after('stripe_customer_id');
                $blueprint->enum('gateway', ['moneris', 'stripe'])->nullable()->after('stripe_payment_method_id');

                // The commonest query in both the renewal scheduler and the
                // ramp reporting is "which rows does this gateway own".
                $blueprint->index('gateway');
            });
        }

        // The card table too, so a Stripe payment method can sit beside the
        // Moneris token it replaces. This is what the phase 2 mapping file is
        // loaded into, and what the last-four verification compares against.
        Schema::connection($this->connection)->table('user_moneris_cards', function (Blueprint $blueprint) {
            $blueprint->string('stripe_customer_id', 64)->nullable()->after('data_key');
            $blueprint->string('stripe_payment_method_id', 64)->nullable()->after('stripe_customer_id');
        });
    }

    /**
     * Reversible, with one caveat.
     *
     * Safe at any point before phase 1 ships. Afterwards, dropping `gateway`
     * leaves the renewal scheduler unable to find its own rows, so stop that
     * scheduler first and expect those subscriptions to need either the
     * Moneris path or a re-collected card.
     */
    public function down(): void
    {
        foreach (self::SUBSCRIPTION_TABLES as $table) {
            Schema::connection($this->connection)->table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex($table.'_gateway_index');
                $blueprint->dropColumn(['stripe_customer_id', 'stripe_payment_method_id', 'gateway']);
            });
        }

        Schema::connection($this->connection)->table('user_moneris_cards', function (Blueprint $blueprint) {
            $blueprint->dropColumn(['stripe_customer_id', 'stripe_payment_method_id']);
        });
    }
};
