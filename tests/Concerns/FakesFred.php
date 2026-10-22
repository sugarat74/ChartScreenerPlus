<?php

namespace Tests\Concerns;

use App\Services\Fundamentals\Sleeper;
use Closure;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;

/**
 * Offline FRED for tests (macro-series-fred).
 *
 * Requests to `https://api.stlouisfed.org/fred/series` and
 * `/fred/series/observations` are answered from tests/fixtures/fred/ by
 * `series_id` (see the README there for the sources). Every test runs with
 * `Http::preventStrayRequests()`, so a request without a fixture or override
 * fails the test. The API key is the documented fake one.
 */
trait FakesFred
{
    protected const FRED_TEST_KEY = 'abcdefghijklmnopqrstuvwxyz012345';

    /** Codes enabled by default in config/macro.php, in config order. */
    protected const FRED_DEFAULT_CODES = [
        'DGS3MO', 'DGS2', 'DGS10', 'DFEDTARL', 'DFEDTARU', 'ECBDFR',
        'WALCL', 'WDTGAL', 'WLRRAOL', 'ICSA', 'PERMIT', 'USALOLITOAASTSAM',
    ];

    protected function enableFred(): void
    {
        config()->set('macro.enabled', true);
        config()->set('macro.fred_api_key', self::FRED_TEST_KEY);
    }

    /** @return array<mixed> */
    protected static function fredFixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/fixtures/fred/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Fake FRED from the fixtures. `$overrides` maps `"series:CODE"` or
     * `"observations:CODE"` to a response, a sequence or a closure receiving
     * the request; it wins over the fixtures. Each call starts a fresh fake.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function fakeFred(array $overrides = []): void
    {
        Http::swap(new Factory($this->app->make('events')));
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($overrides) {
            $endpoint = self::fredEndpoint($request);
            if ($endpoint === null) {
                return null;
            }
            $code = (string) ($request->data()['series_id'] ?? '');
            $override = $overrides["{$endpoint}:{$code}"] ?? null;
            if ($override instanceof ResponseSequence || $override instanceof Closure) {
                return $override($request);
            }
            if ($override !== null) {
                return $override;
            }
            $path = base_path("tests/fixtures/fred/{$endpoint}_{$code}.json");

            return is_file($path) ? Http::response(self::fredFixture("{$endpoint}_{$code}")) : null;
        });
    }

    /** `series` or `observations` for a FRED API request, else null. */
    protected static function fredEndpoint(Request $request): ?string
    {
        $parts = parse_url($request->url());
        if (($parts['host'] ?? null) !== 'api.stlouisfed.org') {
            return null;
        }

        return match ($parts['path'] ?? null) {
            '/fred/series' => 'series',
            '/fred/series/observations' => 'observations',
            default => null,
        };
    }

    /** A clock that only moves when slept on, recording every wait. */
    protected function fakeMacroSleeper(): Sleeper
    {
        $sleeper = new class extends Sleeper
        {
            public float $clock = 1000.0;

            /** @var list<float> */
            public array $sleeps = [];

            public function now(): float
            {
                return $this->clock;
            }

            public function sleep(float $seconds): void
            {
                $this->sleeps[] = round($seconds, 6);
                $this->clock += $seconds;
            }
        };
        $this->app->instance(Sleeper::class, $sleeper);

        return $sleeper;
    }
}
