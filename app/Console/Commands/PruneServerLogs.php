<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Delete daily Laravel log files older than the retention stated in the
 * privacy policy (`config('legal.server_log_retention_days')`, 14 by default;
 * docs/specs/legal-privacy-publication.md). Monolog's rotating handler keeps a
 * number of files rather than a number of days, so a quiet period would let
 * older files survive without this prune.
 *
 * Only `laravel-YYYY-MM-DD.log` files next to the `daily` channel path are
 * considered, by the date in their name: a file dated `today - days` or
 * earlier is deleted, so at most `days` dated files remain. The legacy
 * `laravel.log` and any other file are never touched. Scheduled daily in
 * `routes/console.php`.
 */
class PruneServerLogs extends Command
{
    /**
     * @var string
     */
    protected $signature = 'logs:prune';

    /**
     * @var string
     */
    protected $description = 'Delete daily server log files older than the stated retention period';

    public function handle(): int
    {
        $days = max(1, (int) config('legal.server_log_retention_days'));
        $path = (string) config('logging.channels.daily.path');
        $directory = dirname($path);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $cutoff = Carbon::now('UTC')->startOfDay()->subDays($days);
        $pattern = '/^'.preg_quote($base, '/').'-(\d{4}-\d{2}-\d{2})'.($extension === '' ? '' : '\.'.preg_quote($extension, '/')).'$/';

        $deleted = 0;
        $failed = 0;
        foreach (is_dir($directory) ? (scandir($directory) ?: []) : [] as $file) {
            if (preg_match($pattern, $file, $match) !== 1) {
                continue;
            }

            $date = Carbon::createFromFormat('!Y-m-d', $match[1], 'UTC');
            if ($date === null || $date->format('Y-m-d') !== $match[1] || $date->greaterThan($cutoff)) {
                continue;
            }

            $full = $directory.DIRECTORY_SEPARATOR.$file;
            if (is_file($full) && @unlink($full)) {
                $deleted++;
            } elseif (is_file($full)) {
                $failed++;
            }
        }

        $this->info("Deleted {$deleted} server log file(s) older than {$days} days.");
        if ($failed > 0) {
            $this->error("Could not delete {$failed} server log file(s).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
