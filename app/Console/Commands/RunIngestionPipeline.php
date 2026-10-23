<?php

namespace App\Console\Commands;

use App\Models\Universe;
use App\Services\Market\MarketCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Run the full EOD pipeline in order: ingestion -> indicators -> signals -> patterns
 * -> Market Context -> Macro Series -> Market Regime -> screener rows -> alerts.
 *
 * This is a thin composition wrapper: it never reimplements any stage and
 * delegates to the `ingestion:run`, `indicators:compute`, `signals:detect`,
 * `patterns:detect`, `screener:refresh` and `alerts:evaluate` commands via
 * `$this->call()`, so each
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
 *
 * Market Context block (docs/specs/market-context-sectors.md): after the main
 * universe's patterns and before the Screener refresh, the `market-context`
 * universe (SPY + sector ETFs) is ingested and its indicators computed, then
 * `market-context:compute` stores the sector and Breadth snapshots. No signals
 * or patterns run for it. The block is isolated: a failed or throwing stage is
 * printed and logged (`Log::warning`, stage and exit code only) but never
 * changes the exit code nor skips the Screener refresh or the alerts; an
 * unseeded universe skips the block with one info line.
 *
 * Macro stage (docs/specs/macro-series-fred.md): right after the Market
 * Context block, `macro:refresh` fetches the FRED Macro Series. It is isolated
 * the same way (a non-zero exit or an exception is printed and logged with
 * stage and exit only, never changing the exit code or skipping later
 * stages) and skipped with one info line when `MACRO_ENABLED=false`.
 *
 * Market Regime stage (docs/specs/market-regime.md): right after the macro
 * stage and before the Screener refresh, `market-regime:compute` labels the
 * latest sessions (or seeds the history on an empty table). It always runs,
 * even when macro is disabled or the Market Context block was skipped, and
 * is isolated the same way: a failure is printed and logged (stage and exit
 * only) and never changes the exit code nor skips later stages.
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
            $this->runMarketContext();
            $this->runMacro();
            $this->runMarketRegime();
            // The Screener and the alerts read the precomputed rows, so they are
            // rebuilt from whatever the stages above stored.
            $screener = $this->call('screener:refresh');
            $alerts = $signals === self::SUCCESS && $screener === self::SUCCESS ? $this->call('alerts:evaluate') : self::FAILURE;
            $forecasts = $this->call('forecast:resolve');

            if ($signals !== self::SUCCESS) {
                $this->warn('Alerts were not evaluated because signal detection failed.');
            } elseif ($screener !== self::SUCCESS) {
                $this->warn('Alerts were not evaluated because the screener rows could not be refreshed.');
            }

            if ($indicators !== self::SUCCESS || $signals !== self::SUCCESS || $patterns !== self::SUCCESS || $screener !== self::SUCCESS || $alerts !== self::SUCCESS || $forecasts !== self::SUCCESS) {
                $this->error('EOD pipeline finished with errors.');

                return self::FAILURE;
            }

            $this->info('EOD pipeline completed.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * Ingest the Market Context universe, compute its indicators and the
     * snapshots. Every stage is attempted; failures never reach the exit code.
     */
    private function runMarketContext(): void
    {
        $slug = (string) config('market_context.universe');

        if (! Universe::query()->where('slug', $slug)->exists()) {
            $this->info('Market context universe not seeded; skipped.');

            return;
        }

        $stages = [
            'ingestion:run' => ['--universe' => $slug],
            'indicators:compute' => ['--universe' => $slug],
            'market-context:compute' => [],
        ];

        foreach ($stages as $stage => $arguments) {
            try {
                $exit = $this->call($stage, $arguments);
            } catch (Throwable $exception) {
                report($exception);
                $exit = self::FAILURE;
            }

            if ($exit !== self::SUCCESS) {
                $this->warn("Market context stage {$stage} failed (exit {$exit}); the pipeline continues.");
                Log::warning('Market context stage failed.', ['stage' => $stage, 'exit' => $exit]);
            }
        }
    }

    /**
     * Refresh the FRED Macro Series. Failures never reach the exit code.
     * `FredClient` never lets a key-bearing message out, so a reported
     * exception cannot leak the key.
     */
    private function runMacro(): void
    {
        if (! config('macro.enabled')) {
            $this->info('Macro series disabled; skipped.');

            return;
        }

        try {
            $exit = $this->call('macro:refresh');
        } catch (Throwable $exception) {
            report($exception);
            $exit = self::FAILURE;
        }

        if ($exit !== self::SUCCESS) {
            $this->warn("Macro stage macro:refresh failed (exit {$exit}); the pipeline continues.");
            Log::warning('Macro stage failed.', ['stage' => 'macro:refresh', 'exit' => $exit]);
        }
    }

    /**
     * Compute the Market Regime from the snapshots and macro readings stored
     * by the stages above. Failures never reach the exit code.
     */
    private function runMarketRegime(): void
    {
        try {
            $exit = $this->call('market-regime:compute');
        } catch (Throwable $exception) {
            report($exception);
            $exit = self::FAILURE;
        }

        if ($exit !== self::SUCCESS) {
            $this->warn("Market regime stage market-regime:compute failed (exit {$exit}); the pipeline continues.");
            Log::warning('Market regime stage failed.', ['stage' => 'market-regime:compute', 'exit' => $exit]);
        }
    }
}
