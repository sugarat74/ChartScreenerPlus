<?php

namespace Tests\Feature;

use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contract tests for the public, read-only instrument detail endpoint.
 *
 * The endpoint is anonymous by design (`docs/user-and-access-model.md`) and
 * feeds the chart screen: bounded bars ordered ascending, the latest indicator
 * snapshot and the current active signals, all as explicit JSON.
 */
class InstrumentDetailApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The first date used by {@see seedBars()}.
     */
    private const BAR_START = '2020-01-01';

    /**
     * Create the instrument the endpoint is expected to return.
     */
    private function instrument(string $ticker = 'NVDA'): Instrument
    {
        return Instrument::factory()->create([
            'ticker' => $ticker,
            'company' => 'Nvidia',
            'sector' => 'Information Technology',
            'exchange' => 'NASDAQ',
            'active' => true,
        ]);
    }

    /**
     * Bulk-insert `$count` consecutive daily bars, fast enough for >2000 rows.
     */
    private function seedBars(Instrument $instrument, int $count): void
    {
        $start = Carbon::parse(self::BAR_START);
        $rows = [];

        for ($index = 0; $index < $count; $index++) {
            $close = 100 + ($index / 100);

            $rows[] = [
                'instrument_id' => $instrument->id,
                'date' => $start->copy()->addDays($index)->format('Y-m-d'),
                'open' => $close,
                'high' => $close + 1,
                'low' => $close - 1,
                'close' => $close,
                'volume' => 1_000_000 + $index,
                'created_at' => $start,
                'updated_at' => $start,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('daily_bars')->insert($chunk);
        }
    }

    /**
     * The date of the bar at `$index` in a {@see seedBars()} series.
     */
    private function barDate(int $index): string
    {
        return Carbon::parse(self::BAR_START)->addDays($index)->format('Y-m-d');
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function assertAscending(array $values): void
    {
        $sorted = $values;
        sort($sorted);

        $this->assertSame($sorted, $values, 'Bars are not ordered ascending by date.');
    }

    public function test_unknown_ticker_returns_404_json(): void
    {
        $this->getJson('/api/instruments/ZZZZ')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Instrument not found.']);
    }

    public function test_returns_bars_ordered_ascending(): void
    {
        $instrument = $this->instrument();

        // Inserted out of order on purpose: the payload must be ascending.
        foreach (['2026-09-03', '2026-09-01', '2026-09-02'] as $date) {
            DailyBar::factory()->create([
                'instrument_id' => $instrument->id,
                'date' => $date,
            ]);
        }

        $response = $this->getJson('/api/instruments/NVDA')->assertOk();

        $response
            ->assertJsonPath('instrument.ticker', 'NVDA')
            ->assertJsonPath('instrument.company', 'Nvidia')
            ->assertJsonPath('instrument.sector', 'Information Technology')
            ->assertJsonPath('instrument.exchange', 'NASDAQ')
            ->assertJsonPath('instrument.active', true)
            ->assertJsonCount(3, 'bars')
            ->assertJsonPath('bars.0.date', '2026-09-01')
            ->assertJsonPath('bars.1.date', '2026-09-02')
            ->assertJsonPath('bars.2.date', '2026-09-03')
            ->assertJsonPath('meta.bar_count', 3)
            ->assertJsonPath('meta.latest_bar_date', '2026-09-03');

        $this->assertAscending(array_column($response->json('bars'), 'date'));
    }

    public function test_returns_latest_snapshot_and_signals(): void
    {
        $instrument = $this->instrument();

        IndicatorSnapshot::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => '2026-08-01',
            'rsi14' => 20,
        ]);

        IndicatorSnapshot::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => '2026-09-28',
            'sma20' => 170.1,
            'rsi14' => 62.5,
            'sma200' => null,
        ]);

        // Stored out of order: the payload is ordered by `type` ascending.
        Signal::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => '2026-09-28',
            'type' => 'rsi_overbought',
            'metadata' => ['rsi14' => 72.5],
        ]);

        Signal::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => '2026-09-28',
            'type' => 'golden_cross',
            'metadata' => ['sma50' => 101.25],
        ]);

        $response = $this->getJson('/api/instruments/NVDA')->assertOk();

        $response
            ->assertJsonPath('snapshot.date', '2026-09-28')
            ->assertJsonPath('snapshot.sma20', 170.1)
            ->assertJsonPath('snapshot.rsi14', 62.5)
            ->assertJsonPath('snapshot.sma200', null)
            ->assertJsonCount(2, 'signals')
            ->assertJsonPath('signals.0.type', 'golden_cross')
            ->assertJsonPath('signals.0.date', '2026-09-28')
            ->assertJsonPath('signals.0.metadata', ['sma50' => 101.25])
            ->assertJsonPath('signals.1.type', 'rsi_overbought')
            ->assertJsonPath('signals.1.metadata', ['rsi14' => 72.5]);

        $types = array_column($response->json('signals'), 'type');
        $this->assertSame(['golden_cross', 'rsi_overbought'], $types);
    }

    public function test_bars_are_bounded_by_limit(): void
    {
        $instrument = $this->instrument();
        $this->seedBars($instrument, 2010);

        // Default: the most recent 252 bars, ascending.
        $default = $this->getJson('/api/instruments/NVDA')->assertOk();

        $default
            ->assertJsonCount(252, 'bars')
            ->assertJsonPath('meta.limit', 252)
            ->assertJsonPath('meta.bar_count', 252)
            ->assertJsonPath('meta.latest_bar_date', $this->barDate(2009));

        $this->assertSame($this->barDate(1758), $default->json('bars.0.date'));
        $this->assertSame($this->barDate(2009), $default->json('bars.251.date'));
        $this->assertAscending(array_column($default->json('bars'), 'date'));

        // Explicit limit: the 10 most recent bars.
        $ten = $this->getJson('/api/instruments/NVDA?limit=10')->assertOk();

        $ten
            ->assertJsonCount(10, 'bars')
            ->assertJsonPath('meta.limit', 10);

        $this->assertSame($this->barDate(2000), $ten->json('bars.0.date'));
        $this->assertSame($this->barDate(2009), $ten->json('bars.9.date'));

        // Huge limit: clamped to the hard maximum of 2000 bars.
        $clamped = $this->getJson('/api/instruments/NVDA?limit=99999')->assertOk();

        $clamped
            ->assertJsonCount(2000, 'bars')
            ->assertJsonPath('meta.limit', 2000)
            ->assertJsonPath('meta.bar_count', 2000);

        $this->assertSame($this->barDate(10), $clamped->json('bars.0.date'));
        $this->assertSame($this->barDate(2009), $clamped->json('bars.1999.date'));

        // Zero/negative clamp up to 1; a non-numeric value falls back to 252.
        $this->getJson('/api/instruments/NVDA?limit=0')->assertOk()->assertJsonCount(1, 'bars');
        $this->getJson('/api/instruments/NVDA?limit=-5')->assertOk()->assertJsonCount(1, 'bars');

        $nonNumeric = $this->getJson('/api/instruments/NVDA?limit=abc')->assertOk();
        $nonNumeric
            ->assertJsonCount(252, 'bars')
            ->assertJsonPath('meta.limit', 252);
    }

    public function test_sparse_instrument_returns_null_snapshot_and_empty_signals(): void
    {
        $instrument = $this->instrument();

        DailyBar::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => '2026-09-28',
        ]);

        $this->getJson('/api/instruments/NVDA')
            ->assertOk()
            ->assertJsonCount(1, 'bars')
            ->assertJsonPath('snapshot', null)
            ->assertJsonPath('signals', []);
    }

    public function test_an_instrument_without_bars_still_returns_a_bounded_payload(): void
    {
        $this->instrument();

        $this->getJson('/api/instruments/NVDA')
            ->assertOk()
            ->assertJsonPath('bars', [])
            ->assertJsonPath('snapshot', null)
            ->assertJsonPath('signals', [])
            ->assertJsonPath('meta.bar_count', 0)
            ->assertJsonPath('meta.latest_bar_date', null);
    }

    public function test_ticker_lookup_is_case_insensitive(): void
    {
        $this->instrument('AAPL');

        $this->getJson('/api/instruments/aapl')
            ->assertOk()
            ->assertJsonPath('instrument.ticker', 'AAPL');
    }

    public function test_payload_decimals_are_numbers(): void
    {
        $instrument = $this->instrument();

        // Non-integral values so the payload keeps a JSON float. (A whole-number
        // decimal like 175.0000 would serialize as the JSON number 175, which
        // is still numeric but no longer a PHP float after decoding.)
        DailyBar::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => '2026-09-28',
            'open' => '175.2500',
            'high' => '179.7500',
            'low' => '174.5000',
            'close' => '178.2000',
            'volume' => 1234567,
        ]);

        // `sma200` is null on purpose: insufficient history stays null.
        IndicatorSnapshot::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => '2026-09-28',
            'rsi14' => 62.5,
            'sma200' => null,
        ]);

        $response = $this->getJson('/api/instruments/NVDA')->assertOk();

        $this->assertIsFloat($response->json('bars.0.open'));
        $this->assertIsFloat($response->json('bars.0.high'));
        $this->assertIsFloat($response->json('bars.0.low'));
        $this->assertIsFloat($response->json('bars.0.close'));
        $this->assertIsInt($response->json('bars.0.volume'));

        $this->assertSame(178.2, $response->json('bars.0.close'));
        $this->assertSame(1234567, $response->json('bars.0.volume'));

        $this->assertIsFloat($response->json('snapshot.rsi14'));
        $this->assertSame(62.5, $response->json('snapshot.rsi14'));
        $this->assertNull($response->json('snapshot.sma200'));

        // The `decimal:4` cast string is never serialized to the client.
        $this->assertStringNotContainsString('178.2000', $response->getContent());
    }

    public function test_json_structure(): void
    {
        $instrument = $this->instrument();

        DailyBar::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => '2026-09-28',
        ]);

        IndicatorSnapshot::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => '2026-09-28',
        ]);

        Signal::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => '2026-09-28',
            'type' => 'golden_cross',
        ]);

        $payload = $this->getJson('/api/instruments/NVDA')->assertOk()->json();

        $this->assertSame(
            ['instrument', 'bars', 'snapshot', 'signals', 'meta'],
            array_keys($payload),
        );

        $this->assertSame(
            ['ticker', 'company', 'sector', 'exchange', 'active'],
            array_keys($payload['instrument']),
        );

        $this->assertSame(
            ['date', 'open', 'high', 'low', 'close', 'volume'],
            array_keys($payload['bars'][0]),
        );

        $this->assertSame(
            [
                'date', 'sma20', 'sma50', 'sma200', 'ema21', 'ema55', 'rsi14', 'adx',
                'macd', 'macd_signal', 'macd_hist', 'bb_upper', 'bb_middle', 'bb_lower', 'rvol',
            ],
            array_keys($payload['snapshot']),
        );

        $this->assertSame(
            ['type', 'date', 'metadata'],
            array_keys($payload['signals'][0]),
        );

        $this->assertSame(
            ['limit', 'bar_count', 'latest_bar_date'],
            array_keys($payload['meta']),
        );
    }
}
