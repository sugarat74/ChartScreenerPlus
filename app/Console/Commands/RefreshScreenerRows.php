<?php

namespace App\Console\Commands;

use App\Services\Screener\ScreenerRowBuilder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rebuild the precomputed Screener rows (screener-precomputed).
 *
 * Runs in `ingestion:pipeline` (after patterns, before alerts), after every
 * ingestion run (Admin job, `ingestion:run`, retries), after `ingestion:scrape`,
 * the universe seeder and on deploy. Run it by hand after `indicators:compute`,
 * `signals:detect` or `patterns:detect` so the Screener reflects the new data.
 * A failure is reported and exits `1`.
 */
class RefreshScreenerRows extends Command
{
    /**
     * @var string
     */
    protected $signature = 'screener:refresh';

    /**
     * @var string
     */
    protected $description = 'Rebuild the precomputed Screener rows from the stored market data';

    public function handle(ScreenerRowBuilder $builder): int
    {
        $started = microtime(true);

        try {
            $rows = $builder->refresh();
        } catch (Throwable $exception) {
            report($exception);
            $this->error("Screener rows were not refreshed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf('Screener rows refreshed: %d instrument(s) in %.2f s.', $rows, microtime(true) - $started));

        return self::SUCCESS;
    }
}
