<?php

namespace App\Http\Controllers\Admin;

use App\Enums\IngestionRunItemStatus;
use App\Enums\IngestionRunStatus;
use App\Http\Controllers\Controller;
use App\Jobs\RunIngestionJob;
use App\Models\IngestionRun;
use App\Models\IngestionRunItem;
use App\Models\Universe;
use App\Services\Ingestion\IngestionRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Admin control surface over the EOD ingestion ledger.
 *
 * Every route lives inside the existing `['auth:sanctum', 'admin']` group, so
 * guests get 401 and authenticated non-admins get 403; the SPA only hides the
 * tab, it never enforces access. The trigger/retry endpoints create the
 * `queued` run row and dispatch {@see RunIngestionJob}. The database queue
 * keeps the request short; a queue worker owns the execution lifecycle.
 */
class IngestionRunController extends Controller
{
    public function __construct(private readonly IngestionRunner $runner) {}

    /**
     * List the most recent runs, newest first, without their items.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 20);
        $limit = min(max($limit, 1), 100);

        $runs = IngestionRun::query()
            ->with('universe')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (IngestionRun $run): array => $this->runPayload($run));

        return response()->json(['runs' => $runs]);
    }

    /**
     * Trigger a fresh run over a universe and dispatch its job.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'universe' => ['sometimes', 'string'],
        ]);

        $slug = $validated['universe'] ?? (string) config('ingestion.universe');

        if (! Universe::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'universe' => [__('messages.ingestion.universe_unknown', ['slug' => $slug])],
            ]);
        }

        $prepared = $this->runner->prepareUniverseRun($slug);

        RunIngestionJob::dispatch($prepared['run']->id, $prepared['instrumentIds']);

        return response()->json(
            ['run' => $this->runPayload($prepared['run']->refresh())],
            202,
        );
    }

    /**
     * Show one run with its per-instrument log.
     */
    public function show(int $run): JsonResponse
    {
        $ingestionRun = IngestionRun::query()->find($run);

        if ($ingestionRun === null) {
            abort(404);
        }

        $ingestionRun->load(['universe', 'items.instrument']);

        $payload = $this->runPayload($ingestionRun);
        $payload['items'] = $ingestionRun->items
            ->sortBy(fn (IngestionRunItem $item): string => $item->status === IngestionRunItemStatus::Processing
                ? "0:{$item->instrument?->ticker}"
                : "1:{$item->instrument?->ticker}")
            ->values()
            ->map(fn (IngestionRunItem $item): array => $this->itemPayload($item))
            ->all();

        return response()->json(['run' => $payload]);
    }

    /**
     * Re-run only the instruments that failed in a finished run.
     */
    public function retry(int $run): JsonResponse
    {
        $ingestionRun = IngestionRun::query()->find($run);

        if ($ingestionRun === null) {
            abort(404);
        }

        if (! in_array($ingestionRun->status, $this->terminalStatuses(), true)) {
            throw ValidationException::withMessages([
                'run' => [__('messages.ingestion.run_not_finished')],
            ]);
        }

        $hasFailedItems = $ingestionRun->items()
            ->where('status', IngestionRunItemStatus::Failed->value)
            ->exists();

        if (! $hasFailedItems) {
            throw ValidationException::withMessages([
                'run' => [__('messages.ingestion.run_without_failures')],
            ]);
        }

        $prepared = $this->runner->prepareRetryRun($ingestionRun->id);

        RunIngestionJob::dispatch($prepared['run']->id, $prepared['instrumentIds']);

        return response()->json(
            ['run' => $this->runPayload($prepared['run']->refresh())],
            202,
        );
    }

    /**
     * Terminal run statuses (a run that has finished and can be retried).
     *
     * @return array<int, IngestionRunStatus>
     */
    private function terminalStatuses(): array
    {
        return [
            IngestionRunStatus::Completed,
            IngestionRunStatus::Failed,
            IngestionRunStatus::Partial,
        ];
    }

    /**
     * Shape one run for the API.
     *
     * @return array<string, mixed>
     */
    private function runPayload(IngestionRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'universe' => $run->universe === null ? null : [
                'id' => $run->universe->id,
                'slug' => $run->universe->slug,
                'name' => $run->universe->name,
            ],
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'total' => $run->total,
            'succeeded' => $run->succeeded,
            'failed' => $run->failed,
        ];
    }

    /**
     * Shape one per-instrument log line for the API.
     *
     * @return array<string, mixed>
     */
    private function itemPayload(IngestionRunItem $item): array
    {
        return [
            'id' => $item->id,
            'ticker' => $item->instrument?->ticker,
            'status' => $item->status->value,
            'bars_stored' => $item->bars_stored,
            'message' => $item->message,
        ];
    }
}
