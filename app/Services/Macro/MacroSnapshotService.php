<?php

namespace App\Services\Macro;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pure readings derived from the stored Macro Series
 * (docs/specs/macro-series-fred.md, Domain Rule 5). Read-only, no HTTP.
 *
 * Every reading is `{value: ?float, as_of: ?Y-m-d, stale: bool}`: the value
 * rounded to 4 dp, the date of the observation it was computed from and
 * whether that date is older than `stale_after_days` of its anchor series'
 * frequency. A missing input (or a value that cannot be computed) gives
 * `{null, null, false}`; a disabled series gives `null` instead of its
 * reading, and a block whose series are all disabled is `null`.
 *
 * Only observations dated on or before `asOf` count; with `pointInTime`, only
 * those already published by then (`date + release_lag_days <= asOf`), so a
 * past date is read without look-ahead.
 *
 * The stored observations are loaded once per instance.
 */
class MacroSnapshotService
{
    /** @var array<string, array<string, float>>|null code => [Y-m-d => value], ascending */
    private ?array $loaded = null;

    private string $asOf = '';

    /** @var array<string, array<string, mixed>> */
    private array $enabled = [];

    /** @var array<string, array<string, float>> */
    private array $data = [];

    public function __construct(private readonly CentralBankCalendar $calendar) {}

