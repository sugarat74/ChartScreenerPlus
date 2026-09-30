<?php

namespace App\Services\Ingestion;

use App\Enums\IngestionRunItemStatus;
use App\Enums\IngestionRunStatus;
use App\Models\IngestionRun;
use App\Models\Instrument;
use App\Models\Universe;
use RuntimeException;
use Throwable;

/**
 * Orchestrates one EOD ingestion run over the run ledger.
 *
 * The work is split so the same logic serves both execution models:
 * `prepare*Run()` only resolves the scope and creates a `queued` run row,
 * while `process()` moves that run through `running` to a terminal state,
 * recording one item per instrument. The artisan command composes
 * prepare + process synchronously (`start*Run()`); the admin panel creates the
 * `queued` row, dispatches a job and returns immediately, and the job calls
 * `process()` — inline on the default `sync` connection.
 *
 * Every instrument is processed in its own try/catch, so a single failure is
 * recorded instead of aborting the run.
 */
class IngestionRunner
{
    public function __construct(private readonly InstrumentIngestor $ingestor) {}

    /**
     * Resolve a universe and create its `queued` run.
     *
     * Throws (creating no run row) when the universe slug is unknown.
     *
     * @return array{run: IngestionRun, instrumentIds: list<int>}
     */
    public function prepareUniverseRun(string $slug): array
    {
        $universe = Universe::query()->where('slug', $slug)->first();

        if ($universe === null) {
            throw new RuntimeException("No universe found for [{$slug}].");
        }

        $instrumentIds = $universe->instruments()
            ->orderBy('instruments.ticker')
            ->pluck('instruments.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $run = $this->createQueuedRun($universe->id, count($instrumentIds));

        return ['run' => $run, 'instrumentIds' => $instrumentIds];
    }

    /**
     * Create a `queued` retry run containing only the instruments that failed
     * in the referenced run, inheriting its universe.
     *
     * Throws (creating no run row) when the referenced run id is unknown. A
     * referenced run with no failed items yields an empty run; the admin
     * endpoint rejects that case before calling this method.
     *
     * @return array{run: IngestionRun, instrumentIds: list<int>}
     */
    public function prepareRetryRun(int $runId): array
    {
        $previous = IngestionRun::query()->find($runId);

        if ($previous === null) {
            throw new RuntimeException("No ingestion run found for id [{$runId}].");
        }

        $instrumentIds = $previous->items()
            ->where('status', IngestionRunItemStatus::Failed->value)
            ->orderBy('instrument_id')
            ->pluck('instrument_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $run = $this->createQueuedRun($previous->universe_id, count($instrumentIds));

        return ['run' => $run, 'instrumentIds' => $instrumentIds];
    }

    /**
     * Process a prepared run: set it `running`, ingest each instrument and
     * finalize the ledger with a terminal status.
     *
     * @param  list<int>  $instrumentIds
     */
    public function process(int $runId, array $instrumentIds): IngestionRun
    {
        $run = IngestionRun::query()->findOrFail($runId);

        $instruments = Instrument::query()
            ->whereIn('id', $instrumentIds)
            ->orderBy('ticker')
            ->get();

        $run->forceFill([
            'status' => IngestionRunStatus::Running,
            'started_at' => now(),
        ])->save();

        $succeeded = 0;
        $failed = 0;

        try {
            foreach ($instruments as $instrument) {
                try {
                    $barsStored = $this->ingestor->ingest($instrument);

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
     * Resolve the universe, create the run and process it synchronously.
     */
    public function startUniverseRun(string $slug): IngestionRun
    {
        $prepared = $this->prepareUniverseRun($slug);

        return $this->process($prepared['run']->id, $prepared['instrumentIds']);
    }

    /**
     * Create the retry run and process it synchronously.
     */
    public function startRetryRun(int $runId): IngestionRun
    {
        $prepared = $this->prepareRetryRun($runId);

        return $this->process($prepared['run']->id, $prepared['instrumentIds']);
    }

    /**
     * Create the `queued` ledger row for a run scope.
     */
    private function createQueuedRun(?int $universeId, int $total): IngestionRun
    {
        return IngestionRun::query()->create([
            'status' => IngestionRunStatus::Queued,
            'universe_id' => $universeId,
            'started_at' => null,
            'total' => $total,
            'succeeded' => 0,
            'failed' => 0,
        ]);
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
}
