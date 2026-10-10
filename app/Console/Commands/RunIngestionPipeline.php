<?php

namespace App\Console\Commands;

use App\Services\Market\MarketCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Run the full EOD pipeline in order: ingestion -> indicators -> signals -> patterns
 * -> alerts.
 *
 * This is a thin composition wrapper: it never reimplements any stage and
 * delegates to the `ingestion:run`, `indicators:compute`, `signals:detect`,
 * `patterns:detect` and `alerts:evaluate` commands via `$this->call()`, so each
 * stage keeps its own
 * behavior and ledger.
 *
 * Guards:
 * - A command-level cache lock prevents overlapping invocations (the scheduled
 *   event also uses `withoutOverlapping`). A held lock is a skip, not a failure.
 * - Unless `--force`, a non-trading day (weekend or committed NYSE holiday) is
 *   skipped. The schedule already skips those, so this is defense in depth and
 *   the directly testable path.
 *
 * Failure policy: if ingestion fails (a `failed` run), later stages are skipped.
 * If a later stage fails, the remaining stages are still attempted and the command
 * exits `1` so the scheduler records the failure. Alerts run last and only when
 * signals succeeded, so users are never notified from a partial signal set.
 */
class RunIngestionPipeline extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ingestion:pipeline
                            {--universe= : Universe slug to run the pipeline for (defaults to ingestion.universe)}
                            {--force : Run even on a non-trading day}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the full EOD pipeline (ingest, compute indicators, detect signals)';

    /**
     * Execute the console command.
     */
    public function handle(MarketCalendar $calendar): int
    {
        $universe = (string) ($this->option('universe') ?: config('ingestion.universe'));
        $timezone = (string) config('ingestion.timezone');

        $lock = Cache::lock('ingestion:pipeline', (int) config('ingestion.lock_ttl_seconds'));

        if (! $lock->get()) {
            $this->warn('EOD pipeline is already running; skipping.');

            return self::SUCCESS;
        }

        try {
            if (! $this->option('force') && ! $calendar->isTradingDay(now($timezone))) {
                $this->info('Not a trading day; EOD pipeline skipped.');

                return self::SUCCESS;
            }

            $ingestion = $this->call('ingestion:run', ['--universe' => $universe]);

            if ($ingestion !== self::SUCCESS) {
                $this->error('EOD pipeline stopped: ingestion failed; indicators, signals and patterns were skipped.');

                return self::FAILURE;
            }

            $indicators = $this->call('indicators:compute', ['--universe' => $universe]);
            $signals = $this->call('signals:detect', ['--universe' => $universe]);
            $patterns = $this->call('patterns:detect', ['--universe' => $universe]);
            $alerts = $signals === self::SUCCESS ? $this->call('alerts:evaluate') : self::FAILURE;

            if ($signals !== self::SUCCESS) {
                $this->warn('Alerts were not evaluated because signal detection failed.');
            }

            if ($indicators !== self::SUCCESS || $signals !== self::SUCCESS || $patterns !== self::SUCCESS || $alerts !== self::SUCCESS) {
                $this->error('EOD pipeline finished with errors.');

                return self::FAILURE;
            }

            $this->info('EOD pipeline completed.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
