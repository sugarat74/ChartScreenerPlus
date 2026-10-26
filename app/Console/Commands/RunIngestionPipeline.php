<?php

namespace App\Console\Commands;

use App\Models\Universe;
use App\Services\Market\MarketCalendar;
use App\Support\ListingMarket;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
 * Per Listing Market (docs/specs/multi-market-universes.md, Feature 2):
 * `--market=US|XMAD|XETR` runs the market's Screener universes in `position`
 * order. A ticker that belongs to several universes is handled once, by the
 * first one (`--except-universe`). The Market Context block, macro, Market
 * Regime, alerts and forecast stages are US-only. The first universe keeps
 * the failure policy below; a later universe's failure is isolated (logged,
 * later stages still run) and only turns the exit code into 1.
 *
 * Guards:
 * - A command-level cache lock, global to every market, prevents overlapping
 *   invocations. A pipeline that finds it held waits up to
 *   `ingestion.lock_wait_seconds`, then skips with a warning (not a failure).
 * - Unless `--force`, a non-trading day (weekend or committed holiday of the
 *   market) is skipped. The schedule already skips those, so this is defense
 *   in depth and the directly testable path.
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
                            {--market=US : Listing Market to run (US, XMAD or XETR)}
                            {--universe= : Limit the run to one universe slug (defaults to every Screener universe of the market, in position order)}
                            {--force : Run even on a non-trading day}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the full EOD pipeline for one Listing Market (ingest, compute indicators, detect signals)';

    /**
     * Execute the console command.
     */
    public function handle(MarketCalendar $calendar): int
    {
        $market = strtoupper((string) ($this->option('market') ?: ListingMarket::US));

        if (! ListingMarket::isValid($market)) {
            $this->error('Unknown listing market ['.$market.']; use one of '.implode(', ', ListingMarket::codes()).'.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $calendar->isTradingDay($market, now($calendar->timezone($market)))) {
            $this->info('Not a trading day; EOD pipeline skipped.');

            return self::SUCCESS;
        }

        $lock = Cache::lock('ingestion:pipeline', (int) config('ingestion.lock_ttl_seconds'));

        if (! $this->acquire($lock)) {
            $this->warn('EOD pipeline is already running; skipping.');
            Log::warning('EOD pipeline skipped: the pipeline lock stayed held.', ['market' => $market]);

            return self::SUCCESS;
        }

        try {
            return $this->runMarket($market);
        } finally {
            $lock->release();
        }
    }

    /**
     * Take the global pipeline lock, waiting up to `ingestion.lock_wait_seconds`
     * when another pipeline holds it, so per-market runs queue up instead of
     * being dropped.
     */
    private function acquire(Lock $lock): bool
    {
        if ($lock->get()) {
            return true;
        }

        $wait = (int) config('ingestion.lock_wait_seconds');

        if ($wait <= 0) {
            return false;
        }

        $this->info("Another pipeline is running; waiting up to {$wait} s for it.");

        try {
            $lock->block($wait);

            return true;
        } catch (LockTimeoutException) {
            return false;
        }
    }

    /**
     * Run the stages of one Listing Market with the lock held.
     */
    private function runMarket(string $market): int
    {
        $slugs = $this->universeSlugs($market);

        if ($slugs === []) {
            $this->info("No Screener universe for market {$market}; EOD pipeline skipped.");

            return self::SUCCESS;
        }

        $isUs = $market === ListingMarket::US;
        $handled = [];
        $indicators = $signals = $patterns = self::SUCCESS;
        $secondaryFailed = false;

        foreach ($slugs as $position => $universe) {
            $except = ['--except-universe' => $handled];

            if ($position === 0) {
                // The first universe keeps today's failure policy.
                $ingestion = $this->call('ingestion:run', ['--universe' => $universe] + $except);

                if ($ingestion !== self::SUCCESS) {
                    $this->error('EOD pipeline stopped: ingestion failed; indicators, signals and patterns were skipped.');

                    return self::FAILURE;
                }

                $indicators = $this->call('indicators:compute', ['--universe' => $universe] + $except);
                $signals = $this->call('signals:detect', ['--universe' => $universe] + $except);
                $patterns = $this->call('patterns:detect', ['--universe' => $universe] + $except);
            } elseif (! $this->runSecondaryUniverse($universe, $except)) {
                $secondaryFailed = true;
            }

            $handled[] = $universe;
        }

        if ($isUs) {
            $this->runMarketContext();
            $this->runMacro();
            $this->runMarketRegime();
        }

        // The Screener and the alerts read the precomputed rows, so they are
        // rebuilt from whatever the stages above stored.
        $screener = $this->call('screener:refresh');
        $alerts = self::SUCCESS;
        $forecasts = self::SUCCESS;

        if ($isUs) {
            $alerts = $signals === self::SUCCESS && $screener === self::SUCCESS ? $this->call('alerts:evaluate') : self::FAILURE;
            $forecasts = $this->call('forecast:resolve');

            if ($signals !== self::SUCCESS) {
                $this->warn('Alerts were not evaluated because signal detection failed.');
            } elseif ($screener !== self::SUCCESS) {
                $this->warn('Alerts were not evaluated because the screener rows could not be refreshed.');
            }
        }

        if ($secondaryFailed || $indicators !== self::SUCCESS || $signals !== self::SUCCESS || $patterns !== self::SUCCESS || $screener !== self::SUCCESS || $alerts !== self::SUCCESS || $forecasts !== self::SUCCESS) {
            $this->error('EOD pipeline finished with errors.');

            return self::FAILURE;
        }

        $this->info('EOD pipeline completed.');

        return self::SUCCESS;
    }

    /**
     * The universe slugs to process, in order: the `--universe` option alone,
     * otherwise every Screener universe of the market by `position`. With none
     * seeded, the US market falls back to the configured default universe so
     * an unseeded install reports "No universe found" as it always did.
     *
     * @return list<string>
     */
    private function universeSlugs(string $market): array
    {
        $option = (string) $this->option('universe');

        if ($option !== '') {
            return [$option];
        }

        $slugs = Universe::query()
            ->screener()
            ->where('market', $market)
            ->pluck('slug')
            ->map(fn (mixed $slug): string => (string) $slug)
            ->values()
            ->all();

        if ($slugs === [] && $market === ListingMarket::US) {
            return [(string) config('ingestion.universe')];
        }

        return $slugs;
    }

    /**
     * Run every stage of a later universe in isolation: a failing or throwing
     * stage is logged and the remaining stages still run. Returns whether all
     * of them succeeded; the caller turns a failure into the exit code only.
     *
     * @param  array<string, list<string>>  $except
     */
    private function runSecondaryUniverse(string $universe, array $except): bool
    {
        $ok = true;

        foreach (['ingestion:run', 'indicators:compute', 'signals:detect', 'patterns:detect'] as $stage) {
            try {
                $exit = $this->call($stage, ['--universe' => $universe] + $except);
            } catch (Throwable $exception) {
                report($exception);
                $exit = self::FAILURE;
            }

            if ($exit !== self::SUCCESS) {
                $ok = false;
                $this->warn("Universe {$universe} stage {$stage} failed (exit {$exit}); the pipeline continues.");
                Log::warning('Secondary universe stage failed.', ['universe' => $universe, 'stage' => $stage, 'exit' => $exit]);
            }
        }

        return $ok;
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
