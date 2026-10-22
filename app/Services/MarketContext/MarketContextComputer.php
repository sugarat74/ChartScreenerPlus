<?php

namespace App\Services\MarketContext;

use App\Models\Instrument;
use App\Models\MarketBreadthSnapshot;
use App\Models\MarketSectorSnapshot;
use App\Models\Universe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Computes the daily Sector Snapshots and the market Breadth Snapshot
 * (docs/specs/market-context-sectors.md, Domain Rules 3-7) from the stored
 * `daily_bars` and `indicator_snapshots`, in Laravel and without the engine,
 * like `screener:refresh`.
 *
 * - Sessions are the dates on which at least half of the main Universe's
 *   stock members have a bar, so a stray bar never creates a Breadth row.
 * - The main Universe is streamed one stock at a time (memory per instrument
 *   only), accumulating per-date counters; then the Market Context ETFs are
 *   read, and both tables are upserted in one transaction.
 * - Every value is rounded to 4 decimals (the stored precision); relative
 *   strength is the difference of the stored returns and the rank compares
 *   stored values, so the table reproduces itself. Empty denominators give
 *   `null`, never 0.
 */
class MarketContextComputer
{
    /** Share of the main Universe's stock members that must have a bar on a session. */
    public const MIN_SESSION_COVERAGE = 0.5;

    /** Return periods, in sessions of the instrument's own bars. */
    public const RETURN_PERIODS = [5, 20, 60];

    /** Relative-strength periods (a subset of RETURN_PERIODS). */
    public const RS_PERIODS = [20, 60];

    /** Previous bars a new high/low is compared with; fewer means not eligible. */
    public const HIGH_LOW_WINDOW = 251;

    /** Sessions recomputed by a default run, so a one-day ETF failure heals itself. */
    public const DEFAULT_LATEST = 5;

    /**
     * The main (stock) Universe, `config('ingestion.universe')`.
     */
    public function mainUniverse(): ?Universe
    {
        return Universe::query()->where('slug', (string) config('ingestion.universe'))->first();
    }

    /**
     * The Market Context Universe, `config('market_context.universe')`.
     */
    public function marketUniverse(): ?Universe
    {
        return Universe::query()->where('slug', (string) config('market_context.universe'))->first();
    }

    /**
     * Every session (`Y-m-d`, ascending), optionally narrowed: `$date` alone
     * gives `[$date]` when it is a session (else `[]`), `$from` every session
     * on or after it, and neither the latest `$latest` sessions.
     *
     * @return list<string>
     */
    public function sessionDates(?string $from = null, ?string $date = null, int $latest = self::DEFAULT_LATEST): array
    {
        $universe = $this->requireMainUniverse();

        $members = $universe->instruments()->stocks()->count();

        if ($members === 0) {
            return [];
        }

        // Integer threshold: `count >= ceil(0.5 * members)` equals
        // `count / members >= 0.5` and binds portably on PostgreSQL.
        $threshold = (int) ceil($members * self::MIN_SESSION_COVERAGE);

        $sessions = DB::table('daily_bars')
            ->join('instrument_universe', 'instrument_universe.instrument_id', '=', 'daily_bars.instrument_id')
            ->join('instruments', 'instruments.id', '=', 'daily_bars.instrument_id')
            ->where('instrument_universe.universe_id', $universe->id)
            ->where('instruments.kind', Instrument::KIND_STOCK)
            ->groupBy('daily_bars.date')
            ->havingRaw('count(*) >= ?', [$threshold])
            ->orderBy('daily_bars.date')
            ->pluck('daily_bars.date')
            ->map(fn (mixed $value): string => $this->day($value))
            ->unique()
            ->values()
            ->all();

        if ($date !== null) {
            return in_array($date, $sessions, true) ? [$date] : [];
        }

        if ($from !== null) {
            return array_values(array_filter($sessions, fn (string $session): bool => $session >= $from));
        }

        return array_slice($sessions, -max(1, $latest));
    }

