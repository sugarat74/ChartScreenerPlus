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
}
