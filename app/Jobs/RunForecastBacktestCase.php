<?php

namespace App\Jobs;

use App\Models\ForecastBacktestCase;
use App\Models\ForecastBacktestRun;
use App\Models\ForecastPrediction;
use App\Services\Forecast\ForecastService;
use App\Services\Forecast\ForecastStateBuilder;
use App\Services\Forecast\ForecastUnavailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunForecastBacktestCase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 30;

    public function __construct(public readonly int $caseId) {}

    public function handle(ForecastService $service, ForecastStateBuilder $builder): void
    {
        $case = ForecastBacktestCase::query()->with('instrument')->find($this->caseId);
        if ($case === null || $case->status !== 'queued' || $case->run->status === 'failed') {
            return;
        }
        $case->run()->where('status', 'queued')->update(['status' => 'running']);
        try {
            if (config('forecast.model') !== $case->run->model_version
                || config('forecast.question_version') !== $case->run->question_version) {
                $this->finish('skipped', ['reason' => 'version_changed']);
                return;
            }
            $date = $case->as_of_date->format('Y-m-d');
            $targetDate = $builder->targetDate($date, 1);
            $targetBar = $case->instrument->dailyBars()->whereDate('date', $targetDate)->first();
            if ($targetBar === null) {
                $this->finish('skipped', ['target_date' => $targetDate, 'reason' => 'missing_target_bar']);
                return;
            }
            $result = $service->predict($case->instrument, 1, 0, $date, 'historical');
            $probabilities = $result['probabilities'];
            arsort($probabilities);
            $predicted = array_key_first($probabilities);
            $reference = (float) $result['reference_close'];
            $close = (float) $targetBar->close;
            $observed = $close > $reference ? 'up' : ($close < $reference ? 'down' : 'flat');
            ForecastPrediction::query()->where('instrument_id', $case->instrument_id)
                ->whereDate('as_of_date', $date)->where('horizon', 1)
                ->where('model_version', $result['model_version'])
                ->where('question_version', $result['question_version'])
                ->whereNull('resolved_at')->update(['observed_close' => $close, 'observed_class' => $observed, 'resolved_at' => now()]);
            $this->finish('completed', [
                'target_date' => $targetDate, 'predicted_class' => $predicted,
                'observed_class' => $observed, 'probabilities' => $result['probabilities'],
            ]);
        } catch (ForecastUnavailable $exception) {
            $this->finish('skipped', ['reason' => $exception->reason]);
        } catch (Throwable $exception) {
            Log::error('Forecast backtest case failed', ['case_id' => $this->caseId, 'type' => get_class($exception)]);
            $this->finish('skipped', ['reason' => 'internal_error']);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->finish('skipped', ['reason' => 'worker_failed']);
    }

    /** @param array<string, mixed> $fields */
    private function finish(string $status, array $fields): void
    {
        $runId = ForecastBacktestCase::query()->whereKey($this->caseId)->value('run_id');
        if ($runId === null) {
            return;
        }
        DB::transaction(function () use ($status, $fields, $runId): void {
            $run = ForecastBacktestRun::query()->lockForUpdate()->find($runId);
            if ($run === null || $run->status === 'failed') {
                return;
            }
            $case = ForecastBacktestCase::query()->lockForUpdate()->find($this->caseId);
            if ($case === null || $case->status !== 'queued') {
                return;
            }
            $case->update($fields + ['status' => $status]);
            $run->processed += (int) ($status === 'completed');
            $run->skipped += (int) ($status === 'skipped');
            $run->correct += (int) ($status === 'completed' && $fields['predicted_class'] === $fields['observed_class']);
            $run->status = $run->processed + $run->skipped >= $run->total ? 'completed' : 'running';
            $run->save();
        });
    }
}
