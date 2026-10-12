<?php

namespace App\Jobs;

use App\Services\Ingestion\IngestionRunner;
use App\Services\Screener\ScreenerRowBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * The single execution unit of an ingestion run.
 *
 * The trigger endpoint creates the `queued` run row and dispatches this job, so
 * the database queue + `php artisan queue:work` make it a background run the
 * SPA observes by polling. It never retries (`$tries = 1`) so a failed run is not silently
 * re-executed.
 */
class RunIngestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted. A run is a one-shot unit:
     * a failure is recorded in the ledger and recovered explicitly by retrying
     * the failed instruments.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * @param  list<int>  $instrumentIds
     */
    public function __construct(
        public readonly int $runId,
        public readonly array $instrumentIds,
    ) {}

    /**
     * Execute the run's lifecycle through the shared runner.
     */
    public function handle(IngestionRunner $runner, ScreenerRowBuilder $screener): void
    {
        $runner->process($this->runId, $this->instrumentIds);

        // New bars change the latest close/date the Screener shows. A failed
        // refresh is reported but never turns a finished run into a failure.
        try {
            $screener->refresh();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
