<?php

namespace Tests\Concerns;

use App\Services\Fundamentals\Sleeper;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * Offline SEC EDGAR for tests (company-fundamentals-sec).
 *
 * Fixtures in tests/fixtures/sec/ are real SEC responses fetched once on
 * 2026-10-15 with User-Agent "Chartiko research contact@chartiko.com"
 * (company_tickers.json, submissions and companyfacts for AAPL 320193,
 * MSFT 789019, JPM 19617, XOM 34088 and GOOGL 1652044), trimmed to the
 * concepts CompanyFactsReader keeps, 10-K/10-Q facts filed since 2024 and the
 * 40 most recent filings; the ticker map is trimmed to 9 rows. One deliberate
 * edit: the live map already pointed XOM to its new holding company
 * (CIK 2115436, ExxonMobil Holdings Corp); the fixture keeps the operating
 * company's CIK 34088 so XOM exercises the companyfacts lag case (its
 * 2026-08-03 10-Q is listed in submissions but absent from companyfacts).
 * Every test runs with `Http::preventStrayRequests()`.
 */
trait FakesSecEdgar
{
    /** @var array<string, string> ticker => fixture suffix */
    protected const SEC_CIKS = [
        'AAPL' => '0000320193',
        'MSFT' => '0000789019',
        'JPM' => '0000019617',
        'XOM' => '0000034088',
        'GOOGL' => '0001652044',
    ];

    protected function enableSec(): void
    {
        config()->set('fundamentals.enabled', true);
        config()->set('fundamentals.user_agent', 'Chartiko tests ops@example.com');
        config()->set('fundamentals.requests_per_second', 5);
        config()->set('fundamentals.data_base_url', 'https://data.sec.gov');
        config()->set('fundamentals.tickers_url', 'https://www.sec.gov/files/company_tickers.json');
    }

    /** @return array<mixed> */
    protected static function secFixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/fixtures/sec/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Fake every SEC endpoint from the fixtures. `$overrides` maps a URL
     * pattern to a response (or closure) and wins over the fixtures. Each call
     * starts a fresh fake (earlier stubs and recorded requests are dropped).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function fakeSec(array $overrides = []): void
    {
        Http::swap(new Factory($this->app->make('events')));
        Http::preventStrayRequests();
        $routes = $overrides;
        $routes['www.sec.gov/files/company_tickers.json'] ??= Http::response(self::secFixture('company_tickers'));
        foreach (self::SEC_CIKS as $ticker => $cik) {
            $name = strtolower($ticker);
            $routes["data.sec.gov/submissions/CIK{$cik}.json"] ??= Http::response(self::secFixture("submissions_{$name}"));
            $routes["data.sec.gov/api/xbrl/companyfacts/CIK{$cik}.json"] ??= Http::response(self::secFixture("companyfacts_{$name}"));
        }
        Http::fake($routes);
    }

    /** A clock that only moves when slept on, recording every wait. */
    protected function fakeSleeper(): Sleeper
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
