<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Macro\FredClient;
use App\Services\Macro\FredUnavailable;
use App\Services\MarketContext\MarketContextRunLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesFred;
use Tests\TestCase;

class MarketEventsTest extends TestCase
{
    use FakesFred, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 15:00:00');
        Http::preventStrayRequests();
        $this->enableFred();
        $this->fakeMacroSleeper();
        config(['macro.retries' => 0, 'market_context.public' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeDates(?string $next = null, bool $failCpi = false): void
    {
        Http::swap(new Factory($this->app->make('events')));
        Http::preventStrayRequests();
        Http::fake(['api.stlouisfed.org/fred/release/dates*' => function (Request $request) use ($next, $failCpi) {
            $id = (int) $request['release_id'];
            if ($failCpi && $id === 10) {
                return Http::response(['release_dates' => []], 200);
            }
            $fixture = self::fredFixture('release_dates_cpi');
            foreach ($fixture['release_dates'] as &$row) {
                $row['release_id'] = $id;
            }
            unset($row);
            if ($next !== null) {
                $fixture['release_dates'][1]['date'] = $next;
            }

            return Http::response($fixture);
        }]);
    }

    public function test_refresh_is_idempotent_and_removes_withdrawn_dates_without_storing_unrelated_values(): void
    {
        $this->fakeDates();
        $this->artisan('events:refresh')->assertExitCode(0);
        $this->artisan('events:refresh')->assertExitCode(0);
        $this->assertDatabaseCount('market_events', 10);
        $this->assertDatabaseCount('market_event_sources', 5);
        $this->fakeDates('2026-11-13');
        $this->artisan('events:refresh')->assertExitCode(0);
        $this->assertDatabaseCount('market_events', 10);
        $this->assertDatabaseMissing('market_events', ['code' => 'cpi', 'date' => '2026-11-12']);
        $this->assertDatabaseHas('market_events', ['code' => 'cpi', 'date' => '2026-11-13']);
        Http::assertSent(fn (Request $r): bool => $r['include_release_dates_with_no_data'] === 'true' && $r['realtime_start'] === '2026-10-11' && $r['realtime_end'] === '2027-02-08');
        $this->assertSame('succeeded', MarketContextRunLog::get('events:refresh')['status']);
    }

    public function test_a_failed_source_preserves_its_dates_marks_them_stale_and_does_not_stop_the_others(): void
    {
        $this->fakeDates();
        $this->artisan('events:refresh')->assertExitCode(0);
        $this->fakeDates('2026-11-13', failCpi: true);
        $this->artisan('events:refresh')->assertExitCode(1);
        $this->assertDatabaseHas('market_events', ['code' => 'cpi', 'date' => '2026-11-12']);
        $this->assertDatabaseHas('market_events', ['code' => 'gdp', 'date' => '2026-11-13']);
        $this->assertDatabaseHas('market_event_sources', ['code' => 'cpi', 'last_error' => 'invalid_response']);
        $body = $this->actingAs(User::factory()->create())->getJson('/api/market-context/events')->assertOk()->json();
        $this->assertTrue($body['sources']['cpi']['stale']);
        $this->assertFalse($body['sources']['gdp']['stale']);
        $admin = $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/market-context')->assertOk();
        $this->assertSame('invalid_response', collect($admin->json('event_sources'))->firstWhere('code', 'cpi')['last_error']);
        $admin->assertJsonPath('runs.events:refresh.status', 'failed');
    }

    public function test_parser_rejects_partial_empty_changed_or_invalid_responses_before_storage(): void
    {
        $valid = self::fredFixture('release_dates_cpi');
        $invalid = [[], ['count' => 0, 'offset' => 0, 'release_dates' => []],
            array_replace($valid, ['count' => 10]), array_replace($valid, ['offset' => 1]),
            array_replace($valid, ['release_dates' => [['release_id' => 11, 'date' => '2026-11-12']], 'count' => 1]),
            array_replace($valid, ['release_dates' => [['release_id' => 10, 'date' => '2026-02-30']], 'count' => 1]),
            array_replace($valid, ['release_dates' => [null], 'count' => 1]),
        ];
        foreach ($invalid as $payload) {
            Http::swap(new Factory($this->app->make('events')));
            Http::preventStrayRequests();
            Http::fake(['api.stlouisfed.org/*' => Http::response($payload)]);
            try {
                app(FredClient::class)->releaseDates(10, '2026-10-11', '2027-02-08');
                $this->fail('Invalid release response accepted.');
            } catch (FredUnavailable $exception) {
                $this->assertSame('invalid_response', $exception->reason);
                $this->assertStringNotContainsString(self::FRED_TEST_KEY, $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('market_events', 0);
    }

    public function test_transport_errors_are_sanitized_and_unknown_release_ids_never_request(): void
    {
        try {
            app(FredClient::class)->releaseDates(999, '2026-11-01', '2026-11-30');
            $this->fail('Unknown ID accepted.');
        } catch (FredUnavailable $exception) {
            $this->assertSame('unknown_release', $exception->reason);
        }
        Http::assertNothingSent();
        Http::fake(['api.stlouisfed.org/*' => Http::failedConnection('private URL token='.self::FRED_TEST_KEY)]);
        $this->artisan('events:refresh')->assertExitCode(1);
        $this->assertSame(['unavailable'], DB::table('market_event_sources')->distinct()->pluck('last_error')->all());
    }

    public function test_disabled_missing_key_and_lock_paths_never_request(): void
    {
        config(['macro.enabled' => false]);
        $this->artisan('events:refresh')->assertExitCode(0);
        config(['macro.enabled' => true, 'macro.fred_api_key' => 'invalid']);
        $this->artisan('events:refresh')->assertExitCode(1);
        $this->enableFred();
        $lock = Cache::lock('events:refresh', 60);
        $this->assertTrue($lock->get());
        $this->artisan('events:refresh')->assertExitCode(0);
        $lock->release();
        Http::assertNothingSent();
        $this->assertDatabaseCount('market_events', 0);
    }

    public function test_access_matrix_and_bank_events_come_from_the_existing_calendar_even_with_macro_off(): void
    {
        $url = '/api/market-context/events?from=2026-10-20&to=2026-12-20';
        $this->getJson($url)->assertUnauthorized();
        config(['market_context.public' => false, 'macro.enabled' => false]);
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
        $events = $this->actingAs(User::factory()->admin()->create())->getJson($url)->assertOk()->json('events');
        $fed = collect($events)->firstWhere('id', 'fed:2026-12-09');
        $this->assertSame('tentative', $fed['status']);
        $this->assertSame(config('central_banks.fed.source_url'), $fed['source_url']);
        $this->assertSame(config('central_banks.fed.checked_at'), $fed['checked_at']);
        $this->assertSame('past', collect($events)->firstWhere('id', 'fed:2026-10-28')['status']);
        $this->assertSame('scheduled', collect($events)->firstWhere('id', 'ecb:2026-12-17')['status']);
        config(['market_context.public' => true]);
        $this->actingAs(User::factory()->create())->getJson($url)->assertOk();
        Http::assertNothingSent();
    }

    public function test_filters_dates_and_staleness_are_bounded_and_reads_never_fetch(): void
    {
        $this->fakeDates();
        $this->artisan('events:refresh')->assertExitCode(0);
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/market-context/events?kind=macro_release&region=EU')->assertOk()->assertJsonCount(0, 'events');
        $body = $this->getJson('/api/market-context/events?kind=macro_release')->assertOk()->json();
        $this->assertCount(10, $body['events']);
        $this->assertSame('past', $body['events'][0]['status']);
        $this->assertSame('tentative', $body['events'][9]['status']);
        Carbon::setTestNow('2026-11-14 15:00:00');
        $this->assertTrue($this->getJson('/api/market-context/events')->json('sources.cpi.stale'));
        foreach (['days=1000', 'from=2026-02-30', 'from=2026-11-12&to=2026-11-01', 'from=2026-01-01&to=2026-12-31', 'kind=earnings', 'region=GB', 'from[]=2026-11-01'] as $query) {
            $this->getJson('/api/market-context/events?'.$query)->assertUnprocessable();
        }
        Http::assertSentCount(5);
    }

    public function test_daily_schedule_is_opt_in_and_uses_market_timezone(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains($event->command ?? '', 'events:refresh'));
        $this->assertNotNull($event);
        $this->assertSame('45 6 * * *', $event->expression);
        $this->assertSame(config('ingestion.timezone'), $event->timezone);
        config(['macro.enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));
        config(['macro.enabled' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }
}
