<?php

namespace Tests\Feature;

use App\Models\MacroObservation;
use App\Models\MacroSeries;
use App\Services\Macro\MacroSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Derived Macro readings from observations inserted directly
 * (docs/specs/macro-series-fred.md, Domain Rule 5, Scenario 6). Every
 * expected value is computed by hand in the comments.
 */
class MacroSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    private const MISSING = ['value' => null, 'as_of' => null, 'stale' => false];

    /** @param  array<string, float|int>  $values  date => value */
    private function observe(string $code, array $values): void
    {
        $config = config("macro.series.{$code}");
        $series = MacroSeries::query()->firstOrCreate(['code' => $code], [
            'source' => 'fred',
            'category' => $config['category'],
            'frequency' => $config['frequency'],
        ]);
        foreach ($values as $date => $value) {
            MacroObservation::factory()->create(['macro_series_id' => $series->id, 'date' => $date, 'value' => $value]);
        }
    }

    /**
     * `$count` dates `$step` days apart ending on `$last`, mapped to `$value($i)`.
     *
     * @return array<string, float|int>
     */
    private static function series(string $last, int $count, int $step, callable $value): array
    {
        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $values[Carbon::parse($last)->subDays($step * ($count - 1 - $i))->toDateString()] = $value($i);
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private function snapshot(string $asOf, bool $pointInTime = false): array
    {
        return app(MacroSnapshotService::class)->snapshot($asOf, $pointInTime);
    }

    public function test_an_empty_store_gives_missing_readings_and_disabled_series_give_null(): void
    {
        Http::preventStrayRequests();

        $snapshot = $this->snapshot('2026-10-22');

        $this->assertSame('2026-10-22', $snapshot['as_of']);
        $this->assertSame(self::MISSING, $snapshot['rates']['dgs10']);
        $this->assertSame(self::MISSING, $snapshot['rates']['dgs10_change_20']);
        $this->assertSame(self::MISSING, $snapshot['curve']['spread_10y3m']);
        $this->assertNull($snapshot['curve']['inverted']);
        $this->assertSame(self::MISSING, $snapshot['liquidity']['net_liquidity']);
        $this->assertSame(self::MISSING, $snapshot['leading']['icsa_4w']);
        $this->assertSame(self::MISSING, $snapshot['central_banks']['fed']['upper']);
        $this->assertNull($snapshot['central_banks']['fed']['last_change']);
        // NFCI and VIXCLS are off by default: null instead of a reading.
        $this->assertNull($snapshot['leading']['nfci']);
        $this->assertNull($snapshot['volatility']);
    }

    public function test_rates_and_the_20_observation_change(): void
    {
        // 21 observations 4.00, 4.01, ..., 4.20 ending 2026-10-20.
        $this->observe('DGS10', self::series('2026-10-20', 21, 1, fn (int $i): float => 4.00 + $i / 100));
        $this->observe('DGS2', ['2026-10-20' => 3.9]);

        $rates = $this->snapshot('2026-10-22')['rates'];

        $this->assertSame(['value' => 4.2, 'as_of' => '2026-10-20', 'stale' => false], $rates['dgs10']);
        // 4.20 − 4.00 = 0.20 pp.
        $this->assertSame(['value' => 0.2, 'as_of' => '2026-10-20', 'stale' => false], $rates['dgs10_change_20']);
        $this->assertSame(3.9, $rates['dgs2']['value']);
        $this->assertSame(self::MISSING, $rates['dgs3mo']);

        // With only 20 observations up to 2026-10-19 the change is unavailable.
        $this->assertSame(self::MISSING, $this->snapshot('2026-10-19')['rates']['dgs10_change_20']);
    }

    public function test_the_curve_uses_the_latest_common_date_and_strict_inversion(): void
    {
        $this->observe('DGS10', ['2026-10-19' => 4.30, '2026-10-20' => 4.26]);
        $this->observe('DGS3MO', ['2026-10-19' => 4.31, '2026-10-20' => 4.26]);
        // DGS2 has no 2026-10-20 observation: the 10y−2y spread uses 2026-10-19.
        $this->observe('DGS2', ['2026-10-19' => 4.05]);

        $curve = $this->snapshot('2026-10-21')['curve'];

        // 4.26 − 4.26 = 0: not inverted (strict).
        $this->assertSame(['value' => 0.0, 'as_of' => '2026-10-20', 'stale' => false], $curve['spread_10y3m']);
        $this->assertFalse($curve['inverted']);
        // 4.30 − 4.05 = 0.25 on 2026-10-19.
        $this->assertSame(['value' => 0.25, 'as_of' => '2026-10-19', 'stale' => false], $curve['spread_10y2y']);

        // On 2026-10-19 alone: 4.30 − 4.31 = −0.01, inverted.
        $curve = $this->snapshot('2026-10-19')['curve'];
        $this->assertSame(-0.01, $curve['spread_10y3m']['value']);
        $this->assertTrue($curve['inverted']);
    }

    public function test_net_liquidity_uses_complete_wednesdays_only(): void
    {
        // Wednesdays 2026-07-08 .. 2026-10-14 (15 weeks).
        $weeks = self::series('2026-10-14', 15, 7, fn (): int => 0);
        $walcl = array_map(fn (): int => 7_000_000, $weeks);
        $walcl['2026-09-09'] = 6_800_000;
        $walcl['2026-07-08'] = 7_100_000;
        $this->observe('WALCL', $walcl);
        $this->observe('WDTGAL', array_map(fn (): int => 800_000, $weeks));
        $reverseRepo = array_map(fn (): int => 1_000, $weeks);
        // The latest Wednesday misses its reverse-repo leg: it is skipped.
        unset($reverseRepo['2026-10-14']);
        $this->observe('WLRRAOL', $reverseRepo);

        $liquidity = $this->snapshot('2026-10-20')['liquidity'];

        // 2026-10-07: (7,000,000 − 800,000 − 1,000) / 1000 = 6,199 bn.
        $this->assertSame(['value' => 6199.0, 'as_of' => '2026-10-07', 'stale' => false], $liquidity['net_liquidity']);
        // Base 2026-09-09: (6,800,000 − 800,000 − 1,000) / 1000 = 5,999; 6199 / 5999 − 1 = 3.3339 %.
        $this->assertSame(['value' => 3.3339, 'as_of' => '2026-10-07', 'stale' => false], $liquidity['change_4w_pct']);
        // Base 2026-07-08: (7,100,000 − 800,000 − 1,000) / 1000 = 6,299; 6199 / 6299 − 1 = −1.5876 %.
        $this->assertSame(-1.5876, $liquidity['change_13w_pct']['value']);
    }

    public function test_a_missing_liquidity_base_gives_null(): void
    {
        $weeks = self::series('2026-10-14', 5, 7, fn (): int => 0);
        $this->observe('WALCL', array_map(fn (): int => 7_000_000, $weeks));
        $tga = array_map(fn (): int => 800_000, $weeks);
        unset($tga['2026-09-16']);
        $this->observe('WDTGAL', $tga);
        $this->observe('WLRRAOL', array_map(fn (): int => 1_000, $weeks));

        $liquidity = $this->snapshot('2026-10-15')['liquidity'];

        $this->assertSame(6199.0, $liquidity['net_liquidity']['value']);
        // 2026-10-14 − 28 d = 2026-09-16 misses its TGA leg; no 13-week base either.
        $this->assertSame(self::MISSING, $liquidity['change_4w_pct']);
        $this->assertSame(self::MISSING, $liquidity['change_13w_pct']);
    }

    public function test_claims_averages_need_consecutive_weeks(): void
    {
        // 55 consecutive Saturdays: 51 at 220,000 then 4 at 230,000.
        $claims = self::series('2026-10-17', 55, 7, fn (int $i): int => $i < 51 ? 220_000 : 230_000);
        $this->observe('ICSA', $claims);

        $leading = $this->snapshot('2026-10-22')['leading'];

        $this->assertSame(['value' => 230000.0, 'as_of' => '2026-10-17', 'stale' => false], $leading['icsa']);
        $this->assertSame(['value' => 230000.0, 'as_of' => '2026-10-17', 'stale' => false], $leading['icsa_4w']);
        // Min 4-week average of the last 52 weeks is 220,000: 230,000 / 220,000 − 1 = 4.5455 %.
        $this->assertSame(['value' => 4.5455, 'as_of' => '2026-10-17', 'stale' => false], $leading['icsa_4w_vs_52w_min_pct']);

        // A gap 20 weeks back breaks the 55-week run but not the latest four.
        MacroObservation::query()->whereDate('date', Carbon::parse('2026-10-17')->subWeeks(20)->toDateString())->delete();
        $leading = $this->snapshot('2026-10-22')['leading'];
        $this->assertSame(230000.0, $leading['icsa_4w']['value']);
        $this->assertSame(self::MISSING, $leading['icsa_4w_vs_52w_min_pct']);

        // A gap inside the latest four weeks: no 4-week average.
        MacroObservation::query()->whereDate('date', '2026-10-10')->delete();
        $this->assertSame(self::MISSING, $this->snapshot('2026-10-22')['leading']['icsa_4w']);
    }

    public function test_monthly_changes_use_the_exact_month_start(): void
    {
        $this->observe('PERMIT', ['2026-06-01' => 1374, '2026-07-01' => 1433, '2026-08-01' => 1403, '2026-09-01' => 1400]);
        $this->observe('USALOLITOAASTSAM', ['2026-08-01' => 100.40, '2026-09-01' => 100.45]);

        $leading = $this->snapshot('2026-10-22')['leading'];

        $this->assertSame(['value' => 1400.0, 'as_of' => '2026-09-01', 'stale' => false], $leading['permit']);
        // 1400 / 1374 − 1 = 1.8923 %.
        $this->assertSame(['value' => 1.8923, 'as_of' => '2026-09-01', 'stale' => false], $leading['permit_change_3m_pct']);
        // 100.45 − 100.40 = 0.05.
        $this->assertSame(['value' => 100.45, 'as_of' => '2026-09-01', 'stale' => false], $leading['oecd_cli']);
        $this->assertSame(0.05, $leading['oecd_cli_change_1m']['value']);

        // Without the June base there is no 3-month change.
        MacroObservation::query()->whereDate('date', '2026-06-01')->delete();
        $this->assertSame(self::MISSING, $this->snapshot('2026-10-22')['leading']['permit_change_3m_pct']);
    }

    public function test_stale_boundaries_by_frequency(): void
    {
        $this->observe('DGS10', ['2026-10-13' => 4.1]);
        $this->observe('ICSA', ['2026-10-03' => 210_000]);
        $this->observe('PERMIT', ['2026-07-01' => 1433]);

        // Daily: 7 days old is fresh, 8 is stale.
        $this->assertFalse($this->snapshot('2026-10-20')['rates']['dgs10']['stale']);
        $this->assertTrue($this->snapshot('2026-10-21')['rates']['dgs10']['stale']);
        // Weekly: 16 / 17 days.
        $this->assertFalse($this->snapshot('2026-10-19')['leading']['icsa']['stale']);
        $this->assertTrue($this->snapshot('2026-10-20')['leading']['icsa']['stale']);
        // Monthly: 100 / 101 days (2026-07-01 + 100 d = 2026-10-09).
        $this->assertFalse($this->snapshot('2026-10-09')['leading']['permit']['stale']);
        $this->assertTrue($this->snapshot('2026-10-10')['leading']['permit']['stale']);
    }

    public function test_point_in_time_ignores_observations_published_after_as_of(): void
    {
        // PERMIT has a 50-day release lag: 2026-09-01 is published ~2026-10-21.
        $this->observe('PERMIT', ['2026-08-01' => 1403, '2026-09-01' => 1400]);
        // DGS10 has a 1-day lag.
        $this->observe('DGS10', ['2026-10-14' => 4.0, '2026-10-15' => 4.1]);

        $plain = $this->snapshot('2026-10-15');
        $this->assertSame('2026-09-01', $plain['leading']['permit']['as_of']);
        $this->assertSame(4.1, $plain['rates']['dgs10']['value']);

        $pointInTime = $this->snapshot('2026-10-15', true);
        $this->assertTrue($pointInTime['point_in_time']);
        // 2026-08-01 + 50 d = 2026-09-20 <= 2026-10-15; 2026-09-01 + 50 d = 2026-10-21 is not.
        $this->assertSame(['value' => 1403.0, 'as_of' => '2026-08-01', 'stale' => false], $pointInTime['leading']['permit']);
        $this->assertSame(['value' => 4.0, 'as_of' => '2026-10-14', 'stale' => false], $pointInTime['rates']['dgs10']);
    }

    public function test_the_fed_and_ecb_last_change_and_meetings(): void
    {
        // Fed raise effective 2026-09-17 (decision 2026-09-16).
        $this->observe('DFEDTARL', ['2026-09-15' => 3.50, '2026-09-16' => 3.50, '2026-09-17' => 3.75, '2026-10-21' => 3.75]);
        $this->observe('DFEDTARU', ['2026-09-15' => 3.75, '2026-09-16' => 3.75, '2026-09-17' => 4.00, '2026-10-21' => 4.00]);
        // ECB cut effective 2026-06-17, unchanged since.
        $this->observe('ECBDFR', ['2026-06-10' => 2.75, '2026-06-17' => 2.50, '2026-10-21' => 2.50]);

        $banks = $this->snapshot('2026-10-22')['central_banks'];

        $this->assertSame(['value' => 3.75, 'as_of' => '2026-10-21', 'stale' => false], $banks['fed']['lower']);
        $this->assertSame(['value' => 4.0, 'as_of' => '2026-10-21', 'stale' => false], $banks['fed']['upper']);
        $this->assertSame([
            'direction' => 'raise',
            'from' => ['lower' => 3.5, 'upper' => 3.75],
            'to' => ['lower' => 3.75, 'upper' => 4.0],
            'effective_date' => '2026-09-17',
        ], $banks['fed']['last_change']);
        $this->assertSame(['start' => '2026-09-15', 'decision' => '2026-09-16'], $banks['fed']['last_meeting']);
        $this->assertSame(['start' => '2026-10-27', 'decision' => '2026-10-28'], $banks['fed']['next_meeting']);

        $this->assertSame(['value' => 2.5, 'as_of' => '2026-10-21', 'stale' => false], $banks['ecb']['deposit_rate']);
        $this->assertSame(['direction' => 'cut', 'from' => 2.75, 'to' => 2.5, 'effective_date' => '2026-06-17'], $banks['ecb']['last_change']);
        $this->assertSame(['start' => '2026-09-09', 'decision' => '2026-09-10'], $banks['ecb']['last_meeting']);
        $this->assertSame(['start' => '2026-10-28', 'decision' => '2026-10-29'], $banks['ecb']['next_meeting']);

        // Before the raise the Fed history never changes: no last change.
        $this->assertNull($this->snapshot('2026-09-16')['central_banks']['fed']['last_change']);
    }

    public function test_a_fed_cut_is_reported_with_both_limits(): void
    {
        $this->observe('DFEDTARL', ['2026-07-29' => 4.00, '2026-07-30' => 3.75]);
        $this->observe('DFEDTARU', ['2026-07-29' => 4.25, '2026-07-30' => 4.00]);

        $change = $this->snapshot('2026-08-01')['central_banks']['fed']['last_change'];

        $this->assertSame([
            'direction' => 'cut',
            'from' => ['lower' => 4.0, 'upper' => 4.25],
            'to' => ['lower' => 3.75, 'upper' => 4.0],
            'effective_date' => '2026-07-30',
        ], $change);
    }

    public function test_enabled_restricted_series_give_readings(): void
    {
        config()->set('macro.series.NFCI.enabled', true);
        config()->set('macro.series.VIXCLS.enabled', true);
        $this->observe('NFCI', ['2026-10-16' => -0.48]);
        $this->observe('VIXCLS', ['2026-10-21' => 17.25]);

        $snapshot = $this->snapshot('2026-10-22');

        $this->assertSame(['value' => -0.48, 'as_of' => '2026-10-16', 'stale' => false], $snapshot['leading']['nfci']);
        $this->assertSame(['vix' => ['value' => 17.25, 'as_of' => '2026-10-21', 'stale' => false]], $snapshot['volatility']);
    }
}
