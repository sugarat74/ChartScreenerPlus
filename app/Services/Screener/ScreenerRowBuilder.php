<?php

namespace App\Services\Screener;

use App\Models\ChartPattern;
use App\Models\Instrument;
use App\Models\ScreenerRow;
use App\Services\MarketRegime\MarketRegimeRules;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds `screener_rows` from the stored market data (screener-precomputed).
 *
 * Every stock Instrument with a latest Daily Bar and a latest Indicator Snapshot
 * gets one row holding exactly what the Candidate payload and the SQL filters
 * need; instruments that no longer qualify lose their row. ETFs (`kind = etf`,
 * the Market Context universe) never get a row: this builder is not
 * universe-scoped, so the `kind` filter here is the Screener's real gate. The values are computed
 * with the same rules the Screener used per request (previous close = second
 * latest bar by date, change % in PHP, nulls kept), so results do not change.
 * Run after every data update: `ingestion:pipeline`, `RunIngestionJob`, deploy,
 * and after a manual `market-context:compute` (the sector context columns).
 *
 * Sector context (docs/specs/market-context-patterns.md, C4): each row gets the
 * trend and relative-strength rank of its sector ETF from the latest Sector
 * Snapshot date, matched by the stored GICS sector name, or nulls when the
 * stock has no (or an unmapped) sector or that snapshot is more than
 * MARKET_STALE_AFTER_DAYS older than the row's own bar. Measured against the
 * bar date, not today, so the build stays deterministic.
 */
class ScreenerRowBuilder
{
    private const CHUNK = 500;

    /**
     * Rebuild every row; returns how many instruments have a row.
     */
    public function refresh(): int
    {
        $now = Carbon::now();
        $rows = [];
        $sectors = $this->latestSectorContext();

        Instrument::query()
            ->stocks()
            ->with(['latestBar', 'latestSnapshot', 'signals', 'chartPatterns'])
            ->chunkById(self::CHUNK, function (EloquentCollection $instruments) use (&$rows, $now, $sectors): void {
                $members = $instruments->filter(fn (Instrument $instrument): bool => $instrument->latestBar !== null
                    && $instrument->latestSnapshot !== null);
                $previousClose = $this->previousCloseByInstrument($members->modelKeys());

                foreach ($members as $instrument) {
                    $rows[] = $this->row($instrument, $previousClose->get($instrument->id), $now, $sectors);
                }
            });

        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                ScreenerRow::query()->upsert(
                    $chunk,
                    ['instrument_id'],
                    array_values(array_diff(array_keys($chunk[0]), ['instrument_id', 'created_at'])),
                );
            }

