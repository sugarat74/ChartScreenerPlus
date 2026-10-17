<?php

namespace App\Console\Commands;

use App\Services\Fundamentals\FundamentalsRefresher;
use App\Services\Fundamentals\SecEdgarClient;
use App\Services\Fundamentals\SecUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Refresh Company Fundamentals from SEC EDGAR (docs/specs/company-fundamentals-sec.md).
 *
 * Opt-in and separate from the EOD pipeline: it refuses to run (exit 1, no
 * request) unless `SEC_FUNDAMENTALS_ENABLED=true` and `SEC_USER_AGENT` is a
 * descriptive contact. A held lock is a skip (exit 0). A `403` from the SEC
 * stops the run (exit 1); any other per-company failure is recorded and the
 * run continues, exiting 1 only when every attempted company failed. It
 * writes only `company_fundamentals`.
 *
 * One-time backfill after enabling: `php artisan fundamentals:refresh --force`.
 */
class RefreshFundamentals extends Command
{
    protected $signature = 'fundamentals:refresh
                            {--ticker=* : Only these tickers (e.g. --ticker=AAPL --ticker=BRK.B)}
                            {--force : Fetch companyfacts even when no new filing is listed}';

    protected $description = 'Refresh SEC EDGAR company fundamentals for the Company Card';

    public function handle(SecEdgarClient $client, FundamentalsRefresher $refresher): int
    {
        if (! config('fundamentals.enabled')) {
            $this->error('SEC fundamentals are disabled (SEC_FUNDAMENTALS_ENABLED=false); nothing was requested.');

            return self::FAILURE;
        }
        if (! $client->isConfigured()) {
            $this->error('SEC_USER_AGENT must name the operator and a contact email (e.g. "Chartiko ops@example.com"), and the SEC URLs must be https; nothing was requested.');

            return self::FAILURE;
        }

        $lock = Cache::lock('fundamentals:refresh', (int) config('fundamentals.lock_ttl_seconds', 3600));
        if (! $lock->get()) {
            $this->warn('Fundamentals refresh is already running; skipping.');

            return self::SUCCESS;
        }

        try {
            return $this->refreshAll($refresher);
        } finally {
            $lock->release();
        }
    }

    private function refreshAll(FundamentalsRefresher $refresher): int
    {
        $tickers = array_values(array_filter(array_map('strval', (array) $this->option('ticker'))));
        $force = (bool) $this->option('force');
        $instruments = $refresher->instruments($tickers);
        if ($instruments->isEmpty()) {
            $this->warn('No instruments to refresh.');

            return self::SUCCESS;
        }

        try {
            $map = $refresher->tickerMap();
        } catch (SecUnavailable $exception) {
            $this->error("Could not load the SEC ticker map ({$exception->getMessage()}).");

            return self::FAILURE;
        }

        [$groups, $missing] = $refresher->group($instruments, $map);
        foreach ($missing as $instrument) {
            $refresher->markNoCik($instrument);
        }

        $counts = array_fill_keys([
            FundamentalsRefresher::RESULT_UPDATED, FundamentalsRefresher::RESULT_UNCHANGED,
            FundamentalsRefresher::RESULT_NO_DATA, FundamentalsRefresher::RESULT_FAILED,
        ], 0);

        foreach ($groups as $cik => $members) {
            try {
                $result = $refresher->refresh((string) $cik, $members, $force);
            } catch (SecUnavailable $exception) {
                $this->error("The SEC refused the request ({$exception->getMessage()}); check SEC_USER_AGENT. Run stopped.");

                return self::FAILURE;
            }
            $counts[$result]++;
            if ($result === FundamentalsRefresher::RESULT_FAILED) {
                $names = implode(', ', array_map(fn ($instrument): string => $instrument->ticker, $members));
                $this->warn("Failed: {$names} (CIK {$cik}).");
            }
        }

        $this->info(sprintf(
            'Fundamentals refresh: %d updated, %d unchanged, %d without data, %d without CIK, %d failed.',
            $counts[FundamentalsRefresher::RESULT_UPDATED],
            $counts[FundamentalsRefresher::RESULT_UNCHANGED],
            $counts[FundamentalsRefresher::RESULT_NO_DATA],
            count($missing),
            $counts[FundamentalsRefresher::RESULT_FAILED],
        ));

        $attempted = count($groups);

        return $attempted > 0 && $counts[FundamentalsRefresher::RESULT_FAILED] === $attempted ? self::FAILURE : self::SUCCESS;
    }
}