    /** @return array<string, mixed> */
    public function snapshot(string $asOf, bool $pointInTime = false): array
    {
        $this->asOf = Carbon::parse($asOf)->toDateString();
        $this->enabled = MacroSeriesRefresher::enabledSeries();
        $this->data = [];
        $loaded = $this->load();
        foreach ($this->enabled as $code => $config) {
            $cutoff = $pointInTime
                ? Carbon::parse($this->asOf)->subDays(max(0, (int) ($config['release_lag_days'] ?? 0)))->toDateString()
                : $this->asOf;
            $this->data[$code] = array_filter(
                $loaded[$code] ?? [],
                fn (string $date): bool => $date <= $cutoff,
                ARRAY_FILTER_USE_KEY,
            );
        }

        return [
            'as_of' => $this->asOf,
            'point_in_time' => $pointInTime,
            'rates' => $this->rates(),
            'curve' => $this->curve(),
            'liquidity' => $this->liquidity(),
            'leading' => $this->leading(),
            'volatility' => $this->has('VIXCLS') ? ['vix' => $this->latestReading('VIXCLS')] : null,
            'central_banks' => [
                'fed' => $this->fed(),
                'ecb' => $this->ecb(),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function rates(): ?array
    {
        $block = [
            'dgs3mo' => $this->has('DGS3MO') ? $this->latestReading('DGS3MO') : null,
            'dgs2' => $this->has('DGS2') ? $this->latestReading('DGS2') : null,
            'dgs10' => $this->has('DGS10') ? $this->latestReading('DGS10') : null,
            'dgs10_change_20' => null,
        ];
        if ($this->has('DGS10')) {
            $values = array_values($this->data['DGS10']);
            $dates = array_keys($this->data['DGS10']);
            $n = count($values);
            $block['dgs10_change_20'] = $n >= 21
                ? $this->reading($values[$n - 1] - $values[$n - 21], $dates[$n - 1], 'DGS10')
                : $this->missing();
        }

        return self::blockOrNull($block);
    }

    /** @return array<string, mixed>|null */
    private function curve(): ?array
    {
        $block = [
            'spread_10y2y' => $this->has('DGS10') && $this->has('DGS2') ? $this->spread('DGS10', 'DGS2') : null,
            'spread_10y3m' => $this->has('DGS10') && $this->has('DGS3MO') ? $this->spread('DGS10', 'DGS3MO') : null,
        ];
        if (self::blockOrNull($block) === null) {
            return null;
        }
        $spread = $block['spread_10y3m']['value'] ?? null;
        $block['inverted'] = $spread === null ? null : $spread < 0;

        return $block;
    }

    /** `$a − $b` on the latest date where both have an observation. */
    private function spread(string $a, string $b): array
    {
        $date = $this->latestCommonDate($a, $b);

        return $date === null
            ? $this->missing()
            : $this->reading($this->data[$a][$date] - $this->data[$b][$date], $date, $a);
    }

    /** @return array<string, mixed>|null */
    private function liquidity(): ?array
    {
        if (! $this->has('WALCL') || ! $this->has('WDTGAL') || ! $this->has('WLRRAOL')) {
            return null;
        }

        // Latest Wednesday where all three legs were published on that exact date.
        $week = null;
        foreach (array_reverse(array_keys($this->data['WALCL'])) as $date) {
            if ($this->netLiquidity($date) !== null) {
                $week = $date;
                break;
            }
        }
        if ($week === null) {
            return ['net_liquidity' => $this->missing(), 'change_4w_pct' => $this->missing(), 'change_13w_pct' => $this->missing()];
        }

        $net = (float) $this->netLiquidity($week);
        $change = function (int $days) use ($net, $week): array {
            $base = $this->netLiquidity(Carbon::parse($week)->subDays($days)->toDateString());

            return $base !== null && $base > 0
                ? $this->reading(($net / $base - 1) * 100, $week, 'WALCL')
                : $this->missing();
        };

        return [
            'net_liquidity' => $this->reading($net, $week, 'WALCL'),
            'change_4w_pct' => $change(28),
            'change_13w_pct' => $change(91),
        ];
    }

    /** Net liquidity in billions: (WALCL − WDTGAL − WLRRAOL) / 1000, all three in millions on `$date`. */
    private function netLiquidity(string $date): ?float
    {
        $assets = $this->data['WALCL'][$date] ?? null;
        $tga = $this->data['WDTGAL'][$date] ?? null;
        $reverseRepo = $this->data['WLRRAOL'][$date] ?? null;

        return $assets === null || $tga === null || $reverseRepo === null
            ? null
            : ($assets - $tga - $reverseRepo) / 1000;
    }

    /** @return array<string, mixed>|null */
    private function leading(): ?array
    {
        $block = [
            'icsa' => null,
            'icsa_4w' => null,
            'icsa_4w_vs_52w_min_pct' => null,
            'permit' => null,
            'permit_change_3m_pct' => null,
            'nfci' => $this->has('NFCI') ? $this->latestReading('NFCI') : null,
            'oecd_cli' => null,
            'oecd_cli_change_1m' => null,
        ];

        if ($this->has('ICSA')) {
            $values = array_values($this->data['ICSA']);
            $dates = array_keys($this->data['ICSA']);
            $n = count($values);
            $average = fn (int $end): float => array_sum(array_slice($values, $end - 3, 4)) / 4;
            $block['icsa'] = $this->latestReading('ICSA');
            $block['icsa_4w'] = self::consecutiveWeeks($dates, 4)
                ? $this->reading($average($n - 1), $dates[$n - 1], 'ICSA')
                : $this->missing();
            if (self::consecutiveWeeks($dates, 55)) {
                $minimum = min(array_map($average, range($n - 52, $n - 1)));
                $block['icsa_4w_vs_52w_min_pct'] = $minimum > 0
                    ? $this->reading(($average($n - 1) / $minimum - 1) * 100, $dates[$n - 1], 'ICSA')
                    : $this->missing();
            } else {
                $block['icsa_4w_vs_52w_min_pct'] = $this->missing();
            }
        }

        if ($this->has('PERMIT')) {
            $block['permit'] = $this->latestReading('PERMIT');
            $block['permit_change_3m_pct'] = $this->monthlyChange('PERMIT', 3, true);
        }

        if ($this->has('USALOLITOAASTSAM')) {
            $block['oecd_cli'] = $this->latestReading('USALOLITOAASTSAM');
            $block['oecd_cli_change_1m'] = $this->monthlyChange('USALOLITOAASTSAM', 1, false);
        }

        return self::blockOrNull($block);
    }

    /** Latest value vs the value exactly `$months` earlier (month start): % change or difference. */
    private function monthlyChange(string $code, int $months, bool $percent): array
    {
        $date = array_key_last($this->data[$code]);
        if ($date === null) {
            return $this->missing();
        }
        $base = $this->data[$code][Carbon::parse($date)->subMonthsNoOverflow($months)->toDateString()] ?? null;
        $value = $this->data[$code][$date];
        if ($base === null || ($percent && $base == 0)) {
            return $this->missing();
        }

        return $this->reading($percent ? ($value / $base - 1) * 100 : $value - $base, $date, $code);
    }

    /** @return array<string, mixed>|null */
    private function fed(): ?array
    {
        if (! $this->has('DFEDTARL') || ! $this->has('DFEDTARU')) {
            return null;
        }
        $lower = $this->data['DFEDTARL'];
        $upper = $this->data['DFEDTARU'];
        $date = $this->latestCommonDate('DFEDTARU', 'DFEDTARL');

        $change = null;
        $at = $this->lastChangeIndex($upper);
        if ($at !== null) {
            $dates = array_keys($upper);
            $values = array_values($upper);
            $change = [
                'direction' => $values[$at] > $values[$at - 1] ? 'raise' : 'cut',
                'from' => ['lower' => self::round($lower[$dates[$at - 1]] ?? null), 'upper' => self::round($values[$at - 1])],
                'to' => ['lower' => self::round($lower[$dates[$at]] ?? null), 'upper' => self::round($values[$at])],
                'effective_date' => $dates[$at],
            ];
        }

        return [
            'lower' => $date === null ? $this->missing() : $this->reading($lower[$date], $date, 'DFEDTARL'),
            'upper' => $date === null ? $this->missing() : $this->reading($upper[$date], $date, 'DFEDTARU'),
            'last_change' => $change,
            'last_meeting' => $this->calendar->last('fed', $this->asOf),
            'next_meeting' => $this->calendar->next('fed', $this->asOf),
        ];
    }

    /** @return array<string, mixed>|null */
    private function ecb(): ?array
    {
        if (! $this->has('ECBDFR')) {
            return null;
        }
        $rate = $this->data['ECBDFR'];

        $change = null;
        $at = $this->lastChangeIndex($rate);
        if ($at !== null) {
            $dates = array_keys($rate);
            $values = array_values($rate);
            $change = [
                'direction' => $values[$at] > $values[$at - 1] ? 'raise' : 'cut',
                'from' => self::round($values[$at - 1]),
                'to' => self::round($values[$at]),
                'effective_date' => $dates[$at],
            ];
        }

        return [
            'deposit_rate' => $this->latestReading('ECBDFR'),
            'last_change' => $change,
            'last_meeting' => $this->calendar->last('ecb', $this->asOf),
            'next_meeting' => $this->calendar->next('ecb', $this->asOf),
        ];
    }

    /**
     * Index of the most recent observation whose value differs from the
     * previous observation, or null when the stored history never changes.
     *
     * @param  array<string, float>  $series
     */
    private function lastChangeIndex(array $series): ?int
    {
        $values = array_values($series);
        for ($i = count($values) - 1; $i >= 1; $i--) {
            if (self::round($values[$i]) !== self::round($values[$i - 1])) {
                return $i;
            }
        }

        return null;
    }

    private function latestCommonDate(string $a, string $b): ?string
    {
        foreach (array_reverse(array_keys($this->data[$a])) as $date) {
            if (isset($this->data[$b][$date])) {
                return $date;
            }
        }

        return null;
    }

    /**
     * The latest `$count` dates exist and are 7 days apart.
     *
     * @param  list<string>  $dates
     */
    private static function consecutiveWeeks(array $dates, int $count): bool
    {
        if (count($dates) < $count) {
            return false;
        }
        $window = array_slice($dates, -$count);
        for ($i = 1; $i < $count; $i++) {
            if (Carbon::parse($window[$i - 1])->addDays(7)->toDateString() !== $window[$i]) {
                return false;
            }
        }

        return true;
    }

    private function latestReading(string $code): array
    {
        $date = array_key_last($this->data[$code]);

        return $date === null ? $this->missing() : $this->reading($this->data[$code][$date], $date, $code);
    }

    /** @return array{value: ?float, as_of: ?string, stale: bool} */
    private function reading(float $value, string $date, string $anchor): array
    {
        $frequency = (string) ($this->enabled[$anchor]['frequency'] ?? 'daily');
        $threshold = (int) config("macro.stale_after_days.{$frequency}");
        $age = (int) round(Carbon::parse($date)->diffInDays(Carbon::parse($this->asOf)));

        return ['value' => self::round($value), 'as_of' => $date, 'stale' => $age > $threshold];
    }

    /** @return array{value: null, as_of: null, stale: false} */
    private function missing(): array
    {
        return ['value' => null, 'as_of' => null, 'stale' => false];
    }

    private function has(string $code): bool
    {
        return isset($this->enabled[$code]);
    }

    private static function round(?float $value): ?float
    {
        return $value === null ? null : round($value, 4);
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private static function blockOrNull(array $block): ?array
    {
        return array_filter($block, fn (mixed $reading): bool => $reading !== null) === [] ? null : $block;
    }

    /** @return array<string, array<string, float>> */
    private function load(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $rows = DB::table('macro_observations')
            ->join('macro_series', 'macro_series.id', '=', 'macro_observations.macro_series_id')
            ->whereIn('macro_series.code', array_keys((array) config('macro.series')))
            ->orderBy('macro_series.code')
            ->orderBy('macro_observations.date')
            ->get(['macro_series.code', 'macro_observations.date', 'macro_observations.value']);

        $loaded = [];
        foreach ($rows as $row) {
            // SQLite keeps "Y-m-d H:i:s", PostgreSQL "Y-m-d".
            $loaded[(string) $row->code][substr((string) $row->date, 0, 10)] = (float) $row->value;
        }

        return $this->loaded = $loaded;
    }
}
