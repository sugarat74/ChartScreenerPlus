<?php

namespace App\Console\Commands;

use App\Services\Screener\ScreenerRowBuilder;
use Illuminate\Console\Command;

/**
 * Rebuild the precomputed Screener rows (screener-precomputed).
 *
 * Runs in `ingestion:pipeline` (after patterns, before alerts), after a manual
 * Admin ingestion and on deploy. Run it by hand after any stage command
 * (`indicators:compute`, `signals:detect`, `patterns:detect`) so the Screener
 * reflects the new data.
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
        $rows = $builder->refresh();

        $this->info(sprintf('Screener rows refreshed: %d instrument(s) in %.2f s.', $rows, microtime(true) - $started));

        return self::SUCCESS;
    }
}
