<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsMarketContextFixture;
use Tests\TestCase;

/**
 * `GET /api/instruments/{ticker}/market-context`
 * (docs/specs/market-context-patterns.md, C3; Scenarios 1, 3 and 5) on the
 * "context" fixture: today 2026-10-23 New York, sector rows and regime on
 * 2026-10-22.
 */
class InstrumentMarketContextApiTest extends TestCase
{
    use BuildsMarketContextFixture;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->setUpMarketContextFixture();
        config(['market_context.public' => true]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function context(string $ticker): array
    {
        return $this->actingAs($this->user)->getJson("/api/instruments/{$ticker}/market-context")->assertOk()->json();
    }

    public function test_a_stock_gets_its_sector_and_the_regime_in_contract_order(): void
    {
        $this->contextFixture();

        $body = $this->context('AAPL');

        $this->assertSame(['ticker', 'as_of', 'sector', 'regime'], array_keys($body));
        $this->assertSame([
            'ticker' => 'AAPL',
            'as_of' => '2026-10-23',
            'sector' => [
                'date' => '2026-10-22', 'stale' => false, 'etf' => 'XLK', 'sector_key' => 'information_technology',
                'trend' => 'uptrend', 'rs_rank' => 1, 'rs_rank_of' => 10,
            ],
            'regime' => ['date' => '2026-10-22', 'stale' => false, 'regime' => 'neutral'],
        ], $body);

        // No score, components or macro values.
        $this->assertSame(['date', 'stale', 'regime'], array_keys($body['regime']));
    }

    public function test_the_ticker_is_trimmed_and_upper_cased(): void
    {
        $this->contextFixture();

        $this->assertSame('XOM', $this->context(' xom ')['ticker']);
        $this->assertSame(
            ['date' => '2026-10-22', 'stale' => false, 'etf' => 'XLE', 'sector_key' => 'energy', 'trend' => 'downtrend', 'rs_rank' => 10, 'rs_rank_of' => 10],
            $this->context('xom')['sector'],
        );
    }

    public function test_a_null_rank_and_edge_sectors(): void
    {
        $this->contextFixture();

        $nee = $this->context('NEE');
        $this->assertSame('XLU', $nee['sector']['etf']);
        $this->assertSame('mixed', $nee['sector']['trend']);
        $this->assertNull($nee['sector']['rs_rank']);
        $this->assertSame(10, $nee['sector']['rs_rank_of']);

        // No sector, or one without a sector ETF: the regime is still shown.
        foreach (['ZZZ', 'ODD'] as $ticker) {
            $body = $this->context($ticker);
            $this->assertNull($body['sector']);
            $this->assertSame('neutral', $body['regime']['regime']);
        }
    }

    public function test_a_null_trend_is_passed_through(): void
    {
        $this->contextFixture();
        DB::table('market_sector_snapshots')->where('sector', 'Information Technology')->update(['trend' => null]);

        $this->assertNull($this->context('AAPL')['sector']['trend']);
    }

    public function test_etfs_and_unknown_tickers_are_404(): void
    {
        $this->contextFixture();

        foreach (['XLK', 'SPY', 'NOPE'] as $ticker) {
            $this->actingAs($this->user)->getJson("/api/instruments/{$ticker}/market-context")
                ->assertNotFound()
                ->assertExactJson(['message' => 'Instrument not found.']);
        }
    }

    public function test_empty_tables_give_nulls(): void
    {
        $this->contextStocks();

        $body = $this->context('AAPL');

        $this->assertNull($body['sector']);
        $this->assertNull($body['regime']);
    }

    public function test_an_insufficient_regime_is_shown_as_is(): void
    {
        $this->sectorRows(self::LATEST, self::CONTEXT_SECTOR_ROWS);
        $this->regimeRow(self::LATEST, 'insufficient', null, 3);
        $this->contextStocks();

        $this->assertSame('insufficient', $this->context('AAPL')['regime']['regime']);
    }

    public function test_stale_flags_follow_seven_days_against_today(): void
    {
        // 2026-10-16 is 7 days before 10-23 (fresh); 10-15 is 8 days (stale).
        $this->sectorRows('2026-10-15', self::CONTEXT_SECTOR_ROWS);
        $this->regimeRow('2026-10-16');
        $this->contextStocks();

        $body = $this->context('AAPL');

        $this->assertSame('2026-10-15', $body['sector']['date']);
        $this->assertTrue($body['sector']['stale']);
        $this->assertSame('uptrend', $body['sector']['trend']);
        $this->assertFalse($body['regime']['stale']);
    }

    public function test_only_the_latest_sector_date_is_used(): void
    {
        $older = self::CONTEXT_SECTOR_ROWS;
        $older['XLK'] = [5, 0.0, 0.0, 0.0, 0.0, 'downtrend', 10.0];
        $this->sectorRows('2026-10-21', $older);
        $this->contextFixture();

        $sector = $this->context('AAPL')['sector'];

        $this->assertSame('2026-10-22', $sector['date']);
        $this->assertSame(1, $sector['rs_rank']);
    }

    public function test_the_shared_context_is_cached_until_a_recording_command_finishes(): void
    {
        $this->contextFixture();
        $this->assertSame('neutral', $this->context('AAPL')['regime']['regime']);

        DB::table('market_regime_snapshots')->update(['regime' => 'favourable']);
        DB::table('market_sector_snapshots')->where('sector', 'Information Technology')->update(['rs_rank' => 2]);

        // Still cached, for this stock and any other.
        $this->assertSame('neutral', $this->context('AAPL')['regime']['regime']);
        $this->assertSame(1, $this->context('AAPL')['sector']['rs_rank']);

        Artisan::call('market-regime:compute', ['--date' => '1999-01-01']);

        $this->assertSame('favourable', $this->context('AAPL')['regime']['regime']);
        $this->assertSame(2, $this->context('AAPL')['sector']['rs_rank']);
    }

    public function test_the_public_instrument_detail_never_carries_the_context(): void
    {
        $this->contextFixture();

        $this->getJson('/api/instruments/AAPL')->assertOk()->assertJsonMissingPath('market_context')->assertJsonMissingPath('instrument.market_context');
        $this->actingAs($this->user)->getJson('/api/instruments/AAPL')->assertOk()->assertJsonMissingPath('market_context');
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/instruments/AAPL')->assertOk()->assertJsonMissingPath('market_context');
    }
}
