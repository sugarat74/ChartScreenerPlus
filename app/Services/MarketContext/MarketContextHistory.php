<?php

namespace App\Services\MarketContext;

use App\Models\Instrument;
use App\Services\Macro\MacroSeriesRefresher;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Body of `GET /api/market-context/history` (docs/specs/market-context-ui.md,
 * C2): one closed-list metric over the last 1 or 3 years, ascending, values
 * rounded to 4 dp. Read-only. The derived macro metrics use the same formulas
 * as {@see \App\Services\Macro\MacroSnapshotService}, so the last point equals
 * its newest reading for the same data.
 */
class MarketContextHistory
{
    /** The closed metric list, besides `sector_rs_60:{ticker}`. */
    public const METRICS = [
        'regime_score',
        'pct_above_sma200',
        'dgs10',
        'spread_10y3m',
        'net_liquidity',
        'icsa_4w',
        'nfci',
    ];

    public const SECTOR_PREFIX = 'sector_rs_60:';

    public const RANGES = ['1y' => 1, '3y' => 3];

    /** Macro metric => the FRED series it needs (all enabled). */
    private const MACRO_SERIES = [
        'dgs10' => ['DGS10'],
        'spread_10y3m' => ['DGS10', 'DGS3MO'],
        'net_liquidity' => ['WALCL', 'WDTGAL', 'WLRRAOL'],
        'icsa_4w' => ['ICSA'],
        'nfci' => ['NFCI'],
    ];

    public function isValidMetric(string $metric): bool
    {
        if (in_array($metric, self::METRICS, true)) {
            return true;
        }

        if (! str_starts_with($metric, self::SECTOR_PREFIX)) {
            return false;
        }

        return $this->sectorInstrumentId(substr($metric, strlen(self::SECTOR_PREFIX))) !== null;
    }

    public static function isValidRange(string $range): bool
    {
        return array_key_exists($range, self::RANGES);
    }

    /** @return array<string, mixed> */
    public function build(string $metric, string $range): array
    {
        $disabled = isset(self::MACRO_SERIES[$metric]) && ! $this->macroAvailable(self::MACRO_SERIES[$metric]);
        $points = $disabled ? [] : $this->points($metric);

        $body = [
            'metric' => $metric,
            'range' => $range,
            'disabled' => $disabled,
            'from' => null,
            'to' => null,
            'first_date' => null,
            'points' => [],
        ];

        if ($points === []) {
            return $body;
        }

        $to = (string) array_key_last($points);
        $from = Carbon::parse($to)->subYearsNoOverflow(self::RANGES[$range])->toDateString();

        $body['from'] = $from;
        $body['to'] = $to;
        $body['first_date'] = (string) array_key_first($points);

        foreach ($points as $date => $value) {
            if ($date >= $from) {
                $body['points'][] = ['date' => (string) $date, 'value' => $value === null ? null : round($value, 4)];
            }
        }

        return $body;
    }

    /**
     * Every point of `$metric`, `Y-m-d => value` ascending.
     *
     * @return array<string, float|null>
     */
    private function points(string $metric): array
    {
        if (str_starts_with($metric, self::SECTOR_PREFIX)) {
            $id = $this->sectorInstrumentId(substr($metric, strlen(self::SECTOR_PREFIX)));

            return $id === null ? [] : $this->column(
                DB::table('market_sector_snapshots')->where('instrument_id', $id)->whereNotNull('rs_60'),
                'rs_60',
            );
        }

        return match ($metric) {
            // `null` for an insufficient row: a gap in the line, never 0.
            'regime_score' => $this->column(DB::table('market_regime_snapshots'), 'score', keepNull: true),
            'pct_above_sma200' => $this->breadth(),
            'dgs10' => $this->observations('DGS10'),
            'nfci' => $this->observations('NFCI'),
            'spread_10y3m' => $this->spread(),
            'net_liquidity' => $this->netLiquidity(),
            'icsa_4w' => $this->claimsAverage(),
        };
    }

    /** @return array<string, float|null> */
    private function breadth(): array
    {
        $universe = DB::table('universes')->where('slug', (string) config('ingestion.universe'))->value('id');

        return $universe === null ? [] : $this->column(
            DB::table('market_breadth_snapshots')->where('universe_id', $universe)->whereNotNull('pct_above_sma200'),
            'pct_above_sma200',
        );
    }

