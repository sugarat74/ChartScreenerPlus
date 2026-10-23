<?php

namespace App\Console\Concerns;

use App\Services\MarketContext\MarketContextRunLog;
use Closure;
use Throwable;

/**
 * Records a Market Context command's run in {@see MarketContextRunLog}
 * (docs/specs/market-context-ui.md, C4): `running` before `$run`, then
 * `succeeded` (exit 0) or `failed` with the exit code, which also renews the
 * Market Context cache. An exception records `failed` (exit 1) and is
 * rethrown, so callers see the same behaviour as before.
 */
trait RecordsMarketContextRun
{
    /** @param  Closure(): int  $run */
    protected function recordingRun(string $command, Closure $run): int
    {
        MarketContextRunLog::started($command);

        try {
            $exit = $run();
        } catch (Throwable $exception) {
            MarketContextRunLog::finished($command, 1);

            throw $exception;
        }

        MarketContextRunLog::finished($command, $exit);

        return $exit;
    }
}
