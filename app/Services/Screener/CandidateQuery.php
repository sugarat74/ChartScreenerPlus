<?php

namespace App\Services\Screener;

use App\Models\ChartPattern;
use App\Models\ScreenerRow;
use App\Models\Universe;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ranked Candidate list for a Universe and a set of already-validated
 * filters, read from the precomputed `screener_rows` (screener-precomputed).
 *
 * `$filters` uses the Screener API param names, i.e. the same shape as a
 * Saved Screener's canonical `filters` object: `signal`, `rsi_min`,
 * `rsi_max`, `min_rvol`, `price_above_sma200`, `ma_cross`, `pattern`,
 * `pattern_status` and `sort`. The HTTP controller validates query params into
 * that shape; `fromSaved()` normalizes a stored definition into it.
 *
 * Filtering, ranking (nulls last, ticker tie-break in byte order), counting
 * and paging all happen in SQL, so the cost per request does not grow with
 * the number of instruments. Rows are rebuilt by `ScreenerRowBuilder`.
 *
 * @phpstan-type Filters array{signal: list<string>, rsi_min: ?float, rsi_max: ?float, min_rvol: ?float, price_above_sma200: bool, ma_cross: ?string, pattern: list<string>, pattern_status: string, sort: string}
 */
class CandidateQuery
{
    /**
     * The sortable column and direction for each supported order.
     */
    private const SORTS = [
        'rvol_desc' => ['rvol', 'desc'],
        'rsi_desc' => ['rsi14', 'desc'],
        'rsi_asc' => ['rsi14', 'asc'],
        'change_desc' => ['change_percent', 'desc'],
        'change_asc' => ['change_percent', 'asc'],
        'signal_count_desc' => ['signal_count', 'desc'],
    ];

    /**
     * Every matching Candidate payload, ranked (used by alerts).
     *
     * @param  Filters  $filters
     * @return list<array<string, mixed>>
     */
    public function candidates(Universe $universe, array $filters): array
    {
        return $this->ranked($this->filtered($universe, $filters), $filters['sort'])
            ->get()
            ->map(fn (ScreenerRow $row): array => $this->payload($row))
            ->all();
    }

    /**
     * One page of ranked Candidates plus the total number that matched.
     *
     * @param  Filters  $filters
     * @return array{candidates: list<array<string, mixed>>, total: int}
     */
    public function page(Universe $universe, array $filters, int $limit, int $offset): array
    {
        $query = $this->filtered($universe, $filters);
        $total = (clone $query)->count();

        $candidates = $total === 0 || $offset >= $total
            ? []
            : $this->ranked($query, $filters['sort'])
                ->offset($offset)
                ->limit($limit)
                ->get()
                ->map(fn (ScreenerRow $row): array => $this->payload($row))
                ->all();

        return ['candidates' => $candidates, 'total' => $total];
    }

    /**
     * Normalize a stored Saved Screener definition into validated filters.
     *
     * Lenient by design (stored rows were validated on save): unknown values
     * fall back to "filter off", so an old or odd row can never throw.
     *
     * @param  array<string, mixed>  $stored
     * @return Filters
     */
    public static function fromSaved(array $stored): array
    {
        $number = fn (mixed $value): ?float => is_numeric($value) ? (float) $value : null;
        $list = fn (mixed $value): array => is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
        $patterns = array_values(array_intersect(ChartPattern::TYPES, $list($stored['pattern'] ?? [])));

        return [
            'signal' => $list($stored['signal'] ?? []),
            'rsi_min' => $number($stored['rsi_min'] ?? null),
            'rsi_max' => $number($stored['rsi_max'] ?? null),
            'min_rvol' => $number($stored['min_rvol'] ?? null),
            'price_above_sma200' => ($stored['price_above_sma200'] ?? false) === true,
            'ma_cross' => in_array($stored['ma_cross'] ?? null, ['bullish', 'bearish'], true) ? $stored['ma_cross'] : null,
            'pattern' => $patterns,
            'pattern_status' => $patterns !== [] && in_array($stored['pattern_status'] ?? null, ['forming', 'confirmed'], true)
                ? $stored['pattern_status']
                : 'any',
            'sort' => is_string($stored['sort'] ?? null) ? $stored['sort'] : 'rvol_desc',
        ];
    }