    /** `DGS10 − DGS3MO` on the dates where both exist. @return array<string, float> */
    private function spread(): array
    {
        $long = $this->observations('DGS10');
        $short = $this->observations('DGS3MO');
        $points = [];

        foreach ($long as $date => $value) {
            if (isset($short[$date])) {
                $points[$date] = $value - $short[$date];
            }
        }

        return $points;
    }

    /** `(WALCL − WDTGAL − WLRRAOL) / 1000` (billions) where all three exist. @return array<string, float> */
    private function netLiquidity(): array
    {
        $assets = $this->observations('WALCL');
        $tga = $this->observations('WDTGAL');
        $reverseRepo = $this->observations('WLRRAOL');
        $points = [];

        foreach ($assets as $date => $value) {
            if (isset($tga[$date], $reverseRepo[$date])) {
                $points[$date] = ($value - $tga[$date] - $reverseRepo[$date]) / 1000;
            }
        }

        return $points;
    }

    /**
     * Mean of the 4 `ICSA` observations ending on each date, only when those
     * 4 dates are consecutive 7-day steps.
     *
     * @return array<string, float>
     */
    private function claimsAverage(): array
    {
        $claims = $this->observations('ICSA');
        $dates = array_keys($claims);
        $values = array_values($claims);
        $points = [];

        for ($i = 3; $i < count($dates); $i++) {
            $consecutive = true;
            for ($j = $i - 2; $j <= $i; $j++) {
                if (Carbon::parse($dates[$j - 1])->addDays(7)->toDateString() !== $dates[$j]) {
                    $consecutive = false;
                    break;
                }
            }

            if ($consecutive) {
                $points[$dates[$i]] = array_sum(array_slice($values, $i - 3, 4)) / 4;
            }
        }

        return $points;
    }

    /** @return array<string, float> */
    private function observations(string $code): array
    {
        $rows = DB::table('macro_observations')
            ->join('macro_series', 'macro_series.id', '=', 'macro_observations.macro_series_id')
            ->where('macro_series.code', $code)
            ->orderBy('macro_observations.date')
            ->get(['macro_observations.date', 'macro_observations.value']);

        $points = [];
        foreach ($rows as $row) {
            // SQLite keeps "Y-m-d H:i:s", PostgreSQL "Y-m-d".
            $points[substr((string) $row->date, 0, 10)] = (float) $row->value;
        }

        return $points;
    }

    /**
     * `$column` by date (ascending) of a snapshot query.
     *
     * @return array<string, float|null>
     */
    private function column(Builder $query, string $column, bool $keepNull = false): array
    {
        $points = [];

        foreach ($query->orderBy('date')->get(['date', $column]) as $row) {
            $value = $row->{$column};

            if ($value === null && ! $keepNull) {
                continue;
            }

            $points[substr((string) $row->date, 0, 10)] = $value === null ? null : (float) $value;
        }

        return $points;
    }

    /** @param  list<string>  $codes */
    private function macroAvailable(array $codes): bool
    {
        if (! config('macro.enabled')) {
            return false;
        }

        $enabled = MacroSeriesRefresher::enabledSeries();

        foreach ($codes as $code) {
            if (! isset($enabled[$code])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Id of a sector ETF of the Market Context Universe (a member with a
     * sector, so never the benchmark), or null.
     */
    private function sectorInstrumentId(string $ticker): ?int
    {
        if ($ticker === '' || $ticker !== strtoupper($ticker)) {
            return null;
        }

        $id = DB::table('instruments')
            ->join('instrument_universe', 'instrument_universe.instrument_id', '=', 'instruments.id')
            ->join('universes', 'universes.id', '=', 'instrument_universe.universe_id')
            ->where('universes.slug', (string) config('market_context.universe'))
            ->where('instruments.ticker', $ticker)
            ->where('instruments.kind', Instrument::KIND_ETF)
            ->whereNotNull('instruments.sector')
            ->value('instruments.id');

        return $id === null ? null : (int) $id;
    }
}
