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
 * The sector context filters (`sector_trend`, `sector_rs_rank_max`,
 * market-context-patterns C5) read the `screener_rows` sector columns; a null
 * column never matches. Callers check the `view-market-context` ability first.
 *
 * The table column criteria (screener-table-column-controls: `search`,
 * `sector`, `industry`, `country`, `rvol_max`, `close_min`/`close_max`,
 * `change_min`/`change_max`) are optional keys: absent means off, so older
 * callers keep working. Every sort maps to a fixed expression in `SORTS`.
 *
 * @phpstan-type Filters array{signal: list<string>, rsi_min: ?float, rsi_max: ?float, min_rvol: ?float, price_above_sma200: bool, ma_cross: ?string, pattern: list<string>, pattern_status: string, sort: string, sector_trend: ?string, sector_rs_rank_max: ?int, search?: ?string, sector?: ?string, industry?: ?string, country?: ?string, rvol_max?: ?float, close_min?: ?float, close_max?: ?float, change_min?: ?float, change_max?: ?float, adx_min?: ?float, adx_max?: ?float, bb_pctb_min?: ?float, bb_pctb_max?: ?float, bb_width_min?: ?float, bb_width_max?: ?float, atr_pct_min?: ?float, atr_pct_max?: ?float, stoch_k_min?: ?float, stoch_k_max?: ?float}
 */
class CandidateQuery
{
    /**
     * The fixed sort expression, direction and text flag for each supported
     * order. Only these constant strings ever reach `orderByRaw`; a request's
     * `sort` value is just a lookup key (validated by `ScreenerFilterParser`).
     * Text orders compare a stable value (lower-cased company or industry, the
     * GICS sector name, the ISO country code) in byte order.
     */
    private const SORTS = [
        'rvol_desc' => ['rvol', 'desc', false],
        'rvol_asc' => ['rvol', 'asc', false],
        'rsi_desc' => ['rsi14', 'desc', false],
        'rsi_asc' => ['rsi14', 'asc', false],
        'change_desc' => ['change_percent', 'desc', false],
        'change_asc' => ['change_percent', 'asc', false],
        'signal_count_desc' => ['signal_count', 'desc', false],
        'signal_count_asc' => ['signal_count', 'asc', false],
        'close_desc' => ['close', 'desc', false],
        'close_asc' => ['close', 'asc', false],
        'company_asc' => ['lower(company)', 'asc', true],
        'company_desc' => ['lower(company)', 'desc', true],
        'sector_asc' => ['sector', 'asc', true],
        'sector_desc' => ['sector', 'desc', true],
        'industry_asc' => ['lower(industry)', 'asc', true],
        'industry_desc' => ['lower(industry)', 'desc', true],
        'country_asc' => ['country', 'asc', true],
        'country_desc' => ['country', 'desc', true],
        'adx_asc' => ['adx', 'asc', false],
        'adx_desc' => ['adx', 'desc', false],
        'bb_pctb_asc' => ['bb_pctb', 'asc', false],
        'bb_pctb_desc' => ['bb_pctb', 'desc', false],
        'bb_width_asc' => ['bb_width', 'asc', false],
        'bb_width_desc' => ['bb_width', 'desc', false],
        'atr_pct_asc' => ['atr_pct', 'asc', false],
        'atr_pct_desc' => ['atr_pct', 'desc', false],
        'stoch_k_asc' => ['stoch_k', 'asc', false],
        'stoch_k_desc' => ['stoch_k', 'desc', false],
    ];

    /**
     * The technical indicator range filters (screener-technical-filters-api):
     * request key => [column, operator]. Inclusive; a null column never
     * matches.
     */
    private const INDICATOR_RANGES = [
        'adx_min' => ['adx', '>='],
        'adx_max' => ['adx', '<='],
        'bb_pctb_min' => ['bb_pctb', '>='],
        'bb_pctb_max' => ['bb_pctb', '<='],
        'bb_width_min' => ['bb_width', '>='],
        'bb_width_max' => ['bb_width', '<='],
        'atr_pct_min' => ['atr_pct', '>='],
        'atr_pct_max' => ['atr_pct', '<='],
        'stoch_k_min' => ['stoch_k', '>='],
        'stoch_k_max' => ['stoch_k', '<='],
    ];

