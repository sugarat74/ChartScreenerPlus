<?php

namespace Tests\Feature;

use App\Models\ChartPattern;
use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\ScreenerRow;
use App\Models\Signal;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * screener-precomputed: `screener:refresh` builds one row per qualifying
 * instrument with the values the Candidate list and the SQL filters need,
 * and removes rows of instruments that no longer qualify.
 */
class ScreenerRowBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function instrument(string $ticker, array $bars, ?array $snapshot = []): Instrument
    {
        $instrument = Instrument::factory()->create(['ticker' => $ticker, 'company' => "{$ticker} Inc", 'sector' => 'Tech', 'exchange' => 'NYSE']);
        foreach ($bars as $date => $close) {
            DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => $date, 'close' => $close]);
        }
        if ($snapshot !== null) {
            IndicatorSnapshot::factory()->create(array_merge(
                ['instrument_id' => $instrument->id, 'date' => array_key_last($bars) ?? '2026-10-01'],
                $snapshot,
            ));
        }

        return $instrument;
    }

    public function test_it_builds_rows_only_for_instruments_with_a_bar_and_a_snapshot(): void
    {
        $this->instrument('AAA', ['2026-10-01' => 100, '2026-10-02' => 110], ['rvol' => 2.5, 'rsi14' => 61.25, 'sma50' => 105, 'sma200' => 90]);
        $this->instrument('NOSNAP', ['2026-10-02' => 50], null);
        Instrument::factory()->create(['ticker' => 'NOBARS']);

        $this->artisan('screener:refresh')->expectsOutputToContain('Screener rows refreshed: 1 instrument(s)')->assertExitCode(0);

        $row = ScreenerRow::query()->sole();
        $this->assertSame('AAA', $row->ticker);
        $this->assertSame('2026-10-02', $row->date->toDateString());
        $this->assertEqualsWithDelta(110.0, (float) $row->close, 1e-9);
        $this->assertEqualsWithDelta(100.0, (float) $row->previous_close, 1e-9);
        $this->assertEqualsWithDelta(10.0, $row->change_percent, 1e-9);
        $this->assertEqualsWithDelta(2.5, (float) $row->rvol, 1e-9);
        $this->assertTrue($row->price_above_sma200);
        $this->assertSame('bullish', $row->ma_cross);
    }

    public function test_flags_signals_and_patterns_are_stored_like_the_candidate_payload(): void
    {
        $bear = $this->instrument('BEAR', ['2026-10-02' => 80], ['sma50' => 85, 'sma200' => 95, 'rsi14' => null, 'rvol' => null]);
        Signal::factory()->create(['instrument_id' => $bear->id, 'date' => '2026-10-02', 'type' => 'rsi_oversold']);
        Signal::factory()->create(['instrument_id' => $bear->id, 'date' => '2026-10-02', 'type' => 'death_cross']);
        ChartPattern::factory()->create(['instrument_id' => $bear->id, 'type' => 'double_top', 'status' => 'confirmed', 'breakout_level' => 82, 'end_date' => '2026-09-20']);
        $this->instrument('FLAT', ['2026-10-02' => 50], ['sma50' => 60, 'sma200' => 60]);

        $this->artisan('screener:refresh')->assertExitCode(0);

        $bearRow = ScreenerRow::query()->where('ticker', 'BEAR')->sole();
        $this->assertNull($bearRow->change_percent);
        $this->assertNull($bearRow->previous_close);
        $this->assertNull($bearRow->rvol);
        $this->assertFalse($bearRow->price_above_sma200);
        $this->assertSame('bearish', $bearRow->ma_cross);
        $this->assertSame(2, $bearRow->signal_count);
        $this->assertSame(['death_cross', 'rsi_oversold'], $bearRow->signals);
        $this->assertSame([['type' => 'double_top', 'status' => 'confirmed', 'breakout_level' => 82.0, 'end_date' => '2026-09-20']], $bearRow->patterns);

        $flat = ScreenerRow::query()->where('ticker', 'FLAT')->sole();
        $this->assertNull($flat->ma_cross);
        $this->assertSame([], $flat->signals);
    }

    public function test_refresh_is_idempotent_and_drops_instruments_that_no_longer_qualify(): void
    {
        $keep = $this->instrument('KEEP', ['2026-10-02' => 10]);
        $gone = $this->instrument('GONE', ['2026-10-02' => 20]);

        $this->artisan('screener:refresh')->assertExitCode(0);
        $this->artisan('screener:refresh')->assertExitCode(0);
        $this->assertSame(2, ScreenerRow::query()->count());

        IndicatorSnapshot::query()->where('instrument_id', $gone->id)->delete();
        DailyBar::factory()->create(['instrument_id' => $keep->id, 'date' => '2026-10-05', 'close' => 11]);
        $this->artisan('screener:refresh')->assertExitCode(0);

        $this->assertSame(['KEEP'], ScreenerRow::query()->pluck('ticker')->all());
        $this->assertSame('2026-10-05', ScreenerRow::query()->sole()->date->toDateString());

        IndicatorSnapshot::query()->delete();
        $this->artisan('screener:refresh')->expectsOutputToContain('0 instrument(s)')->assertExitCode(0);
        $this->assertSame(0, ScreenerRow::query()->count());
    }

    public function test_a_screener_request_reads_the_rows_not_live_data(): void
    {
        $instrument = $this->instrument('AAA', ['2026-10-02' => 10]);
        $universe = Universe::factory()->create(['slug' => 'sp500']);
        $universe->instruments()->attach($instrument->id);
        config(['ingestion.universe' => 'sp500']);

        // Without a refresh there is nothing to list yet.
        $this->getJson('/api/screener')->assertOk()->assertJsonPath('meta.total', 0);

        $this->refreshScreenerRows()->getJson('/api/screener')->assertOk()->assertJsonPath('meta.total', 1);
    }
}
