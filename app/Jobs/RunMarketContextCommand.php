<?php

namespace App\Jobs;

use App\Services\MarketContext\MarketContextRunLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * An Admin rerun of one Market Context command (docs/specs/market-context-ui.md,
 * C3-C4), dispatched by `POST /api/admin/market-context/runs` after the
 * `queued` record exists. The command records its own run; it is called with
 * no options (latest 5 sessions, macro incremental). One attempt only.
 */
class RunMarketContextCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $tries = 1;

    /** `macro:refresh` has a 300 s budget plus one series in flight. */
    public $timeout = 900;

    public function __construct(public readonly string $artisanCommand) {}

    public function handle(): void
    {
        $exit = Artisan::call($this->artisanCommand);

        // A path that ran nothing never left `queued` (for example a malformed
        // FRED key): close the Admin's record with the command's exit code.
        if (MarketContextRunLog::storedStatus($this->artisanCommand) === MarketContextRunLog::QUEUED) {
            MarketContextRunLog::finished($this->artisanCommand, $exit);
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        if (MarketContextRunLog::storedStatus($this->artisanCommand) === MarketContextRunLog::QUEUED) {
            MarketContextRunLog::finished($this->artisanCommand, 1);
        }
    }
}
