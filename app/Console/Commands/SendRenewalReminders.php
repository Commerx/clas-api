<?php

namespace App\Console\Commands;

use App\Services\SubscriptionReminders;
use Illuminate\Console\Command;

/**
 * Mail everyone whose subscription renews inside the reminder window.
 *
 * Its own command rather than a step inside `clas:renew-subscriptions`, for
 * two reasons. It covers rows that command must never touch — the Moneris
 * book the Yii cron still charges — and a fault in reminding people should
 * not be able to stop anybody being charged.
 */
class SendRenewalReminders extends Command
{
    protected $signature = 'clas:send-renewal-reminders {--dry-run : List who would be mailed and send nothing}';

    protected $description = 'Remind subscribers before their card is charged';

    public function handle(SubscriptionReminders $reminders): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run: no mail will be sent and nothing recorded.');

            foreach ($reminders->due() as $subscription) {
                $this->line(sprintf(
                    '  subscription %-7s user %-6s renews %s  type %-2s  %s',
                    $subscription->subscription_id,
                    $subscription->user_id,
                    substr((string) $subscription->expiration_date, 0, 10),
                    $subscription->subscription_type,
                    $subscription->gateway ?: 'moneris',
                ));
            }
        }

        $tally = $reminders->run($dryRun);

        $this->table(
            ['due', 'sent', 'skipped', 'failed'],
            [[$tally['due'], $tally['sent'], $tally['skipped'], $tally['failed']]],
        );

        return self::SUCCESS;
    }
}
