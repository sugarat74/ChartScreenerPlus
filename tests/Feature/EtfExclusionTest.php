<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\ChartPattern;
use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\ScreenerRow;
use App\Models\Signal;
use App\Models\Universe;
use App\Models\User;
use App\Services\Alerts\AlertEvaluator;
use App\Services\Fundamentals\FundamentalsRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesSecEdgar;
use Tests\TestCase;

/**
 * Market Context ETFs (`kind = etf`) never reach the stock features
 * (docs/specs/market-context-sectors.md, scenario 4): Screener rows and
 * results, Watchlist, alerts, Company Card, fundamentals and forecast. The
 * public chart payload still serves them, with `kind`.
 */
class EtfExclusionTest extends TestCase
{
    use FakesSecEdgar;
    use RefreshDatabase;

    private Universe $sp500;

    private Universe $market;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingestion.universe' => 'sp500', 'market_context.universe' => 'market-context']);
        $this->sp500 = Universe::factory()->create(['slug' => 'sp500', 'name' => 'S&P 500']);
        $this->market = Universe::factory()->notScreener()->create(['slug' => 'market-context', 'name' => 'Market Context']);
        Http::preventStrayRequests();
    }

    /** A sector ETF with a bar, a snapshot and (forced) a signal and a pattern. */
    private function xlk(string $date = '2026-10-01'): Instrument
    {
        $xlk = Instrument::factory()->etf()->create(['ticker' => 'XLK', 'company' => 'Technology Select Sector SPDR Fund', 'sector' => 'Information Technology']);
        $this->market->instruments()->attach($xlk->id);
        DailyBar::factory()->create(['instrument_id' => $xlk->id, 'date' => $date]);
        IndicatorSnapshot::factory()->create(['instrument_id' => $xlk->id, 'date' => $date, 'rsi14' => 60]);
        Signal::factory()->create(['instrument_id' => $xlk->id, 'date' => $date, 'type' => 'golden_cross']);
        ChartPattern::factory()->create(['instrument_id' => $xlk->id, 'as_of_date' => $date, 'end_date' => $date, 'type' => 'double_bottom', 'status' => 'confirmed']);

        return $xlk;
    }

    private function stock(string $ticker, string $date = '2026-10-01'): Instrument
    {
        $stock = Instrument::factory()->create(['ticker' => $ticker, 'company' => "{$ticker} Corp"]);
        $this->sp500->instruments()->attach($stock->id);
        DailyBar::factory()->create(['instrument_id' => $stock->id, 'date' => $date]);
        IndicatorSnapshot::factory()->create(['instrument_id' => $stock->id, 'date' => $date, 'rsi14' => 60]);

        return $stock;
    }

    public function test_screener_refresh_builds_no_etf_row_and_deletes_an_existing_one(): void
    {
        $this->stock('NVDA');
        $xlk = $this->xlk();

        // A row left from before the instrument was an ETF is removed.
        $xlk->update(['kind' => Instrument::KIND_STOCK]);
        $this->refreshScreenerRows();
        $this->assertTrue(ScreenerRow::query()->where('ticker', 'XLK')->exists());
        $xlk->update(['kind' => Instrument::KIND_ETF]);

        $this->artisan('screener:refresh')->expectsOutputToContain('Screener rows refreshed: 1 instrument(s)')->assertExitCode(0);

        $this->assertSame(['NVDA'], ScreenerRow::query()->pluck('ticker')->all());
    }

    public function test_the_market_context_universe_is_not_a_screener_universe(): void
    {
        $this->stock('NVDA');
        $this->xlk();
        $this->refreshScreenerRows();

        $this->getJson('/api/screener?universe=market-context')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Universe not found.']);
        $this->getJson('/api/screener')
            ->assertOk()
            ->assertJsonPath('candidates.0.ticker', 'NVDA')
            ->assertJsonCount(1, 'candidates');
    }

    public function test_an_etf_cannot_be_added_to_the_watchlist(): void
    {
        $this->xlk();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/watchlist', ['ticker' => 'xlk'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ticker' => 'This instrument cannot be added to your watchlist.']);

        $this->assertDatabaseCount('watchlist_items', 0);
    }

    public function test_an_etf_has_no_company_card_and_no_forecast(): void
    {
        $this->xlk();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/instruments/XLK/company-card')
            ->assertNotFound()->assertExactJson(['message' => 'Instrument not found.']);
        $this->actingAs($user)->postJson('/api/instruments/XLK/company-card')
            ->assertNotFound()->assertExactJson(['message' => 'Instrument not found.']);
        $this->actingAs($user)->postJson('/api/instruments/XLK/forecast', ['horizon' => 1, 'minimum_probability' => 60])
            ->assertNotFound();

        $this->assertDatabaseCount('ai_company_cards', 0);
        $this->assertDatabaseCount('forecast_predictions', 0);
        Http::assertNothingSent();
    }

    public function test_fundamentals_refresh_ignores_etfs(): void
    {
        $this->enableSec();
        $this->fakeSleeper();
        $this->fakeSec();
        $this->xlk();
        $this->sp500->instruments()->attach(Instrument::query()->where('ticker', 'XLK')->value('id'));

        $this->artisan('fundamentals:refresh', ['--ticker' => ['XLK']])
            ->expectsOutputToContain('No instruments to refresh.')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertDatabaseCount('company_fundamentals', 0);
        // Even attached to the main universe, the universe path skips it.
        $this->assertSame([], app(FundamentalsRefresher::class)->instruments([])->pluck('ticker')->all());
    }

    public function test_etf_bars_never_move_the_alert_as_of_date(): void
    {
        $this->stock('NVDA', '2026-10-01');
        $this->xlk('2026-10-02');

        $this->assertSame('2026-10-01', app(AlertEvaluator::class)->asOf());
    }

    public function test_watchlist_alerts_ignore_an_etf_forced_into_the_watchlist(): void
    {
        $nvda = $this->stock('NVDA');
        Signal::factory()->create(['instrument_id' => $nvda->id, 'date' => '2026-10-01', 'type' => 'golden_cross']);
        ChartPattern::factory()->create(['instrument_id' => $nvda->id, 'as_of_date' => '2026-10-01', 'end_date' => '2026-10-01', 'start_date' => '2026-08-03', 'type' => 'double_bottom', 'status' => 'confirmed']);
        $xlk = $this->xlk();
        $user = User::factory()->create();
        // Written past the API (which refuses it), as legacy data could be.
        $user->watchlist()->attach([$nvda->id, $xlk->id]);
        $signalAlert = $user->alerts()->create(['kind' => Alert::KIND_WATCHLIST, 'signal_types' => ['golden_cross']]);
        $patternAlert = Alert::factory()->for($user)->watchlistPattern(['double_bottom'], ['confirmed'])->create();

        $this->refreshScreenerRows()->artisan('alerts:evaluate')->expectsOutputToContain('2 baseline')->assertExitCode(0);

        $this->assertSame(['signals' => ['NVDA' => ['golden_cross']]], $signalAlert->fresh()->last_state);
        $this->assertSame(['NVDA'], array_keys($patternAlert->fresh()->last_state['patterns']));
    }

    public function test_the_chart_payload_still_serves_an_etf_with_its_kind(): void
    {
        $this->xlk();

        $this->getJson('/api/instruments/XLK')
            ->assertOk()
            ->assertJsonPath('instrument.ticker', 'XLK')
            ->assertJsonPath('instrument.kind', 'etf')
            ->assertJsonPath('instrument.exchange', 'NYSE Arca')
            ->assertJsonCount(1, 'bars');
    }
}
