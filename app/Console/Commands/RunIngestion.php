<?php

namespace App\Console\Commands;

use App\Enums\IngestionRunItemStatus;
use App\Enums\IngestionRunStatus;
use App\Models\IngestionRun;
use App\Models\Instrument;
use App\Models\Universe;
use App\Services\Ingestion\InstrumentIngestor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Throwable;

/**
 * Orchestrate one EOD ingestion run over a universe and record a per-instrument
 * ledger. Every instrument is processed in its own try/catch, so a single
 * failure is recorded instead of aborting the run; the run and each item are
 * persisted in separate transactions.
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
    public function handle(InstrumentIngestor $ingestor): int
    {
        $retry = $this->option('retry');

        try {
            if ($retry !== null && $retry !== '') {
                $run = $this->retryRun($ingestor, (int) $retry);
            } else {
                $run = $this->startUniverseRun($ingestor, (string) $this->option('universe'));
            }
        } catch (Throwable $exception) {
            // Pre-flight failures (unknown universe/run) are command errors;
            // per-instrument failures are handled inside process().
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->report($run);

        return $run->status === IngestionRunStatus::Failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Start a fresh run over every instrument of the named universe.
     */
    private function startUniverseRun(InstrumentIngestor $ingestor, string $slug): IngestionRun
    {
        $universe = Universe::query()->where('slug', $slug)->first();

        if ($universe === null) {
            throw new RuntimeException("No universe found for [{$slug}].");
        }

        $instruments = $universe->instruments()->orderBy('ticker')->get();

        return $this->process($ingestor, $instruments, $universe->id);
    }

    /**
     * Start a new run containing only the instruments that failed in a
     * previous run.
     */
    private function retryRun(InstrumentIngestor $ingestor, int $runId): IngestionRun
    {
        $previous = IngestionRun::query()->find($runId);

        if ($previous === null) {
            throw new RuntimeException("No ingestion run found for id [{$runId}].");
        }

        $failedInstrumentIds = $previous->items()
            ->where('status', IngestionRunItemStatus::Failed->value)
            ->pluck('instrument_id');

        $instruments = Instrument::query()
            ->whereIn('id', $failedInstrumentIds)
            ->orderBy('ticker')
            ->get();

        return $this->process($ingestor, $instruments, $previous->universe_id);
    }

    /**
     * Process each instrument in its own try/catch and finalize the run ledger.
     *
     * @param  Collection<int, Instrument>  $instruments
     */
    private function process(InstrumentIngestor $ingestor, Collection $instruments, ?int $universeId): IngestionRun
    {
        $run = IngestionRun::query()->create([
            'status' => IngestionRunStatus::Running,
            'universe_id' => $universeId,
            'started_at' => now(),
            'total' => $instruments->count(),
            'succeeded' => 0,
            'failed' => 0,
        ]);

        $succeeded = 0;
        $failed = 0;

        try {
            foreach ($instruments as $instrument) {
                try {
                    $barsStored = $ingestor->ingest($instrument);

                    $run->items()->create([
                        'instrument_id' => $instrument->id,
                        'status' => IngestionRunItemStatus::Success,
                        'bars_stored' => $barsStored,
                        'message' => null,
                    ]);

                    $succeeded++;
                } catch (Throwable $exception) {
                    $run->items()->create([
                        'instrument_id' => $instrument->id,
                        'status' => IngestionRunItemStatus::Failed,
                        'bars_stored' => 0,
                        'message' => $exception->getMessage(),
                    ]);

                    $failed++;
                }
            }
        } finally {
            // Always leave the run in a terminal state, even if an unexpected
            // error escapes the per-instrument guard.
            $run->forceFill([
                'status' => $this->statusFor($succeeded, $failed),
                'succeeded' => $succeeded,
                'failed' => $failed,
                'finished_at' => now(),
            ])->save();
        }

        return $run;
    }

    /**
     * Terminal status: `completed` only when nothing failed, `failed` only when
     * nothing succeeded, otherwise `partial`.
     */
    private function statusFor(int $succeeded, int $failed): IngestionRunStatus
    {
        if ($failed === 0) {
            return IngestionRunStatus::Completed;
        }

        if ($succeeded === 0) {
            return IngestionRunStatus::Failed;
        }

        return IngestionRunStatus::Partial;
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
