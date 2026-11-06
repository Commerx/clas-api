<?php

namespace App\Console\Commands;

use App\Services\SubscriptionRenewals;
use Illuminate\Console\Command;

/**
 * Charge the subscriptions this API owns, and expire what has lapsed.
 *
 * Scoped to `gateway = 'stripe'`. The Yii application's own cron keeps
 * charging everything else, and the two cannot pick up the same row —
 * docs/moneris-to-stripe-migration.md explains why that holds.
 */
class RenewSubscriptions extends Command
{
    protected $signature = 'clas:renew-subscriptions {--dry-run : List what would be charged and change nothing}';

    protected $description = 'Renew the Stripe subscriptions that are due, and expire those out of grace';

    public function handle(SubscriptionRenewals $renewals): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run: nothing will be charged or changed.');

            foreach ($renewals->due() as $subscription) {
                $this->line(sprintf(
                    '  subscription %-7s user %-6s expired %s  type %s',
                    $subscription->subscription_id,
                    $subscription->user_id,
                    $subscription->expiration_date,
                    $subscription->subscription_type,
                ));
            }
        }

        $tally = $renewals->run($dryRun);

        $this->table(
            ['attempted', 'renewed', 'failed', 'expired', 'skipped'],
            [[
                $tally['attempted'], $tally['renewed'], $tally['failed'],
                $tally['expired'], $tally['skipped'],
            ]],
        );

        // A declined card is an ordinary outcome, not a broken job, so this
        // exits 0 unless the run itself could not proceed.
        return self::SUCCESS;
    }
}
