<?php

namespace Tests\Feature;

use App\Models\MacroObservation;
use App\Models\MacroSeries;
use App\Services\Macro\MacroSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesFred;
use Tests\TestCase;

/**
 * `macro:refresh` against offline FRED fixtures (docs/specs/macro-series-fred.md,
 * Scenarios 1-5).
 */
class MacroRefreshCommandTest extends TestCase
{
    use FakesFred;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'America/New_York'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array{endpoint: string, code: string, start: ?string, key: ?string}> */
    private function fredRequests(): array
    {
        return Http::recorded()->map(fn (array $pair): array => [
            'endpoint' => (string) self::fredEndpoint($pair[0]),
            'code' => (string) ($pair[0]->data()['series_id'] ?? ''),
            'start' => $pair[0]->data()['observation_start'] ?? null,
            'key' => $pair[0]->data()['api_key'] ?? null,
        ])->values()->all();
    }

    /** @return array<string, string> date => value (6-dp string) */
    private function stored(string $code): array
    {
        $series = MacroSeries::query()->where('code', $code)->first();
        if ($series === null) {
            return [];
        }

        return $series->observations()->orderBy('date')->get()
            ->mapWithKeys(fn (MacroObservation $observation): array => [$observation->date->toDateString() => $observation->value])
            ->all();
    }

    public function test_disabled_is_inert(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        Log::spy();

        $this->artisan('macro:refresh')
            ->expectsOutputToContain('Macro series are disabled (MACRO_ENABLED=false); nothing was requested.')
            ->assertExitCode(0);

        Http::assertNothingSent();
        Log::shouldHaveReceived('info')->with('Macro refresh skipped: disabled.')->once();
        $this->assertDatabaseCount('macro_series', 0);
    }

