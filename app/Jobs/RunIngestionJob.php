<?php

namespace App\Jobs;

use App\Services\Ingestion\IngestionRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The single execution unit of an ingestion run.
 *
 * The trigger endpoint creates the `queued` run row and dispatches this job, so
 * the queue connection decides sync vs async: on the default `sync` connection
 * it runs inline in the same request (no worker needed), while `database` +
 * `php artisan queue:work` makes it a real background run the SPA observes by
 * polling. It never retries (`$tries = 1`) so a failed run is not silently
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
    public function handle(IngestionRunner $runner): void
    {
        $runner->process($this->runId, $this->instrumentIds);
    }
}
