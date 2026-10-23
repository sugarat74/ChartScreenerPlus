<?php

namespace Tests\Feature;

use App\Models\Universe;
use App\Services\MarketContext\MarketContextCache;
use App\Services\MarketContext\MarketContextComputer;
use App\Services\MarketContext\MarketContextRunLog;
use App\Services\MarketRegime\MarketRegimeComputer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesFred;
use Tests\TestCase;

/**
 * Run recording of the three Market Context commands
 * (docs/specs/market-context-ui.md, C4): whoever starts them, each records
 * running -> succeeded/failed and renews the cache version.
 */
class MarketContextRunLogTest extends TestCase
{
    use FakesFred;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-23 12:00:00', 'UTC'));
        config(['ingestion.universe' => 'sp500']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_market_regime_compute_records_a_succeeded_run_and_bumps_the_cache(): void
    {
        Universe::factory()->create(['slug' => 'sp500']);
        $version = MarketContextCache::version();

        $this->artisan('market-regime:compute')->assertExitCode(0);

        $this->assertSame([
            'status' => 'succeeded',
            'queued_at' => null,
            'started_at' => '2026-10-23T12:00:00Z',
            'finished_at' => '2026-10-23T12:00:00Z',
            'exit_code' => 0,
        ], MarketContextRunLog::get('market-regime:compute'));
        $this->assertNotSame($version, MarketContextCache::version());
    }

    public function test_a_failing_command_records_failed_with_its_exit_code(): void
    {
        // No market-context universe: the command exits 1.
        $this->artisan('market-context:compute')->assertExitCode(1);

        $run = MarketContextRunLog::get('market-context:compute');
        $this->assertSame('failed', $run['status']);
        $this->assertSame(1, $run['exit_code']);
    }

    public function test_an_exception_records_failed_and_is_rethrown(): void
    {
        $this->app->instance(MarketRegimeComputer::class, new class(new MarketContextComputer) extends MarketRegimeComputer
        {
            public function firstSnapshotDate(): ?string
            {
                throw new \LogicException('regime exploded');
            }
        });

        try {
            Artisan::call('market-regime:compute');
            $this->fail('The exception was swallowed.');
        } catch (\LogicException $exception) {
            $this->assertSame('regime exploded', $exception->getMessage());
        }

        $run = MarketContextRunLog::get('market-regime:compute');
        $this->assertSame('failed', $run['status']);
        $this->assertSame(1, $run['exit_code']);
    }

    public function test_a_start_keeps_the_queue_time_only_after_a_queued_record(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-23 11:59:00', 'UTC'));
        MarketContextRunLog::queued('market-regime:compute');
        Carbon::setTestNow(Carbon::parse('2026-10-23 12:00:00', 'UTC'));

        MarketContextRunLog::started('market-regime:compute');
        $this->assertSame('2026-10-23T11:59:00Z', MarketContextRunLog::get('market-regime:compute')['queued_at']);
        $this->assertSame('running', MarketContextRunLog::get('market-regime:compute')['status']);

        MarketContextRunLog::finished('market-regime:compute', 0);
        MarketContextRunLog::started('market-regime:compute');
        $this->assertNull(MarketContextRunLog::get('market-regime:compute')['queued_at']);
    }

    public function test_macro_refresh_disabled_and_lock_held_record_nothing(): void
    {
        $version = MarketContextCache::version();

        $this->artisan('macro:refresh')->assertExitCode(0);
        $this->assertNull(MarketContextRunLog::get('macro:refresh'));

        $this->enableFred();
        $held = Cache::lock('macro:refresh', 60);
        $this->assertTrue($held->get());
        $this->artisan('macro:refresh')->assertExitCode(0);
        $held->release();

        $this->assertNull(MarketContextRunLog::get('macro:refresh'));
        $this->assertSame($version, MarketContextCache::version());
    }

    public function test_macro_refresh_records_a_malformed_key_and_a_real_run(): void
    {
        config(['macro.enabled' => true, 'macro.fred_api_key' => 'bad']);
        $this->artisan('macro:refresh')->assertExitCode(1);
        $this->assertSame('failed', MarketContextRunLog::get('macro:refresh')['status']);

        $this->enableFred();
        $this->fakeFred();
        $this->fakeMacroSleeper();
        $this->artisan('macro:refresh')->assertExitCode(0);

        $run = MarketContextRunLog::get('macro:refresh');
        $this->assertSame('succeeded', $run['status']);
        $this->assertSame(0, $run['exit_code']);
    }

    public function test_a_stale_active_record_is_reported_as_interrupted(): void
    {
        MarketContextRunLog::queued('market-context:compute');
        $this->assertTrue(MarketContextRunLog::isActive('market-context:compute'));

        Carbon::setTestNow(Carbon::parse('2026-10-23 12:31:00', 'UTC'));

        $this->assertSame('interrupted', MarketContextRunLog::get('market-context:compute')['status']);
        $this->assertFalse(MarketContextRunLog::isActive('market-context:compute'));
        $this->assertSame('queued', MarketContextRunLog::storedStatus('market-context:compute'));
    }
}