    /**
     * Universe members' rows matching every active filter (AND; the signal
     * and pattern lists are OR). A null indicator never satisfies a numeric
     * filter, because SQL comparisons with NULL are never true.
     *
     * @param  Filters  $filters
     * @return Builder<ScreenerRow>
     */
    private function filtered(Universe $universe, array $filters): Builder
    {
        return ScreenerRow::query()
            ->whereIn('instrument_id', fn ($query) => $query->select('instrument_id')
                ->from('instrument_universe')
                ->where('universe_id', $universe->id))
            ->when($filters['signal'] !== [], fn (Builder $query) => $query->whereExists(
                fn ($exists) => $exists->selectRaw('1')
                    ->from('signals')
                    ->whereColumn('signals.instrument_id', 'screener_rows.instrument_id')
                    ->whereIn('signals.type', $filters['signal'])
            ))
            ->when($filters['pattern'] !== [], fn (Builder $query) => $query->whereExists(
                fn ($exists) => $exists->selectRaw('1')
                    ->from('chart_patterns')
                    ->whereColumn('chart_patterns.instrument_id', 'screener_rows.instrument_id')
                    ->whereIn('chart_patterns.type', $filters['pattern'])
                    ->when($filters['pattern_status'] !== 'any', fn ($status) => $status->where('chart_patterns.status', $filters['pattern_status']))
            ))
            ->when($filters['rsi_min'] !== null, fn (Builder $query) => $query->where('rsi14', '>=', $filters['rsi_min']))
            ->when($filters['rsi_max'] !== null, fn (Builder $query) => $query->where('rsi14', '<=', $filters['rsi_max']))
            ->when($filters['min_rvol'] !== null, fn (Builder $query) => $query->where('rvol', '>=', $filters['min_rvol']))
            ->when($filters['price_above_sma200'], fn (Builder $query) => $query->where('price_above_sma200', true))
            ->when($filters['ma_cross'] !== null, fn (Builder $query) => $query->where('ma_cross', $filters['ma_cross']));
    }

    /**
     * Order by the sort key with nulls last, then by ticker in byte order
     * (PostgreSQL's collation could otherwise ignore punctuation such as `.`).
     *
     * @param  Builder<ScreenerRow>  $query
     * @return Builder<ScreenerRow>
     */
    private function ranked(Builder $query, string $sort): Builder
    {
        [$column, $direction] = self::SORTS[$sort] ?? self::SORTS['rvol_desc'];

        $query->orderByRaw("{$column} is null")->orderBy($column, $direction);

        return $query->getConnection()->getDriverName() === 'pgsql'
            ? $query->orderByRaw('ticker collate "C" asc')
            : $query->orderBy('ticker');
    }

    /**
     * `((close - previous close) / previous close) * 100`, or `null`.
     */
    private function changePercent(float $close, ?float $previousClose): ?float
    {
        if ($previousClose === null || $previousClose === 0.0) {
            return null;
        }

        return (($close - $previousClose) / $previousClose) * 100;
    }

    /**
     * Shape one Candidate for the API (same keys and types as before).
     *
     * @return array<string, mixed>
     */
    private function payload(ScreenerRow $row): array
    {
        return [
            'ticker' => $row->ticker,
            'company' => $row->company,
            'sector' => $row->sector,
            'exchange' => $row->exchange,
            'active' => $row->active,
            'date' => $row->date->format('Y-m-d'),
            'close' => (float) $row->close,
            // Recomputed from the stored decimals (exactly as before) rather
            // than read from the `double` sort column, whose text round-trip
            // can drop the last digits on some drivers (SQLite: 15 digits).
            'change_percent' => $this->changePercent((float) $row->close, $row->previous_close === null ? null : (float) $row->previous_close),
            'rvol' => $row->rvol === null ? null : (float) $row->rvol,
            'rsi14' => $row->rsi14 === null ? null : (float) $row->rsi14,
            'signals' => $row->signals,
            'patterns' => $row->patterns,
        ];
    }
}
