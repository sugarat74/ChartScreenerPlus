<?php

namespace App\Services\Engine;

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
     * @throws \Illuminate\Http\Client\RequestException when the engine responds with an error status.
     * @throws \Illuminate\Http\Client\ConnectionException when the engine is unreachable.
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
}
