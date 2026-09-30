<?php

namespace App\Console\Commands;

use App\Enums\IngestionRunItemStatus;
use App\Enums\IngestionRunStatus;
use App\Models\IngestionRun;
use App\Services\Ingestion\IngestionRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Orchestrate one EOD ingestion run over a universe and record a per-instrument
 * ledger. The run lifecycle lives in {@see IngestionRunner}; this command only
 * selects the scope, prints the summary and maps the terminal status to an exit
 * code.
 *
 * `--retry=<runId>` creates a new run that contains only the instruments that
 * failed in the referenced run, so succeeded instruments are never reprocessed.
 */
class RunIngestion extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ingestion:run
                            {--universe=sp500 : Universe slug to ingest}
                            {--retry= : Re-run only the failed instruments of this ingestion run id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run EOD ingestion over a universe and record a per-instrument ledger';

    /**
     * Execute the console command.
     */
    public function handle(IngestionRunner $runner): int
    {
        $retry = $this->option('retry');

        try {
            if ($retry !== null && $retry !== '') {
                $run = $runner->startRetryRun((int) $retry);
            } else {
                $run = $runner->startUniverseRun((string) $this->option('universe'));
            }
        } catch (Throwable $exception) {
            // Pre-flight failures (unknown universe/run) are command errors;
            // per-instrument failures are handled inside the runner.
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->report($run);

        return $run->status === IngestionRunStatus::Failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Print a summary plus one line per failed instrument.
     */
    private function report(IngestionRun $run): void
    {
        $this->info(sprintf(
            'Ingestion run #%d [%s]: total=%d succeeded=%d failed=%d.',
            $run->id,
            $run->status->value,
            $run->total,
            $run->succeeded,
            $run->failed,
        ));

        $failedItems = $run->items()
            ->where('status', IngestionRunItemStatus::Failed->value)
            ->with('instrument')
            ->orderBy('instrument_id')
            ->get();

        foreach ($failedItems as $item) {
            $this->warn("  {$item->instrument->ticker} failed: {$item->message}");
        }
    }
}
