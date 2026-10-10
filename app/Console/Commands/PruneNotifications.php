<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete in-app notifications older than the configured retention
 * (`config('alerts.notification_retention_days')`, 90 by default), as stated
 * in the privacy policy. Scheduled daily in `routes/console.php`.
 */
class PruneNotifications extends Command
{
    /**
     * @var string
     */
    protected $signature = 'notifications:prune';

    /**
     * @var string
     */
    protected $description = 'Delete in-app notifications older than the retention period';

    public function handle(): int
    {
        $days = max(1, (int) config('alerts.notification_retention_days'));
        $deleted = DB::table('notifications')->where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted {$deleted} notification(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