    /**
     * Case-insensitive "contains" predicates over fixed columns; `\` is the
     * explicit LIKE escape on SQLite and PostgreSQL. Only the bound value
     * comes from the request.
     */
    private const CONTAINS_SQL = [
        'ticker' => "lower(ticker) like ? escape '\\'",
        'company' => "lower(company) like ? escape '\\'",
        'industry' => "lower(industry) like ? escape '\\'",
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
            'sector_trend' => in_array($stored['sector_trend'] ?? null, ScreenerFilterParser::SECTOR_TRENDS, true)
                ? $stored['sector_trend']
                : null,
            'sector_rs_rank_max' => is_int($stored['sector_rs_rank_max'] ?? null)
                && $stored['sector_rs_rank_max'] >= 1
                && $stored['sector_rs_rank_max'] <= ScreenerFilterParser::SECTOR_RS_RANK_MAX
                ? $stored['sector_rs_rank_max']
                : null,
            // screener-table-column-controls: absent on rows saved before it.
            'search' => self::storedText($stored['search'] ?? null, ScreenerFilterParser::SEARCH_MAX_LENGTH),
            'sector' => in_array($stored['sector'] ?? null, ScreenerFilterParser::SECTORS, true) ? $stored['sector'] : null,
            'industry' => self::storedText($stored['industry'] ?? null, ScreenerFilterParser::INDUSTRY_MAX_LENGTH),
            'country' => is_string($stored['country'] ?? null) && preg_match('/^[A-Z]{2}$/', $stored['country']) === 1
                ? $stored['country']
                : null,
            'rvol_max' => $number($stored['rvol_max'] ?? null),
            'close_min' => $number($stored['close_min'] ?? null),
            'close_max' => $number($stored['close_max'] ?? null),
            'change_min' => $number($stored['change_min'] ?? null),
            'change_max' => $number($stored['change_max'] ?? null),
            // screener-technical-filters-api: absent on rows saved before it.
            'adx_min' => $number($stored['adx_min'] ?? null),
            'adx_max' => $number($stored['adx_max'] ?? null),
            'bb_pctb_min' => $number($stored['bb_pctb_min'] ?? null),
            'bb_pctb_max' => $number($stored['bb_pctb_max'] ?? null),
            'bb_width_min' => $number($stored['bb_width_min'] ?? null),
            'bb_width_max' => $number($stored['bb_width_max'] ?? null),
            'atr_pct_min' => $number($stored['atr_pct_min'] ?? null),
            'atr_pct_max' => $number($stored['atr_pct_max'] ?? null),
            'stoch_k_min' => $number($stored['stoch_k_min'] ?? null),
            'stoch_k_max' => $number($stored['stoch_k_max'] ?? null),
        ];
    }

    /**
     * A stored text fragment, or `null` when it is not a non-blank string of
     * at most `$max` characters.
     */
    private static function storedText(mixed $value, int $max): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $text = trim($value);

        return $text === '' || mb_strlen($text) > $max ? null : $text;
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
            ->when($filters['ma_cross'] !== null, fn (Builder $query) => $query->where('ma_cross', $filters['ma_cross']))
            ->when(($filters['sector_trend'] ?? null) !== null, fn (Builder $query) => $query->where('sector_trend', $filters['sector_trend']))
            ->when(($filters['sector_rs_rank_max'] ?? null) !== null, fn (Builder $query) => $query->where('sector_rs_rank', '<=', $filters['sector_rs_rank_max']))
            // Table column criteria (screener-table-column-controls). Text
            // fragments are bound LIKE values with escaped wildcards; a null
            // column never matches.
            ->when(($filters['search'] ?? null) !== null, fn (Builder $query) => $query->where(
                fn (Builder $either) => $either
                    ->whereRaw(self::CONTAINS_SQL['ticker'], [self::containsPattern($filters['search'])])
                    ->orWhereRaw(self::CONTAINS_SQL['company'], [self::containsPattern($filters['search'])])
            ))
            ->when(($filters['sector'] ?? null) !== null, fn (Builder $query) => $query->where('sector', $filters['sector']))
            ->when(($filters['industry'] ?? null) !== null, fn (Builder $query) => $query->whereRaw(
                self::CONTAINS_SQL['industry'],
                [self::containsPattern($filters['industry'])]
            ))
            ->when(($filters['country'] ?? null) !== null, fn (Builder $query) => $query->where('country', $filters['country']))
            ->when(($filters['rvol_max'] ?? null) !== null, fn (Builder $query) => $query->where('rvol', '<=', $filters['rvol_max']))
            ->when(($filters['close_min'] ?? null) !== null, fn (Builder $query) => $query->where('close', '>=', $filters['close_min']))
            ->when(($filters['close_max'] ?? null) !== null, fn (Builder $query) => $query->where('close', '<=', $filters['close_max']))
            ->when(($filters['change_min'] ?? null) !== null, fn (Builder $query) => $query->where('change_percent', '>=', $filters['change_min']))
            ->when(($filters['change_max'] ?? null) !== null, fn (Builder $query) => $query->where('change_percent', '<=', $filters['change_max']))
            ->tap(function (Builder $query) use ($filters): void {
                foreach (self::INDICATOR_RANGES as $key => [$column, $operator]) {
                    if (($filters[$key] ?? null) !== null) {
                        $query->where($column, $operator, $filters[$key]);
                    }
                }
            });
    }

    /**
     * `%fragment%` with the LIKE wildcards of the fragment escaped, so `%`,
     * `_` and `\` typed by a user match literally.
     */
    private static function containsPattern(string $fragment): string
    {
        return '%'.addcslashes(mb_strtolower($fragment), '\\%_').'%';
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
        [$expression, $direction, $text] = self::SORTS[$sort] ?? self::SORTS['rvol_desc'];
        $pgsql = $query->getConnection()->getDriverName() === 'pgsql';

        // Plain columns are quoted by the grammar (`close` is an SQL keyword).
        $null = $text ? $expression : $query->getQuery()->getGrammar()->wrap($expression);
        $query->orderByRaw("{$null} is null");

        if ($text) {
            // Byte order on both drivers, like the ticker tie-break.
            $query->orderByRaw($pgsql ? "{$expression} collate \"C\" {$direction}" : "{$expression} {$direction}");
        } else {
            $query->orderBy($expression, $direction);
        }

        return $pgsql
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
            'industry' => $row->industry,
            'country' => $row->country,
            'exchange' => $row->exchange,
            'market' => $row->market,
            'currency' => $row->currency,
            'active' => $row->active,
            'date' => $row->date->format('Y-m-d'),
            'close' => (float) $row->close,
            // Recomputed from the stored decimals (exactly as before) rather
            // than read from the `double` sort column, whose text round-trip
            // can drop the last digits on some drivers (SQLite: 15 digits).
            'change_percent' => $this->changePercent((float) $row->close, $row->previous_close === null ? null : (float) $row->previous_close),
            'rvol' => $row->rvol === null ? null : (float) $row->rvol,
            'rsi14' => $row->rsi14 === null ? null : (float) $row->rsi14,
            'adx' => $row->adx === null ? null : (float) $row->adx,
            'bb_pctb' => $row->bb_pctb === null ? null : (float) $row->bb_pctb,
            'bb_width' => $row->bb_width === null ? null : (float) $row->bb_width,
            'atr_pct' => $row->atr_pct === null ? null : (float) $row->atr_pct,
            'stoch_k' => $row->stoch_k === null ? null : (float) $row->stoch_k,
            'signals' => $row->signals,
            'patterns' => $row->patterns,
        ];
    }
}
