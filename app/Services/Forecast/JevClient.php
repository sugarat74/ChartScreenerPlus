<?php

namespace App\Services\Forecast;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class JevClient
{
    /** @param array<string, mixed> $state
     *  @return array{up: float, down: float, flat: float}
     */
    public function predict(array $state, int $horizon, string $targetDate): array
    {
        $key = config('forecast.api_key');
        $model = config('forecast.model');
        if (! config('forecast.enabled') || ! config('forecast.rights_verified') || ! is_string($key) || $key === '' || ! is_string($model) || ! preg_match('/^jev-[0-9][a-zA-Z0-9.\-]*$/', $model)) {
            throw new ForecastUnavailable('provider_not_configured');
        }

        $state['horizon_sessions'] = $horizon;
        $state['target_date'] = $targetDate;
        try {
            $response = Http::withToken($key)->acceptJson()
                ->timeout((int) config('forecast.timeout_seconds'))
                ->post((string) config('forecast.endpoint'), [
                    'model' => $model,
                    'state' => $state,
                    'questions' => [
                        'direction' => [
                            'type' => 'choice',
                            'instructions' => 'Which closing-price outcome is most likely at the target session compared with reference_close? Estimate the probabilities from the supplied data only; do not assume future market data is known.',
                            'criteria' => [
                                'up' => 'Target close is strictly greater than reference_close.',
                                'down' => 'Target close is strictly less than reference_close.',
                                'flat' => 'Target close equals reference_close exactly.',
                            ],
                        ],
                    ],
                ]);
        } catch (Throwable $exception) {
            Log::warning('Jev forecast transport failed', ['type' => get_class($exception)]);
            throw new ForecastUnavailable('provider_unavailable');
        }

        if (! $response->successful()) {
            Log::warning('Jev forecast rejected', ['status' => $response->status()]);
            throw new ForecastUnavailable('provider_unavailable');
        }

        $payload = $response->json();
        if (! is_array($payload) || ($payload['model'] ?? null) !== $model || ($payload['answers']['direction']['type'] ?? null) !== 'choice') {
            throw new ForecastUnavailable('invalid_provider_response');
        }

        $values = $payload['answers']['direction']['probabilities'] ?? null;
        if (! is_array($values) || count($values) !== 3 || array_diff(['up', 'down', 'flat'], array_keys($values)) !== []) {
            throw new ForecastUnavailable('invalid_provider_response');
        }

        $probabilities = [];
        foreach (['up', 'down', 'flat'] as $direction) {
            $value = $values[$direction];
            if (! is_int($value) && ! is_float($value)) {
                throw new ForecastUnavailable('invalid_provider_response');
            }
            $probability = (float) $value;
            if (! is_finite($probability) || $probability < 0 || $probability > 1) {
                throw new ForecastUnavailable('invalid_provider_response');
            }
            $probabilities[$direction] = $probability;
        }

        if (abs(array_sum($probabilities) - 1.0) > 0.0001) {
            throw new ForecastUnavailable('invalid_provider_response');
        }

        $choice = $payload['answers']['direction']['choice'] ?? null;
        if (! is_string($choice) || ! array_key_exists($choice, $probabilities) || $probabilities[$choice] < max($probabilities)) {
            throw new ForecastUnavailable('invalid_provider_response');
        }

        return $probabilities;
    }
}
