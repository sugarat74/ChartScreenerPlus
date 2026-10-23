<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\User;
use App\Services\Macro\MacroSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsMarketContextFixture;
use Tests\TestCase;

/**
 * `GET /api/market-context/history` (docs/specs/market-context-ui.md, C2;
 * Scenarios 2-3). Every expected point is computed by hand in the comments.
 */
class MarketContextHistoryTest extends TestCase
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
    private function history(string $query): array
    {
        return $this->actingAs($this->user)->getJson('/api/market-context/history?'.$query)->assertOk()->json();
    }

    public function test_guests_get_401_and_registered_users_200(): void
    {
        $this->macroFixture();

        $this->getJson('/api/market-context/history?metric=dgs10&range=1y')->assertUnauthorized();
        $this->actingAs($this->user)->getJson('/api/market-context/history?metric=dgs10&range=1y')
            ->assertOk()
            ->assertJsonStructure(['metric', 'range', 'disabled', 'from', 'to', 'first_date', 'points']);
    }

    public function test_gate_off_is_404_for_users_and_open_for_admins(): void
    {
        config(['market_context.public' => false]);

        $this->actingAs($this->user)->getJson('/api/market-context/history?metric=dgs10')
            ->assertNotFound()
            ->assertJsonPath('message', 'This page is not available yet.');

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/market-context/history?metric=dgs10')->assertOk();
    }

    public function test_unknown_metrics_and_ranges_are_422(): void
    {
        // AAPL is a stock of the main Universe, SPY the benchmark (no sector).
        $aapl = Instrument::factory()->create(['ticker' => 'AAPL', 'sector' => 'Information Technology']);
        $this->mainUniverse->instruments()->attach($aapl->id);

        foreach (['t10y3m', 'vix', 'sector_rs_60:SPY', 'sector_rs_60:AAPL', 'sector_rs_60:xlk', 'sector_rs_60:', ''] as $metric) {
            $this->actingAs($this->user)->getJson('/api/market-context/history?metric='.urlencode($metric))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['metric'])
                ->assertJsonMissingValidationErrors(['range']);
        }

        $this->actingAs($this->user)->getJson('/api/market-context/history')->assertUnprocessable()->assertJsonValidationErrors(['metric']);

        $this->actingAs($this->user)->getJson('/api/market-context/history?metric=dgs10&range=5y')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['range'])
            ->assertJsonMissingValidationErrors(['metric'])
            ->assertJsonPath('errors.range.0', 'The range must be 1y or 3y.');
    }

    public function test_regime_score_keeps_insufficient_as_a_gap_and_reports_first_date(): void
    {
        $this->regimeRow('2025-10-22', 'neutral', 1, 5);
        $this->regimeRow('2026-01-05', 'insufficient', null, 3);
        $this->regimeRow('2026-10-22', 'favourable', 3, 6);

        // Default range 1y: from = 2026-10-22 - 1 year = 2025-10-22.
        $body = $this->history('metric=regime_score');
        $this->assertSame([
            'metric' => 'regime_score', 'range' => '1y', 'disabled' => false,
            'from' => '2025-10-22', 'to' => '2026-10-22', 'first_date' => '2025-10-22',
            'points' => [
                ['date' => '2025-10-22', 'value' => 1],
                ['date' => '2026-01-05', 'value' => null],
                ['date' => '2026-10-22', 'value' => 3],
            ],
        ], $body);

        // 3y: from = 2023-10-22; the history starts later (Scenario 2).
        $body = $this->history('metric=regime_score&range=3y');
        $this->assertSame('2023-10-22', $body['from']);
        $this->assertSame('2025-10-22', $body['first_date']);
        $this->assertCount(3, $body['points']);
    }

    public function test_breadth_skips_nulls_and_cuts_the_window(): void
    {
        $this->breadthRow('2023-06-01', 50.0);
        $this->breadthRow('2025-10-21', 52.0);
        $this->breadthRow('2025-10-22', 53.0);
        $this->breadthRow('2026-01-05', null);
        $this->breadthRow('2026-10-22', 55.2);

        $body = $this->history('metric=pct_above_sma200&range=1y');
        $this->assertSame('2025-10-22', $body['from']);
        $this->assertSame('2023-06-01', $body['first_date']);
        $this->assertEquals([['date' => '2025-10-22', 'value' => 53.0], ['date' => '2026-10-22', 'value' => 55.2]], $body['points']);

        $body = $this->history('metric=pct_above_sma200&range=3y');
        $this->assertSame('2023-10-22', $body['from']);
        $this->assertSame(['2025-10-21', '2025-10-22', '2026-10-22'], array_column($body['points'], 'date'));
    }

    public function test_macro_metrics_are_hand_computed(): void
    {
        $this->macroFixture();
        // A WALCL week without the other legs is skipped.
        $this->observe('WALCL', ['2026-10-21' => 7000000]);

        $dgs10 = $this->history('metric=dgs10');
        $this->assertCount(23, $dgs10['points']);
        $this->assertSame('2026-10-21', $dgs10['to']);
        $this->assertSame('2025-10-21', $dgs10['from']);
        $this->assertSame(['date' => '2026-10-21', 'value' => 4.11], $dgs10['points'][22]);

        // Common dates only: 10-20 4.105 - 3.80, 10-21 4.11 - 3.80.
        $spread = $this->history('metric=spread_10y3m');
        $this->assertSame(['2026-10-20', '2026-10-21'], array_column($spread['points'], 'date'));
        $this->assertEqualsWithDelta(0.305, $spread['points'][0]['value'], 0.00001);
        $this->assertEqualsWithDelta(0.31, $spread['points'][1]['value'], 0.00001);

        $net = $this->history('metric=net_liquidity');
        $this->assertEquals([
            ['date' => '2026-07-15', 'value' => 6000],
            ['date' => '2026-09-16', 'value' => 6065],
            ['date' => '2026-10-14', 'value' => 6130],
        ], $net['points']);

        $claims = $this->history('metric=icsa_4w');
        $this->assertEquals([['date' => '2026-10-17', 'value' => 227500]], $claims['points']);
    }

    public function test_claims_average_needs_four_consecutive_weeks(): void
    {
        // 09-26 is missing: points only where the trailing 4 weeks are 7 days apart.
        $this->observe('ICSA', [
            '2026-08-22' => 200000, '2026-08-29' => 204000, '2026-09-05' => 208000, '2026-09-12' => 212000,
            '2026-09-19' => 216000, '2026-10-03' => 220000, '2026-10-10' => 224000, '2026-10-17' => 228000,
        ]);

        // (200 + 204 + 208 + 212) / 4 = 206k; (204 + 208 + 212 + 216) / 4 = 210k.
        $this->assertEquals(
            [['date' => '2026-09-12', 'value' => 206000], ['date' => '2026-09-19', 'value' => 210000]],
            $this->history('metric=icsa_4w')['points'],
        );
    }

    public function test_sector_relative_strength_of_a_sector_etf(): void
    {
        $this->sectorRows('2026-10-21', ['XLK' => [2, 1.0, 4.0, 9.0, 2.75, 'uptrend', 70.0]]);
        $this->sectorRows();

        $body = $this->history('metric=sector_rs_60:XLK&range=3y');
        $this->assertSame('sector_rs_60:XLK', $body['metric']);
        $this->assertEquals([['date' => '2026-10-21', 'value' => 2.75], ['date' => '2026-10-22', 'value' => 3.21]], $body['points']);
    }

    public function test_disabled_series_and_disabled_macro(): void
    {
        $this->macroFixture();

        // NFCI is off by default.
        $this->assertSame(
            ['metric' => 'nfci', 'range' => '1y', 'disabled' => true, 'from' => null, 'to' => null, 'first_date' => null, 'points' => []],
            $this->history('metric=nfci'),
        );

        config(['macro.series.NFCI.enabled' => true]);
        $this->observe('NFCI', ['2026-10-09' => -0.51, '2026-10-16' => -0.48]);
        $body = $this->history('metric=nfci&range=3y');
        $this->assertFalse($body['disabled']);
        $this->assertCount(2, $body['points']);

        config(['macro.enabled' => false]);
        $body = $this->history('metric=dgs10&range=3y');
        $this->assertTrue($body['disabled']);
        $this->assertSame([], $body['points']);
    }

    public function test_no_points_gives_null_dates(): void
    {
        $this->assertSame(
            ['metric' => 'regime_score', 'range' => '1y', 'disabled' => false, 'from' => null, 'to' => null, 'first_date' => null, 'points' => []],
            $this->history('metric=regime_score'),
        );
    }

    public function test_last_points_equal_the_snapshot_service_readings(): void
    {
        $this->macroFixture();
        $snapshot = app(MacroSnapshotService::class)->snapshot(self::TODAY);

        $last = fn (string $metric): array => array_slice($this->history("metric={$metric}")['points'], -1)[0];

        $this->assertSame($snapshot['curve']['spread_10y3m']['value'], $last('spread_10y3m')['value']);
        $this->assertSame($snapshot['curve']['spread_10y3m']['as_of'], $last('spread_10y3m')['date']);
        $this->assertEquals($snapshot['liquidity']['net_liquidity']['value'], $last('net_liquidity')['value']);
        $this->assertSame($snapshot['liquidity']['net_liquidity']['as_of'], $last('net_liquidity')['date']);
        $this->assertEquals($snapshot['leading']['icsa_4w']['value'], $last('icsa_4w')['value']);
        $this->assertSame($snapshot['leading']['icsa_4w']['as_of'], $last('icsa_4w')['date']);
    }
}
