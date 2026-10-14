<?php

namespace App\Jobs;

use App\Models\ForecastBacktestRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdvanceForecastBacktestRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function __construct(public readonly int $runId, public readonly int $afterCaseId = 0) {}

    public function handle(): void
    {
        $run = ForecastBacktestRun::query()->find($this->runId);
        if ($run === null || in_array($run->status, ['completed', 'failed'], true)) {
            return;
        }
        $caseId = $run->cases()->where('id', '>', $this->afterCaseId)->orderBy('id')->value('id');
        if ($caseId === null) {
            return;
        }
        try {
            // One case and one successor at most are queued at a time. Newly
            // arrived default-queue ingestion jobs are never behind a run.
            RunForecastBacktestCase::dispatch($caseId);
            self::dispatch($run->id, $caseId);
        } catch (Throwable $exception) {
            Log::error('Forecast backtest queue advance failed', ['run_id' => $run->id, 'type' => get_class($exception)]);
            $run->failDispatch();
        }
    }

    public function failed(?Throwable $exception): void
    {
        ForecastBacktestRun::query()->find($this->runId)?->failDispatch();
    }
}
