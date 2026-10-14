<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AdvanceForecastBacktestRun;
use App\Models\ForecastBacktestRun;
use App\Models\Instrument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;

class ForecastBacktestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['runs' => ForecastBacktestRun::query()->latest('id')->limit(20)->get()->map(fn ($run) => $this->payload($run))]);
    }

    public function show(Request $request, ForecastBacktestRun $run): JsonResponse
    {
        $validated = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $cases = $run->cases()->with('instrument')->orderBy('instrument_id')->orderBy('as_of_date')
            ->paginate(100, ['*'], 'page', $validated['page'] ?? 1);
        $payload = $this->payload($run);
        $payload['cases'] = $cases->getCollection()->map(fn ($case) => [
            'ticker' => $case->instrument->ticker,
            'as_of_date' => $case->as_of_date->format('Y-m-d'),
            'target_date' => $case->target_date?->format('Y-m-d'),
            'status' => $case->status,
            'predicted_class' => $case->predicted_class,
            'observed_class' => $case->observed_class,
            'probabilities' => $case->probabilities,
            'reason' => $case->reason,
        ])->all();
        $payload['case_page'] = [
            'current' => $cases->currentPage(), 'last' => $cases->lastPage(), 'total' => $cases->total(),
        ];

        return response()->json(['run' => $payload]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tickers' => ['required', 'array', 'min:1', 'max:100'],
            'tickers.*' => ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9.\-]{0,14}$/'],
            'bars' => ['required', 'integer', 'between:1,100'],
        ]);
        $tickers = array_values(array_unique(array_map('strtoupper', $validated['tickers'])));
        if (count($tickers) !== count($validated['tickers'])) {
            throw ValidationException::withMessages(['tickers' => [__('forecast.backtest_duplicate_ticker')]]);
        }
        $instruments = Instrument::query()->whereIn('ticker', $tickers)->get()->keyBy('ticker');
        if ($instruments->count() !== count($tickers)) {
            throw ValidationException::withMessages(['tickers' => [__('forecast.backtest_unknown_ticker')]]);
        }
        $model = config('forecast.model');
        $question = config('forecast.question_version');
        if (! config('forecast.enabled') || ! config('forecast.rights_verified') || ! config('forecast.api_key')
            || ! is_string($model) || ! preg_match('/^jev-[0-9][a-zA-Z0-9.\-]*$/', $model)
            || ! is_string($question) || $question === '') {
            return response()->json(['message' => __('forecast.provider_not_configured')], 503);
        }

        // Preparation can insert 10,000 cases before queuing the first job.
        $lock = Cache::lock('forecast:backtest:start', 300);
        if (! $lock->get()) {
            return response()->json(['message' => __('forecast.busy')], 409);
        }
        try {
            if (ForecastBacktestRun::query()->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['bars' => [__('forecast.backtest_active')]]);
            }
            try {
                $run = DB::transaction(function () use ($request, $tickers, $instruments, $validated, $model, $question): ForecastBacktestRun {
                    $run = ForecastBacktestRun::create([
                        'requested_by' => $request->user()->id,
                        'tickers' => $tickers,
                        'bars_requested' => $validated['bars'],
                        'model_version' => $model,
                        'question_version' => $question,
                        'status' => 'queued',
                    ]);
                    $pendingCases = [];
                    $now = now();
                    foreach ($tickers as $ticker) {
                        // The newest stored bar has no known next close. Select
                        // the previous N bars; jobs verify the actual target.
                        $bars = $instruments[$ticker]->dailyBars()->orderByDesc('date')
                            ->limit($validated['bars'] + 1)->get()->slice(1);
                        foreach ($bars as $bar) {
                            $pendingCases[] = [
                                'run_id' => $run->id,
                                'instrument_id' => $instruments[$ticker]->id,
                                'as_of_date' => $bar->date->format('Y-m-d'),
                                'status' => 'queued',
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                            if (count($pendingCases) === 500) {
                                DB::table('forecast_backtest_cases')->insert($pendingCases);
                                $pendingCases = [];
                            }
                        }
                    }
                    if ($pendingCases !== []) {
                        DB::table('forecast_backtest_cases')->insert($pendingCases);
                    }
                    $run->update(['total' => $run->cases()->count()]);

                    return $run;
                });
            } catch (QueryException $exception) {
                if (ForecastBacktestRun::query()->whereIn('status', ['queued', 'running'])->exists()) {
                    throw ValidationException::withMessages(['bars' => [__('forecast.backtest_active')]]);
                }
                throw $exception;
            }
            if ($run->total === 0) {
                $run->update(['status' => 'completed']);
            } else {
                try {
                    AdvanceForecastBacktestRun::dispatch($run->id);
                } catch (Throwable $exception) {
                    Log::error('Forecast backtest queue dispatch failed', ['run_id' => $run->id, 'type' => get_class($exception)]);
                    $run->failDispatch();
                }
            }

            return response()->json(['run' => $this->payload($run->refresh())], 202);
        } finally {
            $lock->release();
        }
    }

    private function payload(ForecastBacktestRun $run): array
    {
        return [
            'id' => $run->id, 'tickers' => $run->tickers, 'bars_requested' => $run->bars_requested,
            'model_version' => $run->model_version, 'question_version' => $run->question_version,
            'status' => $run->status, 'total' => $run->total, 'processed' => $run->processed,
            'correct' => $run->correct, 'skipped' => $run->skipped,
            'accuracy' => $run->processed ? $run->correct / $run->processed : null,
            'created_at' => $run->created_at?->toISOString(),
        ];
    }
}
