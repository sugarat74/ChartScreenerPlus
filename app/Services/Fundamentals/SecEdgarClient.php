<?php

namespace App\Services\Fundamentals;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only way Chartiko talks to SEC EDGAR (config/fundamentals.php).
 *
 * Every request carries the configured descriptive `User-Agent` and
 * `Accept-Encoding: gzip, deflate`, is spaced to at most
 * `requests_per_second` (clamped 1..9) through the injectable `Sleeper`, and
 * is retried at most `retries` times with backoff on 429, 5xx or a connection
 * error/timeout. Redirects are never followed. Logs carry only the endpoint
 * kind and the HTTP status, never a payload.
 */
class SecEdgarClient
{
    private ?float $lastRequestAt = null;

    public function __construct(private readonly Sleeper $sleeper) {}

    /** Enabled, with a valid User-Agent and valid (https or local) URLs. */
    public function isConfigured(): bool
    {
        return (bool) config('fundamentals.enabled')
            && self::validUserAgent(config('fundamentals.user_agent'))
            && $this->baseUrl() !== null
            && self::validUrl(config('fundamentals.tickers_url')) !== null;
    }

    /**
     * A descriptive contact: printable text with a name and an email address,
     * e.g. "Chartiko ops@chartiko.com".
     */
    public static function validUserAgent(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 200 || preg_match('/[\p{Cc}]/u', $value) === 1) {
            return false;
        }
        if (preg_match('/[^\s<>@()]+@[^\s<>@()]+\.[A-Za-z]{2,}/', $value, $email) !== 1) {
            return false;
        }
        $name = trim(str_replace($email[0], '', $value), " \t<>()");

        return preg_match('/\p{L}{2,}/u', $name) === 1;
    }

    /**
     * Ticker -> zero-padded 10-digit CIK, tickers upper-cased as the SEC
     * writes them (`BRK-B`).
     *
     * @return array<string, string>
     */
    public function tickers(): array
    {
        $url = self::validUrl(config('fundamentals.tickers_url'));
        if ($url === null) {
            throw new SecUnavailable('not_configured');
        }
        $data = $this->get($url, 'tickers');
        $map = [];
        foreach ($data as $row) {
            if (! is_array($row) || ! isset($row['ticker'], $row['cik_str']) || ! is_numeric($row['cik_str'])) {
                continue;
            }
            $map[strtoupper(trim((string) $row['ticker']))] = self::padCik($row['cik_str']);
        }

        return $map;
    }

    /** @return array<mixed> */
    public function submissions(string $cik): array
    {
        return $this->get($this->dataUrl('/submissions/CIK'.self::padCik($cik).'.json'), 'submissions');
    }

    /** @return array<mixed> */
    public function companyFacts(string $cik): array
    {
        return $this->get($this->dataUrl('/api/xbrl/companyfacts/CIK'.self::padCik($cik).'.json'), 'companyfacts');
    }

    public static function padCik(int|string $cik): string
    {
        return str_pad(ltrim((string) $cik, '0') ?: '0', 10, '0', STR_PAD_LEFT);
    }

    /** @return array<mixed> */
    private function get(string $url, string $kind): array
    {
        $retries = max(0, (int) config('fundamentals.retries'));
        $backoff = array_values((array) config('fundamentals.backoff_seconds'));

        for ($attempt = 0; ; $attempt++) {
            $this->space();
            $response = null;
            try {
                $response = $this->request($url);
            } catch (ConnectionException) {
                // Timeout or connection error: retried below.
            } finally {
                // Space from the end of this request too, so latency jitter
                // never lets two requests arrive closer than the interval.
                $this->lastRequestAt = $this->sleeper->now();
            }

            $status = $response?->status();
            $retryable = $response === null || $status === 429 || $status >= 500;
            if ($retryable && $attempt < $retries) {
                Log::info('SEC request retry', ['endpoint' => $kind, 'status' => $status]);
                $this->sleeper->sleep((float) ($backoff[$attempt] ?? end($backoff) ?: 0));

                continue;
            }

            if ($response === null) {
                Log::warning('SEC request failed', ['endpoint' => $kind, 'status' => null]);
                throw new SecUnavailable('unavailable');
            }
            if ($response->successful()) {
                $data = $response->json();
                if (! is_array($data)) {
                    Log::warning('SEC response is not JSON', ['endpoint' => $kind, 'status' => $status]);
                    throw new SecUnavailable('invalid_response', $status);
                }

                return $data;
            }

            Log::warning('SEC request failed', ['endpoint' => $kind, 'status' => $status]);
            throw new SecUnavailable(match (true) {
                $status === 403 => 'forbidden',
                $status === 404 => 'not_found',
                $retryable => 'unavailable',
                default => 'http_error',
            }, $status);
        }
    }

    private function request(string $url): Response
    {
        $timeout = max(1, (int) config('fundamentals.timeout_seconds'));

        return Http::withHeaders([
            'User-Agent' => trim((string) config('fundamentals.user_agent')),
            'Accept-Encoding' => 'gzip, deflate',
            'Accept' => 'application/json',
        ])
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(min(10, $timeout))
            ->timeout($timeout)
            ->get($url);
    }

    /** Wait so a request starts at least 1/rps seconds after the previous one started and ended. */
    private function space(): void
    {
        $rps = min(9, max(1, (int) config('fundamentals.requests_per_second')));
        $interval = 1 / $rps;
        if ($this->lastRequestAt !== null) {
            $wait = $interval - ($this->sleeper->now() - $this->lastRequestAt);
            if ($wait > 0) {
                $this->sleeper->sleep($wait);
            }
        }
        $this->lastRequestAt = $this->sleeper->now();
    }

    private function dataUrl(string $path): string
    {
        $base = $this->baseUrl();
        if ($base === null) {
            throw new SecUnavailable('not_configured');
        }

        return $base.$path;
    }

    private function baseUrl(): ?string
    {
        $url = self::validUrl(config('fundamentals.data_base_url'));

        return $url === null ? null : rtrim($url, '/');
    }

    /** https://, or http:// only on localhost / 127.0.0.1 (local QA fakes). */
    private static function validUrl(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        $url = trim($url);
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $local = in_array(strtolower($parts['host']), ['localhost', '127.0.0.1'], true);

        return $scheme === 'https' || ($scheme === 'http' && $local) ? $url : null;
    }
}