            // Rows of instruments that no longer qualify (no bar or no
            // snapshot any more, or not a stock) go; deleted instruments
            // cascade by FK. The rule is a correlated check, so it never
            // binds every id.
            ScreenerRow::query()
                ->where(fn ($query) => $query
                    ->whereNotExists(fn ($bars) => $bars->selectRaw('1')->from('daily_bars')
                        ->whereColumn('daily_bars.instrument_id', 'screener_rows.instrument_id'))
                    ->orWhereNotExists(fn ($snapshots) => $snapshots->selectRaw('1')->from('indicator_snapshots')
                        ->whereColumn('indicator_snapshots.instrument_id', 'screener_rows.instrument_id'))
                    ->orWhereExists(fn ($instruments) => $instruments->selectRaw('1')->from('instruments')
                        ->whereColumn('instruments.id', 'screener_rows.instrument_id')
                        ->where('instruments.kind', '!=', Instrument::KIND_STOCK)))
                ->delete();
        });

        return count($rows);
    }

    /**
     * The `screener_rows` attributes for one qualifying instrument.
     *
     * @param  array{date: ?string, rows: array<string, array{trend: ?string, rs_rank: ?int}>}  $sectors
     * @return array<string, mixed>
     */
    private function row(Instrument $instrument, ?float $previousClose, Carbon $now, array $sectors): array
    {
        $bar = $instrument->latestBar;
        $snapshot = $instrument->latestSnapshot;
        $close = (float) $bar->close;
        $sma50 = $this->nullableFloat($snapshot->sma50);
        $sma200 = $this->nullableFloat($snapshot->sma200);
        $signals = $instrument->signals->pluck('type')->unique()->sort()->values()->all();
        $sector = $this->sectorContext($instrument, $bar->date->format('Y-m-d'), $sectors);

        return [
            'instrument_id' => $instrument->id,
            'ticker' => $instrument->ticker,
            'company' => $instrument->company,
            'sector' => $instrument->sector,
            'industry' => $instrument->industry,
            'country' => $instrument->country,
            'exchange' => $instrument->exchange,
            'active' => (bool) $instrument->active,
            'date' => $bar->date->format('Y-m-d'),
            'close' => $bar->close,
            'previous_close' => $previousClose,
            // Bound as a 17-significant-digit string: Laravel binds PHP floats
            // as strings with `precision=14`, which would collapse distinct
            // values (10.000000000000009 vs 9.999999999999993) into ties and
            // change the ranking against the per-request computation.
            'change_percent' => $this->exact($this->changePercent($close, $previousClose)),
            'rvol' => $snapshot->rvol,
            'rsi14' => $snapshot->rsi14,
            'sma50' => $snapshot->sma50,
            'sma200' => $snapshot->sma200,
            'price_above_sma200' => $sma200 !== null && $close > $sma200,
            'ma_cross' => match (true) {
                $sma50 === null || $sma200 === null => null,
                $sma50 > $sma200 => 'bullish',
                $sma50 < $sma200 => 'bearish',
                default => null,
            },
            'signal_count' => count($signals),
            'signals' => json_encode($signals),
            'patterns' => json_encode($instrument->chartPatterns
                ->sortBy('type')
                ->values()
                ->map(fn (ChartPattern $pattern): array => [
                    'type' => $pattern->type,
                    'status' => $pattern->status,
                    'breakout_level' => $pattern->breakout_level,
                    'end_date' => $pattern->end_date->format('Y-m-d'),
                ])
                ->all(), JSON_PRESERVE_ZERO_FRACTION),
            'sector_trend' => $sector['trend'] ?? null,
            'sector_rs_rank' => $sector['rs_rank'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * The rows of the latest Sector Snapshot date keyed by stored GICS sector.
     *
     * @return array{date: ?string, rows: array<string, array{trend: ?string, rs_rank: ?int}>}
     */
    private function latestSectorContext(): array
    {
        // The raw stored value: SQLite keeps "Y-m-d H:i:s", PostgreSQL "Y-m-d".
        $latest = DB::table('market_sector_snapshots')->max('date');

        if ($latest === null) {
            return ['date' => null, 'rows' => []];
        }

        $rows = [];

        DB::table('market_sector_snapshots')
            ->where('date', $latest)
            ->whereNotNull('sector')
            ->orderBy('instrument_id')
            ->get(['sector', 'trend', 'rs_rank'])
            ->each(function (object $row) use (&$rows): void {
                $rows[(string) $row->sector] ??= [
                    'trend' => $row->trend === null ? null : (string) $row->trend,
                    'rs_rank' => $row->rs_rank === null ? null : (int) $row->rs_rank,
                ];
            });

        return ['date' => substr((string) $latest, 0, 10), 'rows' => $rows];
    }

    /**
     * The stock's sector context, or null when it has no (or an unmapped)
     * sector or the snapshot is more than 7 days older than the bar.
     *
     * @param  array{date: ?string, rows: array<string, array{trend: ?string, rs_rank: ?int}>}  $sectors
     * @return array{trend: ?string, rs_rank: ?int}|null
     */
    private function sectorContext(Instrument $instrument, string $barDate, array $sectors): ?array
    {
        $context = $instrument->sector === null ? null : ($sectors['rows'][$instrument->sector] ?? null);

        if ($context === null || $sectors['date'] === null) {
            return null;
        }

        // Age of the snapshot on the bar date; a snapshot after the bar is fresh.
        $age = (int) round(Carbon::parse($sectors['date'])->diffInDays(Carbon::parse($barDate), false));

        return $age > MarketRegimeRules::MARKET_STALE_AFTER_DAYS ? null : $context;
    }

    /**
     * Build the `instrument_id => previous close` map in one bounded query.
     *
     * The previous close is the close of each instrument's second most recent
     * bar by date, read with one correlated subquery per instrument
     * (`order by date desc limit 1 offset 1`) on the unique
     * `(instrument_id, date)` index, so the cost grows with the number of
     * instruments, not with their bar history. An instrument with fewer than
     * two bars is absent from the map.
     *
     * @param  list<int>  $instrumentIds
     * @return Collection<int, float>
     */
    private function previousCloseByInstrument(array $instrumentIds): Collection
    {
        if ($instrumentIds === []) {
            return collect();
        }

        return DB::table('instruments as i')
            ->whereIn('i.id', $instrumentIds)
            ->select('i.id')
            ->selectSub(
                fn ($query) => $query->from('daily_bars as b')
                    ->select('b.close')
                    ->whereColumn('b.instrument_id', 'i.id')
                    ->orderByDesc('b.date')
                    ->offset(1)
                    ->limit(1),
                'previous_close',
            )
            ->get()
            ->filter(fn (object $row): bool => $row->previous_close !== null)
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => (float) $row->previous_close]);
    }

    /**
     * `((latest close - previous close) / previous close) * 100`, or `null`.
     */
    private function changePercent(float $close, ?float $previousClose): ?float
    {
        if ($previousClose === null || $previousClose === 0.0) {
            return null;
        }

        return (($close - $previousClose) / $previousClose) * 100;
    }

    /**
     * A float as a string that round-trips exactly (17 significant digits).
     */
    private function exact(?float $value): ?string
    {
        return $value === null ? null : sprintf('%.17H', $value);
    }

    /**
     * Convert a nullable `decimal:4` value to a float or `null`.
     */
    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
