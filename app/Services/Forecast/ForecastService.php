<?php

namespace App\Services\Forecast;

use App\Models\ForecastPrediction;
use App\Models\Instrument;
use Illuminate\Support\Facades\Cache;

class ForecastService
{
    public function __construct(private readonly ForecastStateBuilder $builder, private readonly JevClient $client) {}

    /** @return array<string, mixed> */
    public function predict(Instrument $instrument, int $horizon, float $minimumProbability, ?string $asOfDate = null, string $source = 'prospective'): array
    {
        $input = $this->builder->build($instrument, $asOfDate);
        if ($input === null) {
            throw new ForecastUnavailable('insufficient_data');
        }

        $model = config('forecast.model');
        if (! config('forecast.enabled') || ! config('forecast.rights_verified') || ! config('forecast.api_key') || ! is_string($model) || ! preg_match('/^jev-[0-9][a-zA-Z0-9.\-]*$/', $model)) {
            throw new ForecastUnavailable('provider_not_configured');
        }

        $key = [
            'instrument_id' => $instrument->id,
            'horizon' => $horizon,
            'model_version' => $model,
            'question_version' => config('forecast.question_version'),
        ];
        $lockKey = 'forecast:'.hash('sha256', json_encode($key + ['as_of_date' => $input['as_of_date']], JSON_THROW_ON_ERROR));
        $lock = Cache::lock($lockKey, 30);
        if (! $lock->get()) {
            throw new ForecastUnavailable('busy');
        }

        try {
            $prediction = ForecastPrediction::query()->where($key)->whereDate('as_of_date', $input['as_of_date'])->first();
            if ($prediction === null) {
                $target = $this->builder->targetDate($input['as_of_date'], $horizon);
                $probabilities = $this->client->predict($input['state'], $horizon, $target);
                $prediction = ForecastPrediction::query()->create($key + [
                    'as_of_date' => $input['as_of_date'],
                    'reference_close' => $input['reference_close'],
                    'target_date' => $target,
                    'input_hash' => $input['input_hash'],
                    'probability_up' => $probabilities['up'],
                    'probability_down' => $probabilities['down'],
                    'probability_flat' => $probabilities['flat'],
                    'source' => $source,
                ]);
            }
        } finally {
            $lock->release();
        }

        $probabilities = [
            'up' => (float) $prediction->probability_up,
            'down' => (float) $prediction->probability_down,
            'flat' => (float) $prediction->probability_flat,
        ];
        arsort($probabilities);
        $leading = array_key_first($probabilities);
        $signal = $leading !== 'flat' && ($probabilities[$leading] * 100) >= $minimumProbability ? $leading : null;

        return [
            'ticker' => $instrument->ticker,
            'as_of_date' => $prediction->as_of_date->format('Y-m-d'),
            'target_date' => $prediction->target_date->format('Y-m-d'),
            'horizon' => $horizon,
            'reference_close' => (float) $prediction->reference_close,
            'probabilities' => [
                'up' => (float) $prediction->probability_up,
                'down' => (float) $prediction->probability_down,
                'flat' => (float) $prediction->probability_flat,
            ],
            'signal' => $signal,
            'minimum_probability' => $minimumProbability,
            'model_version' => $prediction->model_version,
            'question_version' => $prediction->question_version,
            'experimental' => true,
        ];
    }
}