    /**
     * Compute and upsert both tables for `$dates` (`Y-m-d`). Idempotent.
     *
     * @param  list<string>  $dates
     * @return array{sectors: int, breadth: int, skipped: list<string>}
     */
    public function compute(array $dates): array
    {
        $main = $this->requireMainUniverse();
        $market = $this->marketUniverse();

        if ($market === null) {
            throw new RuntimeException('No universe found for ['.config('market_context.universe').'].');
        }

        $dates = array_values(array_unique($dates));
        sort($dates);

        if ($dates === []) {
            return ['sectors' => 0, 'breadth' => 0, 'skipped' => []];
        }

        $breadth = $this->breadthCounters($main, $dates);
        [$sectorRows, $spyTrend, $skipped] = $this->sectorRows($market, $dates, $breadth['sectors']);

        $now = Carbon::now();
        $breadthRows = [];

        foreach ($dates as $date) {
            $counter = $breadth['dates'][$date];

            $breadthRows[] = [
                'universe_id' => $main->id,
                'date' => Carbon::parse($date)->startOfDay(),
                'members' => $counter['members'],
                'pct_above_sma50' => $this->decimal($this->percent($counter['above50'], $counter['with50'])),
                'pct_above_sma200' => $this->decimal($this->percent($counter['above200'], $counter['with200'])),
                'new_highs' => $counter['eligible'] > 0 ? $counter['highs'] : null,
                'new_lows' => $counter['eligible'] > 0 ? $counter['lows'] : null,
                'advancers' => $counter['advancers'],
                'decliners' => $counter['decliners'],
                'spy_trend' => $spyTrend[$date] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($sectorRows as &$row) {
            $row['date'] = Carbon::parse($row['date'])->startOfDay();
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        DB::transaction(function () use ($breadthRows, $sectorRows): void {
            foreach (array_chunk($breadthRows, 200) as $chunk) {
                MarketBreadthSnapshot::query()->upsert(
                    $chunk,
                    ['universe_id', 'date'],
                    array_values(array_diff(array_keys($chunk[0]), ['universe_id', 'date', 'created_at'])),
                );
            }

            foreach (array_chunk($sectorRows, 200) as $chunk) {
                MarketSectorSnapshot::query()->upsert(
                    $chunk,
                    ['date', 'instrument_id'],
                    array_values(array_diff(array_keys($chunk[0]), ['date', 'instrument_id', 'created_at'])),
                );
            }
        });

        return ['sectors' => count($sectorRows), 'breadth' => count($breadthRows), 'skipped' => $skipped];
    }

    /**
     * Stream the main Universe's stock members and count, per date, the
     * Breadth figures and, per sector, the members above their SMA200.
     *
     * @param  list<string>  $dates
     * @return array{
     *     dates: array<string, array{members: int, with50: int, above50: int, with200: int, above200: int, eligible: int, highs: int, lows: int, advancers: int, decliners: int}>,
     *     sectors: array<string, array<string, array{with200: int, above200: int}>>
     * }
     */
    private function breadthCounters(Universe $universe, array $dates): array
    {
        $empty = ['members' => 0, 'with50' => 0, 'above50' => 0, 'with200' => 0, 'above200' => 0, 'eligible' => 0, 'highs' => 0, 'lows' => 0, 'advancers' => 0, 'decliners' => 0];
        $counters = array_fill_keys($dates, $empty);
        $sectors = [];

        $members = $universe->instruments()
            ->stocks()
            ->orderBy('instruments.id')
            ->get(['instruments.id', 'instruments.sector']);

        foreach ($members as $member) {
            $series = $this->series((int) $member->id);

            if ($series['index'] === []) {
                continue;
            }

            $snapshots = $this->snapshots((int) $member->id);
            $sector = $member->sector;

            foreach ($dates as $date) {
                $k = $series['index'][$date] ?? null;

                if ($k === null) {
                    continue;
                }

                $close = $series['close'][$k];
                $counters[$date]['members']++;

                $snapshot = $snapshots[$date] ?? null;
                $sma50 = $snapshot['sma50'] ?? null;
                $sma200 = $snapshot['sma200'] ?? null;

                if ($sma50 !== null) {
                    $counters[$date]['with50']++;
                    $counters[$date]['above50'] += $close > $sma50 ? 1 : 0;
                }

                if ($sma200 !== null) {
                    $counters[$date]['with200']++;
                    $counters[$date]['above200'] += $close > $sma200 ? 1 : 0;

                    if ($sector !== null && $sector !== '') {
                        $sectors[$sector][$date] ??= ['with200' => 0, 'above200' => 0];
                        $sectors[$sector][$date]['with200']++;
                        $sectors[$sector][$date]['above200'] += $close > $sma200 ? 1 : 0;
                    }
                }

                if ($k >= self::HIGH_LOW_WINDOW) {
                    $counters[$date]['eligible']++;
                    $start = $k - self::HIGH_LOW_WINDOW;
                    $counters[$date]['highs'] += $series['high'][$k] >= max(array_slice($series['high'], $start, self::HIGH_LOW_WINDOW)) ? 1 : 0;
                    $counters[$date]['lows'] += $series['low'][$k] <= min(array_slice($series['low'], $start, self::HIGH_LOW_WINDOW)) ? 1 : 0;
                }

                if ($k >= 1) {
                    $previous = $series['close'][$k - 1];
                    $counters[$date]['advancers'] += $close > $previous ? 1 : 0;
                    $counters[$date]['decliners'] += $close < $previous ? 1 : 0;
                }
            }
        }

        return ['dates' => $counters, 'sectors' => $sectors];
    }

    /**
     * Sector rows for every sector ETF and date, plus SPY's trend per date and
     * the dates whose sector rows were skipped because SPY has no bar.
     *
     * @param  list<string>  $dates
     * @param  array<string, array<string, array{with200: int, above200: int}>>  $sectorBreadth
     * @return array{0: list<array<string, mixed>>, 1: array<string, string|null>, 2: list<string>}
     */
    private function sectorRows(Universe $market, array $dates, array $sectorBreadth): array
    {
        $etfs = $market->instruments()
            ->where('instruments.kind', Instrument::KIND_ETF)
            ->orderBy('instruments.ticker')
            ->get(['instruments.id', 'instruments.ticker', 'instruments.sector']);

        $benchmarkTicker = (string) config('market_context.benchmark');
        $benchmark = $etfs->firstWhere('ticker', $benchmarkTicker);
        $spySeries = $benchmark === null ? null : $this->series((int) $benchmark->id);
        $spySnapshots = $benchmark === null ? [] : $this->snapshots((int) $benchmark->id);

        $spyTrend = [];
        $spyReturns = [];
        $skipped = [];

        foreach ($dates as $date) {
            $k = $spySeries['index'][$date] ?? null;

            if ($spySeries === null || $k === null) {
                $skipped[] = $date;

                continue;
            }

            $spyTrend[$date] = $this->trend($spySeries['close'][$k], $spySnapshots[$date] ?? null);
            $spyReturns[$date] = $this->returns($spySeries['close'], $k);
        }

        $rows = [];

        foreach ($etfs as $etf) {
            if ($etf->ticker === $benchmarkTicker || $etf->sector === null || $etf->sector === '') {
                continue;
            }

            $series = $this->series((int) $etf->id);
            $snapshots = $this->snapshots((int) $etf->id);

            foreach ($dates as $date) {
                $k = $series['index'][$date] ?? null;

                if ($k === null || ! isset($spyReturns[$date])) {
                    continue;
                }

                $returns = $this->returns($series['close'], $k);
                $row = [
                    'date' => $date,
                    'instrument_id' => (int) $etf->id,
                    'ticker' => $etf->ticker,
                    'sector' => $etf->sector,
                ];

                foreach (self::RETURN_PERIODS as $period) {
                    $row["ret_{$period}"] = $returns[$period];
                }

                foreach (self::RS_PERIODS as $period) {
                    $row["rs_{$period}"] = $returns[$period] === null || $spyReturns[$date][$period] === null
                        ? null
                        : $this->round($returns[$period] - $spyReturns[$date][$period]);
                }

                $row['rs_rank'] = null;
                $row['trend'] = $this->trend($series['close'][$k], $snapshots[$date] ?? null);
                $breadth = $sectorBreadth[$etf->sector][$date] ?? ['with200' => 0, 'above200' => 0];
                $row['breadth_200'] = $this->percent($breadth['above200'], $breadth['with200']);

                $rows[] = $row;
            }
        }

        $rows = $this->rank($rows);

        // Rounded floats become fixed 4-decimal strings at the storage boundary.
        $rows = array_map(function (array $row): array {
            unset($row['ticker']);

            foreach (['ret_5', 'ret_20', 'ret_60', 'rs_20', 'rs_60', 'breadth_200'] as $column) {
                $row[$column] = $this->decimal($row[$column]);
            }

            return $row;
        }, $rows);

        return [$rows, $spyTrend, $skipped];
    }

    /**
     * Set `rs_rank` 1..k per date over the rows with a non-null `rs_60`, by
     * the stored `rs_60` descending, ties by ticker ascending.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function rank(array $rows): array
    {
        $byDate = [];

        foreach ($rows as $index => $row) {
            if ($row['rs_60'] !== null) {
                $byDate[$row['date']][] = $index;
            }
        }

        foreach ($byDate as $indexes) {
            usort($indexes, fn (int $a, int $b): int => [$rows[$b]['rs_60'], $rows[$a]['ticker']] <=> [$rows[$a]['rs_60'], $rows[$b]['ticker']]);

            foreach ($indexes as $position => $index) {
                $rows[$index]['rs_rank'] = $position + 1;
            }
        }

        return $rows;
    }

    /**
     * `ret_n` for every return period at bar `$k`, rounded; `null` with fewer
     * than `n + 1` bars.
     *
     * @param  list<float>  $closes
     * @return array<int, float|null>
     */
    private function returns(array $closes, int $k): array
    {
        $returns = [];

        foreach (self::RETURN_PERIODS as $period) {
            $base = $k >= $period ? $closes[$k - $period] : null;
            $returns[$period] = $base === null || $base == 0.0
                ? null
                : $this->round(($closes[$k] / $base - 1) * 100);
        }

        return $returns;
    }

    /**
     * `uptrend` when close > sma50 > sma200, `downtrend` when close < sma50 <
     * sma200, else `mixed`; `null` without a snapshot or either SMA.
     *
     * @param  array{sma50: float|null, sma200: float|null}|null  $snapshot
     */
    private function trend(float $close, ?array $snapshot): ?string
    {
        $sma50 = $snapshot['sma50'] ?? null;
        $sma200 = $snapshot['sma200'] ?? null;

        if ($sma50 === null || $sma200 === null) {
            return null;
        }

        return match (true) {
            $close > $sma50 && $sma50 > $sma200 => MarketSectorSnapshot::TREND_UP,
            $close < $sma50 && $sma50 < $sma200 => MarketSectorSnapshot::TREND_DOWN,
            default => MarketSectorSnapshot::TREND_MIXED,
        };
    }

    /**
     * One instrument's bars ordered by date: positional `high`, `low`,
     * `close` and the `Y-m-d => position` index.
     *
     * @return array{index: array<string, int>, high: list<float>, low: list<float>, close: list<float>}
     */
    private function series(int $instrumentId): array
    {
        $series = ['index' => [], 'high' => [], 'low' => [], 'close' => []];

        $bars = DB::table('daily_bars')
            ->where('instrument_id', $instrumentId)
            ->orderBy('date')
            ->get(['date', 'high', 'low', 'close']);

        foreach ($bars as $position => $bar) {
            $series['index'][$this->day($bar->date)] = $position;
            $series['high'][] = (float) $bar->high;
            $series['low'][] = (float) $bar->low;
            $series['close'][] = (float) $bar->close;
        }

        return $series;
    }

    /**
     * One instrument's SMA50/SMA200 per `Y-m-d`.
     *
     * @return array<string, array{sma50: float|null, sma200: float|null}>
     */
    private function snapshots(int $instrumentId): array
    {
        $snapshots = [];

        DB::table('indicator_snapshots')
            ->where('instrument_id', $instrumentId)
            ->orderBy('date')
            ->get(['date', 'sma50', 'sma200'])
            ->each(function (object $row) use (&$snapshots): void {
                $snapshots[$this->day($row->date)] = [
                    'sma50' => $row->sma50 === null ? null : (float) $row->sma50,
                    'sma200' => $row->sma200 === null ? null : (float) $row->sma200,
                ];
            });

        return $snapshots;
    }

    private function requireMainUniverse(): Universe
    {
        $universe = $this->mainUniverse();

        if ($universe === null) {
            throw new RuntimeException('No universe found for ['.config('ingestion.universe').'].');
        }

        return $universe;
    }

    /**
     * `part / whole * 100`, rounded; `null` when `whole` is 0.
     */
    private function percent(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : $this->round($part / $whole * 100);
    }

    /**
     * Round to the stored precision (4 decimals), never `-0`.
     */
    private function round(float $value): float
    {
        return round($value, 4) + 0.0;
    }

    /**
     * A rounded value as a fixed 4-decimal string for a `decimal` column.
     */
    private function decimal(?float $value): ?string
    {
        return $value === null ? null : sprintf('%.4F', $value);
    }

    /**
     * A stored date as `Y-m-d` (SQLite returns `Y-m-d H:i:s`, PostgreSQL `Y-m-d`).
     */
    private function day(mixed $value): string
    {
        return substr((string) $value, 0, 10);
    }
}
