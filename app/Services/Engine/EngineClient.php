<?php

namespace App\Services\Engine;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the internal Python engine.
 *
 * The engine fetches and parses source data; Laravel owns persistence. This
 * client only transports parsed bars.
 */
class EngineClient
{
    /**
     * Fetch the parsed EOD bars for a ticker from the engine.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException when the engine responds with an error status.
     * @throws ConnectionException when the engine is unreachable.
     */
    public function eodBars(string $ticker): array
    {
        $baseUrl = rtrim((string) config('engine.url'), '/');

        $payload = Http::acceptJson()
            ->get($baseUrl.'/eod/'.rawurlencode($ticker))
            ->throw()
            ->json();

        $bars = is_array($payload) ? ($payload['bars'] ?? []) : [];

        return is_array($bars) ? $bars : [];
    }

    /**
     * Compute indicator snapshots for a bar series in the engine.
     *
     * The engine computes only; Laravel persists the returned snapshots. One
     * snapshot is returned per submitted bar, with JSON `null`s where an
     * indicator does not have enough history.
     *
     * @param  array<int, array<string, mixed>>  $bars
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException when the engine responds with an error status.
     * @throws ConnectionException when the engine is unreachable.
     */
    public function computeIndicators(array $bars): array
    {
        $baseUrl = rtrim((string) config('engine.url'), '/');

        $payload = Http::acceptJson()
            ->post($baseUrl.'/indicators/compute', ['bars' => $bars])
            ->throw()
            ->json();

        $snapshots = is_array($payload) ? ($payload['snapshots'] ?? []) : [];

        return is_array($snapshots) ? $snapshots : [];
    }

    /**
     * Detect the deterministic signals that hold on the as-of bar of a series.
     *
     * The engine owns the signal rules; Laravel persists the returned signals.
     * The response carries the signals detected on the last submitted bar
     * (`{signals: [{date, type, metadata}]}`); an empty result is valid.
     *
     * @param  array<int, array<string, mixed>>  $bars
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException when the engine responds with an error status.
     * @throws ConnectionException when the engine is unreachable.
     */
    public function detectSignals(array $bars): array
    {
        $baseUrl = rtrim((string) config('engine.url'), '/');

        $payload = Http::acceptJson()
            ->post($baseUrl.'/signals/detect', ['bars' => $bars])
            ->throw()
            ->json();

        $signals = is_array($payload) ? ($payload['signals'] ?? []) : [];

        return is_array($signals) ? $signals : [];
    }

    /**
     * Detect the chartist patterns active on the as-of bar of a bar series.
     *
     * The response is `{patterns: [{type, status, as_of_date, start_date,
     * end_date, breakout_level, points, metadata}]}`; an empty result is valid.
     *
     * @param  array<int, array<string, mixed>>  $bars
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException when the engine responds with an error status.
     * @throws ConnectionException when the engine is unreachable.
     */
    public function detectPatterns(array $bars): array
    {
        $baseUrl = rtrim((string) config('engine.url'), '/');

        $payload = Http::acceptJson()
            ->post($baseUrl.'/patterns/detect', ['bars' => $bars])
            ->throw()
            ->json();

        $patterns = is_array($payload) ? ($payload['patterns'] ?? []) : [];

        return is_array($patterns) ? $patterns : [];
    }

    /**
     * Calibration aid: patterns the rules reject because of exactly one rule.
     *
     * The response is `{near_misses: [{rule, parameter, production_value,
     * relaxed_value, pattern}]}` (at most one per type the rules do not
     * detect). Used only by `patterns:review-sample`; never persisted.
     *
     * @param  array<int, array<string, mixed>>  $bars
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException when the engine responds with an error status.
     * @throws ConnectionException when the engine is unreachable.
     */
    public function patternNearMisses(array $bars): array
    {
        $baseUrl = rtrim((string) config('engine.url'), '/');

        $payload = Http::acceptJson()
            ->post($baseUrl.'/patterns/near-misses', ['bars' => $bars])
            ->throw()
            ->json();

        $nearMisses = is_array($payload) ? ($payload['near_misses'] ?? []) : [];

        return is_array($nearMisses) ? $nearMisses : [];
    }

    /**
     * The production pattern parameters (`{parameters: {name: number}}`).
     *
     * @return array<string, float>
     *
     * @throws RequestException when the engine responds with an error status.
     * @throws ConnectionException when the engine is unreachable.
     */
    public function patternParameters(): array
    {
        $baseUrl = rtrim((string) config('engine.url'), '/');

        $payload = Http::acceptJson()
            ->get($baseUrl.'/patterns/parameters')
            ->throw()
            ->json();

        $parameters = is_array($payload) ? ($payload['parameters'] ?? []) : [];

        return is_array($parameters)
            ? array_map('floatval', array_filter($parameters, 'is_numeric'))
            : [];
    }
}
