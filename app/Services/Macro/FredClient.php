<?php

namespace App\Services\Macro;

use App\Services\Fundamentals\Sleeper;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only way Chartiko talks to FRED (config/macro.php,
 * docs/specs/macro-series-fred.md, Domain Rule 3).
 *
 * Requests are spaced by `request_interval_seconds` through the injectable
 * `Sleeper`, never follow redirects, and are retried at most `retries` times
 * with `backoff_seconds` on 429, 5xx or a connection error/timeout.
 *
 * Secrecy: FRED takes the key only as a query parameter, so every request URL
 * contains it. Guzzle copies that URL into connection-error messages, so a
 * transport exception is caught here and dropped: it is never rethrown,
 * logged or reported. Failures surface as `FredUnavailable`, whose message is
 * a code; logs carry only `{series, endpoint, status}`.
 */
class FredClient
{
    private ?float $lastRequestAt = null;

    public function __construct(private readonly Sleeper $sleeper) {}

    /** FRED API keys are 32 lower-case alphanumeric characters. */
    public static function validKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/^[a-z0-9]{32}$/', $key) === 1;
    }

    /** Enabled and holding a well-formed key. */
    public static function isConfigured(): bool
    {
        return (bool) config('macro.enabled') && self::validKey(config('macro.fred_api_key'));
    }

    /**
     * Series metadata (`seriess[0]`).
     *
     * @return array{title: string, units: string, frequency_short: ?string, last_updated: ?CarbonImmutable}
     *
     * @throws FredUnavailable
     */
    public function series(string $code): array
    {
        $data = $this->get('series', $code, []);
        $series = $data['seriess'][0] ?? null;
        if (! is_array($series) || ! is_string($series['title'] ?? null) || ! is_string($series['units'] ?? null)) {
            throw $this->invalid($code, 'series');
        }

        $updated = null;
        if (isset($series['last_updated'])) {
            if (! is_string($series['last_updated'])) {
                throw $this->invalid($code, 'series');
            }
            try {
                // e.g. "2013-07-31 09:26:16-05"
                $updated = CarbonImmutable::parse($series['last_updated'])->utc();
            } catch (Throwable) {
                throw $this->invalid($code, 'series');
            }
        }

        return [
            'title' => $series['title'],
            'units' => $series['units'],
            'frequency_short' => is_string($series['frequency_short'] ?? null) ? $series['frequency_short'] : null,
            'last_updated' => $updated,
        ];
    }

    /**
     * Observations from `$start` (ascending). `value` is the published number
     * as a string, or null for FRED's missing value `"."`.
     *
     * @return list<array{date: string, value: ?string}>
     *
     * @throws FredUnavailable
     */
    public function observations(string $code, CarbonInterface $start): array
    {
        $data = $this->get('observations', $code, [
            'observation_start' => $start->format('Y-m-d'),
            'sort_order' => 'asc',
        ]);
        if (! isset($data['observations']) || ! is_array($data['observations'])) {
            throw $this->invalid($code, 'observations');
        }

        $observations = [];
        foreach ($data['observations'] as $row) {
            $date = is_array($row) ? ($row['date'] ?? null) : null;
            $value = is_array($row) ? ($row['value'] ?? null) : null;
            if (! is_string($date) || ! self::validDate($date) || ! is_string($value)) {
                throw $this->invalid($code, 'observations');
            }
            $value = trim($value);
            if ($value === '.') {
                $observations[] = ['date' => $date, 'value' => null];

                continue;
            }
            if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
                throw $this->invalid($code, 'observations');
            }
            $observations[] = ['date' => $date, 'value' => $value];
        }

        return $observations;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<mixed>
     */
    private function get(string $endpoint, string $code, array $query): array
    {
        $key = (string) config('macro.fred_api_key');
        if (! self::validKey($key)) {
            throw new FredUnavailable('invalid_key');
        }

        $path = $endpoint === 'series' ? '/series' : '/series/observations';
        $url = rtrim((string) config('macro.base_url'), '/').$path;
        $query = ['series_id' => $code, 'api_key' => $key, 'file_type' => 'json'] + $query;
        $retries = max(0, (int) config('macro.retries'));
        $backoff = array_values((array) config('macro.backoff_seconds'));
        $context = ['series' => $code, 'endpoint' => $endpoint];

        for ($attempt = 0; ; $attempt++) {
            $this->space();
            $response = null;
            try {
                $response = $this->request($url, $query);
            } catch (ConnectionException|TransferException) {
                // Timeout or connection error. The exception message holds the
                // request URL, and with it the key: it is dropped, never kept.
            } finally {
                // Space from the end of this request too, so latency jitter
                // never lets two requests arrive closer than the interval.
                $this->lastRequestAt = $this->sleeper->now();
            }

            $status = $response?->status();
            $retryable = $response === null || $status === 429 || $status >= 500;
            if ($retryable && $attempt < $retries) {
                Log::info('FRED request retry', $context + ['status' => $status]);
                $this->sleeper->sleep((float) ($backoff[$attempt] ?? end($backoff) ?: 0));

                continue;
            }

            if ($response !== null && $response->successful()) {
                $data = $response->json();
                if (! is_array($data)) {
                    throw $this->invalid($code, $endpoint, $status);
                }

                return $data;
            }

            Log::warning('FRED request failed', $context + ['status' => $status]);

            throw new FredUnavailable(match (true) {
                $response === null => 'unavailable',
                $status === 400 => self::mentionsApiKey($response) ? 'invalid_key' : 'bad_request',
                $status === 404 => 'not_found',
                $status === 429 => 'rate_limited',
                $status >= 500 => 'unavailable',
                default => 'http_error',
            }, $status);
        }
    }

    /** @param  array<string, string>  $query */
    private function request(string $url, array $query): Response
    {
        $timeout = max(1, (int) config('macro.timeout_seconds'));

        return Http::acceptJson()
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(min(10, $timeout))
            ->timeout($timeout)
            ->get($url, $query);
    }

    private static function mentionsApiKey(Response $response): bool
    {
        try {
            $message = $response->json('error_message');
        } catch (Throwable) {
            return false;
        }

        return is_string($message) && str_contains(strtolower($message), 'api_key');
    }

    private function invalid(string $code, string $endpoint, ?int $status = 200): FredUnavailable
    {
        Log::warning('FRED response is invalid', ['series' => $code, 'endpoint' => $endpoint, 'status' => $status]);

        return new FredUnavailable('invalid_response', $status);
    }

    private static function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /** Wait so a request starts at least `request_interval_seconds` after the previous one started and ended. */
    private function space(): void
    {
        $interval = max(0.0, (float) config('macro.request_interval_seconds'));
        if ($this->lastRequestAt !== null) {
            $wait = $interval - ($this->sleeper->now() - $this->lastRequestAt);
            if ($wait > 0) {
                $this->sleeper->sleep($wait);
            }
        }
        $this->lastRequestAt = $this->sleeper->now();
    }
}