    public function test_enabled_without_a_well_formed_key_exits_1_without_requests(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        config()->set('macro.enabled', true);

        foreach (['', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345', 'abc123', self::FRED_TEST_KEY.'x'] as $key) {
            config()->set('macro.fred_api_key', $key);
            $this->artisan('macro:refresh')
                ->expectsOutputToContain('FRED_API_KEY is missing or malformed')
                ->assertExitCode(1);
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('macro_series', 0);
    }

    public function test_an_unknown_or_disabled_series_option_exits_1_without_requests(): void
    {
        $this->enableFred();
        $this->fakeFred();

        $this->artisan('macro:refresh', ['--series' => ['DGS10', 'NFCI', 'T10Y2Y']])
            ->expectsOutputToContain('Unknown or disabled series: NFCI, T10Y2Y.')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_fixtures_are_stored_from_a_five_year_backfill(): void
    {
        $this->enableFred();
        $this->fakeFred();
        $sleeper = $this->fakeMacroSleeper();

        $this->artisan('macro:refresh')
            ->expectsOutputToContain('DGS10: 25 stored (25 new, 0 revised, 0 removed), latest 2026-10-20')
            ->expectsOutputToContain('WALCL: 16 stored (16 new, 0 revised, 0 removed), latest 2026-10-14')
            ->expectsOutputToContain('Macro refresh: 12 updated, 0 failed, 0 skipped.')
            ->assertExitCode(0);

        // Two requests per enabled series, in config order, each with the key
        // and an observation window starting today − 5 years.
        $requests = $this->fredRequests();
        $this->assertCount(24, $requests);
        $expected = [];
        foreach (self::FRED_DEFAULT_CODES as $code) {
            $expected[] = ['endpoint' => 'series', 'code' => $code, 'start' => null, 'key' => self::FRED_TEST_KEY];
            $expected[] = ['endpoint' => 'observations', 'code' => $code, 'start' => '2021-10-22', 'key' => self::FRED_TEST_KEY];
        }
        $this->assertSame($expected, $requests);
        Http::assertSent(fn (Request $request): bool => ($request->data()['file_type'] ?? null) === 'json');
        // Restricted and replaced series are never requested.
        foreach (['NFCI', 'VIXCLS', 'T10Y2Y', 'T10Y3M', 'WTREGEN', 'RRPONTSYD'] as $code) {
            $this->assertNotContains($code, array_column($requests, 'code'));
        }

        // Requests are spaced 0.6 s apart (23 waits for 24 requests), no backoff.
        $this->assertCount(23, $sleeper->sleeps);
        $this->assertSame([0.6], array_values(array_unique($sleeper->sleeps)));

        // Exact values as published; the Columbus Day "." has no row.
        $dgs10 = $this->stored('DGS10');
        $this->assertCount(25, $dgs10);
        $this->assertSame('5.000000', $dgs10['2026-09-15']);
        $this->assertSame('5.120000', $dgs10['2026-10-20']);
        $this->assertArrayNotHasKey('2026-10-12', $dgs10);
        $this->assertSame('6755639.000000', $this->stored('WALCL')['2026-10-14']);
        $this->assertSame('194000.000000', $this->stored('ICSA')['2026-10-17']);
        $this->assertSame('1400.000000', $this->stored('PERMIT')['2026-09-01']);
        $this->assertSame('2.500000', $this->stored('ECBDFR')['2026-10-22']);

        $series = MacroSeries::query()->where('code', 'DGS10')->sole();
        $this->assertSame('fred', $series->source);
        $this->assertSame('rates', $series->category);
        $this->assertSame('daily', $series->frequency);
        $this->assertSame('Market Yield on U.S. Treasury Securities at 10-Year Constant Maturity, Quoted on an Investment Basis', $series->title);
        $this->assertSame('Percent', $series->units);
        $this->assertSame('2026-10-20', $series->last_observation_date->toDateString());
        $this->assertSame('2026-10-21 20:18:04', $series->source_updated_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($series->last_refreshed_at->equalTo(now()));
        $this->assertTrue($series->last_attempted_at->equalTo(now()));
        $this->assertNull($series->last_error);
        $this->assertSame('Millions of U.S. Dollars', MacroSeries::query()->where('code', 'WLRRAOL')->value('units'));
        $this->assertSame(12, MacroSeries::query()->whereNull('last_error')->whereNotNull('last_refreshed_at')->count());
    }

    public function test_reruns_refetch_a_revision_window_update_revisions_and_delete_missing_values(): void
    {
        $this->enableFred();
        $this->fakeFred();
        $this->fakeMacroSleeper();
        $this->artisan('macro:refresh')->assertExitCode(0);

        // FRED revises 2026-10-19 and now returns "." for 2026-10-20.
        $revised = self::fredFixture('observations_DGS10');
        foreach ($revised['observations'] as &$row) {
            $row['value'] = match ($row['date']) {
                '2026-10-19' => '5.16',
                '2026-10-20' => '.',
                default => $row['value'],
            };
        }
        unset($row);
        $this->fakeFred(['observations:DGS10' => Http::response($revised)]);
        Carbon::setTestNow(now()->addDay());

        $this->artisan('macro:refresh')
            ->expectsOutputToContain('DGS10: 24 stored (0 new, 1 revised, 1 removed), latest 2026-10-19')
            ->expectsOutputToContain('WALCL: 16 stored (0 new, 0 revised, 0 removed), latest 2026-10-14')
            ->assertExitCode(0);

        // observation_start = last stored date − refetch_days[frequency].
        $starts = collect($this->fredRequests())->where('endpoint', 'observations')->pluck('start', 'code')->all();
        $this->assertSame('2026-10-06', $starts['DGS10']);   // 2026-10-20 − 14 d
        $this->assertSame('2026-08-19', $starts['WALCL']);   // 2026-10-14 − 56 d
        $this->assertSame('2026-08-22', $starts['ICSA']);    // 2026-10-17 − 56 d
        $this->assertSame('2026-02-27', $starts['PERMIT']);  // 2026-09-01 − 186 d

        $dgs10 = $this->stored('DGS10');
        $this->assertSame('5.160000', $dgs10['2026-10-19']);
        $this->assertArrayNotHasKey('2026-10-20', $dgs10);
        $this->assertSame('2026-10-19', MacroSeries::query()->where('code', 'DGS10')->sole()->last_observation_date->toDateString());

        // A second identical run writes no observation.
        $before = DB::table('macro_observations')->orderBy('id')->get()->toArray();
        Carbon::setTestNow(now()->addDay());
        $this->artisan('macro:refresh')
            ->expectsOutputToContain('DGS10: 24 stored (0 new, 0 revised, 0 removed), latest 2026-10-19')
            ->assertExitCode(0);
        $this->assertEquals($before, DB::table('macro_observations')->orderBy('id')->get()->toArray());
    }

    public function test_series_option_and_backfill_limit_the_run(): void
    {
        $this->enableFred();
        $this->fakeFred();
        $this->fakeMacroSleeper();
        $this->artisan('macro:refresh', ['--series' => ['dgs10']])->assertExitCode(0);
        $this->assertSame(['DGS10'], array_values(array_unique(array_column($this->fredRequests(), 'code'))));

        // With stored data, --backfill still re-reads the whole 5-year window.
        $this->fakeFred();
        $this->artisan('macro:refresh', ['--series' => ['DGS10'], '--backfill' => true])
            ->expectsOutputToContain('DGS10: 25 stored (0 new, 0 revised, 0 removed)')
            ->assertExitCode(0);
        $this->assertSame('2021-10-22', $this->fredRequests()[1]['start']);
        $this->assertDatabaseCount('macro_series', 1);
    }

    public function test_a_restricted_series_is_requested_only_when_its_flag_enables_it(): void
    {
        $this->enableFred();
        config()->set('macro.series.VIXCLS.enabled', true);
        $this->fakeFred();
        $this->fakeMacroSleeper();

        $this->artisan('macro:refresh', ['--series' => ['VIXCLS']])
            ->expectsOutputToContain('VIXCLS: 27 stored')
            ->assertExitCode(0);

        $this->assertSame('volatility', MacroSeries::query()->where('code', 'VIXCLS')->value('category'));
    }

    public function test_one_failing_series_is_recorded_and_the_others_are_stored(): void
    {
        $this->enableFred();
        $series = MacroSeries::factory()->create(['code' => 'DGS2', 'category' => 'rates', 'frequency' => 'daily']);
        MacroObservation::factory()->create(['macro_series_id' => $series->id, 'date' => '2026-01-02', 'value' => 4.25]);
        $this->fakeFred(['series:DGS2' => Http::response(['error_code' => 500, 'error_message' => 'Internal Server Error'], 500)]);
        $sleeper = $this->fakeMacroSleeper();

        $this->artisan('macro:refresh')
            ->expectsOutputToContain('DGS2: failed (unavailable)')
            ->expectsOutputToContain('Macro refresh: 11 updated, 1 failed, 0 skipped.')
            ->assertExitCode(0);

        // Three attempts (two retries) with the configured backoff, no real sleep.
        $dgs2 = collect($this->fredRequests())->where('code', 'DGS2')->values();
        $this->assertCount(3, $dgs2);
        $this->assertSame([5.0, 20.0], array_values(array_filter($sleeper->sleeps, fn (float $wait): bool => $wait >= 5)));

        $series->refresh();
        $this->assertSame('unavailable', $series->last_error);
        $this->assertTrue($series->last_attempted_at->equalTo(now()));
        $this->assertNull($series->last_refreshed_at);
        $this->assertSame(['2026-01-02' => '4.250000'], $this->stored('DGS2'));
        $this->assertCount(25, $this->stored('DGS10'));
    }

    public function test_every_series_failing_exits_1(): void
    {
        $this->enableFred();
        $overrides = [];
        foreach (self::FRED_DEFAULT_CODES as $code) {
            $overrides["series:{$code}"] = Http::response([], 503);
        }
        $this->fakeFred($overrides);
        $this->fakeMacroSleeper();

        $this->artisan('macro:refresh')
            ->expectsOutputToContain('Macro refresh: 0 updated, 12 failed, 0 skipped.')
            ->assertExitCode(1);

        $this->assertSame(12, MacroSeries::query()->where('last_error', 'unavailable')->count());
        $this->assertDatabaseCount('macro_observations', 0);
    }

    public function test_an_invalid_key_stops_the_run(): void
    {
        $this->enableFred();
        $this->fakeFred(['series:DGS3MO' => Http::response(self::fredFixture('error_400_api_key'), 400)]);
        $this->fakeMacroSleeper();

        $this->artisan('macro:refresh')
            ->expectsOutputToContain('DGS3MO: failed (invalid_key)')
            ->expectsOutputToContain('FRED rejected FRED_API_KEY (invalid_key); run stopped.')
            ->assertExitCode(1);

        // Not retried, and no further series was requested.
        Http::assertSentCount(1);
        $this->assertSame('invalid_key', MacroSeries::query()->where('code', 'DGS3MO')->value('last_error'));
        $this->assertDatabaseCount('macro_series', 1);
    }

    public function test_each_failure_is_recorded_with_its_code(): void
    {
        $this->enableFred();
        $this->fakeFred([
            'series:DGS3MO' => Http::response(self::fredFixture('error_400_bad_request'), 400),
            'observations:DGS2' => Http::response(['error_code' => 404, 'error_message' => 'Not Found'], 404),
            'observations:DGS10' => Http::response(self::fredFixture('observations_bad_value')),
            'series:DFEDTARL' => Http::response('', 302, ['Location' => 'https://example.com/']),
            'series:DFEDTARU' => Http::response([], 429),
            'series:ECBDFR' => Http::response('<html>maintenance</html>', 200, ['Content-Type' => 'text/html']),
            'series:WALCL' => Http::response(self::fredFixture('series_units_changed')),
            'series:WDTGAL' => Http::response(['seriess' => []]),
        ]);
        $this->fakeMacroSleeper();

        $this->artisan('macro:refresh')
            ->expectsOutputToContain('Macro refresh: 4 updated, 8 failed, 0 skipped.')
            ->assertExitCode(0);

        $this->assertSame([
            'DFEDTARL' => 'http_error',
            'DFEDTARU' => 'rate_limited',
            'DGS10' => 'invalid_response',
            'DGS2' => 'not_found',
            'DGS3MO' => 'bad_request',
            'ECBDFR' => 'invalid_response',
            'WALCL' => 'units_changed',
            'WDTGAL' => 'invalid_response',
        ], MacroSeries::query()->whereNotNull('last_error')->orderBy('code')->pluck('last_error', 'code')->all());
        // Nothing is written for a failing series, not even the valid rows of
        // a response with one bad value.
        $this->assertSame([], $this->stored('DGS10'));
        $this->assertSame([], $this->stored('WALCL'));
        // The units guard rejects before observations are requested; the
        // redirect is never followed.
        $this->assertSame([], collect($this->fredRequests())->where('code', 'WALCL')->where('endpoint', 'observations')->all());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'example.com'));
    }

    public function test_the_key_never_leaks_into_logs_output_tables_or_reported_exceptions(): void
    {
        $this->enableFred();
        Exceptions::fake();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        // Guzzle's connection error message carries the full URL with the key.
        $this->fakeFred([
            'series:DGS3MO' => Http::failedConnection(),
            'series:DGS10' => Http::response(self::fredFixture('error_400_api_key'), 400),
        ]);
        $this->fakeMacroSleeper();

        $exit = Artisan::call('macro:refresh');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('DGS3MO: failed (unavailable)', $output);
        $this->assertStringContainsString('DGS10: failed (invalid_key)', $output);
        $this->assertStringNotContainsString(self::FRED_TEST_KEY, $output);
        $this->assertNotEmpty($logged);
        foreach ($logged as $record) {
            $this->assertStringNotContainsString(self::FRED_TEST_KEY, $record);
            $this->assertStringNotContainsString('api.stlouisfed.org', $record);
        }
        $this->assertStringNotContainsString(self::FRED_TEST_KEY, json_encode(DB::table('macro_series')->get()));
        Exceptions::assertNothingReported();
        $this->assertSame(['DGS10' => 'invalid_key', 'DGS2' => null, 'DGS3MO' => 'unavailable'], MacroSeries::query()->orderBy('code')->pluck('last_error', 'code')->all());
    }

    public function test_a_held_lock_skips_the_run(): void
    {
        $this->enableFred();
        $this->fakeFred();
        $held = Cache::lock('macro:refresh', 60);
        $this->assertTrue($held->get());

        $this->artisan('macro:refresh')
            ->expectsOutputToContain('Macro refresh is already running; skipping.')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $held->release();
    }

    public function test_the_time_budget_skips_the_remaining_series(): void
    {
        $this->enableFred();
        config()->set('macro.run_budget_seconds', 1);
        $this->fakeFred();
        $this->fakeMacroSleeper();
        Log::spy();

        // Each request after the first waits 0.6 s on the fake clock: after
        // two series (1.8 s) the one-second budget is spent.
        $this->artisan('macro:refresh')
            ->expectsOutputToContain('Time budget spent; skipped until the next run: DGS10, DFEDTARL, DFEDTARU, ECBDFR, WALCL, WDTGAL, WLRRAOL, ICSA, PERMIT, USALOLITOAASTSAM.')
            ->expectsOutputToContain('Macro refresh: 2 updated, 0 failed, 10 skipped.')
            ->assertExitCode(0);

        Http::assertSentCount(4);
        Log::shouldHaveReceived('warning')->with('Macro refresh time budget spent.', ['skipped' => ['DGS10', 'DFEDTARL', 'DFEDTARU', 'ECBDFR', 'WALCL', 'WDTGAL', 'WLRRAOL', 'ICSA', 'PERMIT', 'USALOLITOAASTSAM']])->once();
    }

    public function test_refreshed_fixtures_give_the_hand_computed_readings(): void
    {
        $this->enableFred();
        $this->fakeFred();
        $this->fakeMacroSleeper();
        $this->artisan('macro:refresh')->assertExitCode(0);

        $snapshot = app(MacroSnapshotService::class)->snapshot('2026-10-22');

        // DGS10 2026-10-20 5.12; 20 observations earlier (2026-09-21) 4.96.
        $this->assertSame(['value' => 5.12, 'as_of' => '2026-10-20', 'stale' => false], $snapshot['rates']['dgs10']);
        $this->assertSame(0.16, $snapshot['rates']['dgs10_change_20']['value']);
        // 5.12 − 4.79 and 5.12 − 4.26 on 2026-10-20.
        $this->assertSame(0.33, $snapshot['curve']['spread_10y2y']['value']);
        $this->assertSame(0.86, $snapshot['curve']['spread_10y3m']['value']);
        $this->assertFalse($snapshot['curve']['inverted']);
        // (6,755,639 − 885,823 − 201) / 1000 on 2026-10-14; bases 2026-09-16
        // (5,749.465) and 2026-07-15 (5,946.901).
        $this->assertSame(['value' => 5869.615, 'as_of' => '2026-10-14', 'stale' => false], $snapshot['liquidity']['net_liquidity']);
        $this->assertSame(2.0898, $snapshot['liquidity']['change_4w_pct']['value']);
        $this->assertSame(-1.2996, $snapshot['liquidity']['change_13w_pct']['value']);
        // (198,000 + 200,000 + 194,000 + ...) latest four claims weeks.
        $this->assertSame(197750.0, $snapshot['leading']['icsa_4w']['value']);
        // Only 16 weeks stored: no 52-week comparison.
        $this->assertNull($snapshot['leading']['icsa_4w_vs_52w_min_pct']['value']);
        // PERMIT 1,400 (2026-09) vs 1,374 (2026-06).
        $this->assertSame(1.8923, $snapshot['leading']['permit_change_3m_pct']['value']);
        // The Fed raised the range from 3.50–3.75 to 3.75–4.00, effective 2026-09-17.
        $this->assertSame([
            'direction' => 'raise',
            'from' => ['lower' => 3.5, 'upper' => 3.75],
            'to' => ['lower' => 3.75, 'upper' => 4.0],
            'effective_date' => '2026-09-17',
        ], $snapshot['central_banks']['fed']['last_change']);
        $this->assertSame(4.0, $snapshot['central_banks']['fed']['upper']['value']);
        $this->assertNull($snapshot['central_banks']['ecb']['last_change']);
    }
}
