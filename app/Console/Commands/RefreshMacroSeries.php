<?php

namespace App\Console\Commands;

use App\Console\Concerns\RecordsMarketContextRun;
use App\Services\Fundamentals\Sleeper;
use App\Services\Macro\FredClient;
use App\Services\Macro\MacroSeriesRefresher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Refresh the Macro Series from FRED (docs/specs/macro-series-fred.md).
 *
 * Opt-in: with `MACRO_ENABLED=false` it requests nothing, logs once and exits
 * 0. Enabled without a well-formed `FRED_API_KEY`, or with an unknown or
 * disabled `--series`, it exits 1 without any request. A held lock is a skip
 * (exit 0). Series are refreshed one at a time; a failing series is recorded
 * (`last_error`) and the run goes on. `invalid_key` stops the run (exit 1);
 * otherwise it exits 1 only when every attempted series failed.
 *
 * Bounded: once `run_budget_seconds` is spent, the remaining series are
 * skipped (the next run picks them up), so a FRED outage cannot hold the EOD
 * pipeline, which runs this command as an isolated stage, for long.
 *
 * The first run backfills `backfill_years` by itself; `--backfill` re-fetches
 * that whole window.
 *
 * Its run is recorded for the Admin tab and renews the Market Context cache
 * (market-context-ui), except on the disabled and lock-held exits, which run
 * nothing.
 */
class RefreshMacroSeries extends Command
{
    use RecordsMarketContextRun;

    protected $signature = 'macro:refresh
                            {--series=* : Only these codes (e.g. --series=DGS10 --series=WALCL)}
                            {--backfill : Re-fetch the full backfill window}';

    protected $description = 'Refresh the FRED macro series used by the Market Context';

    public function handle(MacroSeriesRefresher $refresher, Sleeper $sleeper): int
    {
        if (! config('macro.enabled')) {
            $this->info('Macro series are disabled (MACRO_ENABLED=false); nothing was requested.');
            Log::info('Macro refresh skipped: disabled.');

            return self::SUCCESS;
        }
        if (! FredClient::validKey(config('macro.fred_api_key'))) {
            return $this->recordingRun('macro:refresh', function (): int {
                $this->error('FRED_API_KEY is missing or malformed (32 lower-case letters and digits); nothing was requested.');

                return self::FAILURE;
            });
        }

        $enabled = array_keys(MacroSeriesRefresher::enabledSeries());
        $requested = array_values(array_unique(array_filter(array_map(
            fn (mixed $code): string => strtoupper(trim((string) $code)),
            (array) $this->option('series'),
        ))));
        $unknown = array_values(array_diff($requested, $enabled));
        if ($unknown !== []) {
            return $this->recordingRun('macro:refresh', function () use ($unknown, $enabled): int {
                $this->error('Unknown or disabled series: '.implode(', ', $unknown).'. Enabled: '.implode(', ', $enabled).'. Nothing was requested.');

                return self::FAILURE;
            });
        }
        $codes = $requested === [] ? $enabled : array_values(array_intersect($enabled, $requested));

        $lock = Cache::lock('macro:refresh', (int) config('macro.lock_ttl_seconds', 900));
        if (! $lock->get()) {
            $this->warn('Macro refresh is already running; skipping.');

            return self::SUCCESS;
        }

        try {
            return $this->recordingRun(
                'macro:refresh',
                fn (): int => $this->refreshAll($refresher, $sleeper, $codes, (bool) $this->option('backfill')),
            );
        } finally {
            $lock->release();
        }
    }

    /** @param  list<string>  $codes */
    private function refreshAll(MacroSeriesRefresher $refresher, Sleeper $sleeper, array $codes, bool $backfill): int
    {
        $deadline = $sleeper->now() + max(1, (int) config('macro.run_budget_seconds', 300));
        $updated = 0;
        $failed = 0;
        $skipped = [];

        foreach ($codes as $code) {
            if ($sleeper->now() >= $deadline) {
                $skipped[] = $code;

                continue;
            }

            $result = $refresher->refresh($code, $backfill);
            if ($result['result'] === MacroSeriesRefresher::RESULT_FAILED) {
                $failed++;
                $this->warn("{$code}: failed ({$result['error']})");
                if ($result['error'] === 'invalid_key') {
                    $this->error('FRED rejected FRED_API_KEY (invalid_key); run stopped.');

                    return self::FAILURE;
                }

                continue;
            }

            $updated++;
            $this->line(sprintf(
                '%s: %d stored (%d new, %d revised, %d removed), latest %s',
                $code, $result['stored'], $result['new'], $result['revised'], $result['removed'], $result['latest'] ?? 'none',
            ));
        }

        if ($skipped !== []) {
            $this->warn('Time budget spent; skipped until the next run: '.implode(', ', $skipped).'.');
            Log::warning('Macro refresh time budget spent.', ['skipped' => $skipped]);
        }

        $this->info(sprintf('Macro refresh: %d updated, %d failed, %d skipped.', $updated, $failed, count($skipped)));

        $attempted = $updated + $failed;

        return $attempted > 0 && $failed === $attempted ? self::FAILURE : self::SUCCESS;
    }
}
