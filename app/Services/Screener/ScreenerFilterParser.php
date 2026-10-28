<?php

namespace App\Services\Screener;

use App\Models\ChartPattern;
use App\Models\MarketSectorSnapshot;
use Illuminate\Validation\ValidationException;

/**
 * The single Screener filter vocabulary and its validation rules.
 *
 * Both the public `GET /api/screener` (from the query string) and the
 * natural-language interpreter (`ai-screener-natural-language`, from the AI
 * output) resolve filters here, so an AI answer can never express anything
 * the public API would reject. The enum lists below also build the AI schema.
 *
 * `limit` and `offset` are not filters (they page through the ranked set
 * without changing it) and stay in the controller. Every param here can
 * change which rows are returned or their order, so a bad value throws a
 * `ValidationException` (`422`, `CONSTRAINTS.md` -> Public API).
 */
class ScreenerFilterParser
{
    /**
     * The original nine signal types: the closed vocabulary of the
     * natural-language Screener schema, deliberately not expanded
     * (technical-indicators-expansion D9).
     */
    public const AI_SIGNAL_TYPES = [
        'golden_cross',
        'death_cross',
        'ma_alignment_bullish',
        'ma_alignment_bearish',
        'pivot_breakout_rvol',
        'rsi_overbought',
        'rsi_oversold',
        'macd_bullish_cross',
        'macd_bearish_cross',
    ];

    /**
     * The full fixed signal vocabulary (15) emitted by the engine `RULES`
     * registry and shared by the Screener, Saved Screeners, alerts and the
     * `SignalFactory`.
     */
    public const SIGNAL_TYPES = [
        'golden_cross',
        'death_cross',
        'ma_alignment_bullish',
        'ma_alignment_bearish',
        'pivot_breakout_rvol',
        'rsi_overbought',
        'rsi_oversold',
        'macd_bullish_cross',
        'macd_bearish_cross',
        'adx_trend_strength',
        'bollinger_squeeze',
        'bollinger_close_above_upper',
        'bollinger_close_below_lower',
        'stoch_bullish_cross',
        'stoch_bearish_cross',
    ];

    /**
     * Pattern types accepted by `?pattern` (owned by `ChartPattern`).
     */
    public const PATTERN_TYPES = ChartPattern::TYPES;

    /**
     * Pattern statuses accepted by `?pattern_status` (`any` = both).
     */
    public const PATTERN_STATUSES = ['any', 'forming', 'confirmed'];

    /**
     * Values accepted by `?ma_cross`.
     */
    public const MA_CROSSES = ['bullish', 'bearish'];

    /**
     * The original six sort orders. They are also the closed sort enum of the
     * natural-language Screener schema (`ScreenerQuerySchema`), which this
     * feature deliberately does not expand (screener-table-column-controls).
     */
    public const SORTS = [
        'rvol_desc',
        'rsi_desc',
        'rsi_asc',
        'change_desc',
        'change_asc',
        'signal_count_desc',
    ];

    /**
     * The per-column orders added by `screener-table-column-controls`, so every
     * displayed Candidate column sorts both ways.
     */
    public const COLUMN_SORTS = [
        'company_asc',
        'company_desc',
        'sector_asc',
        'sector_desc',
        'industry_asc',
        'industry_desc',
        'country_asc',
        'country_desc',
        'close_asc',
        'close_desc',
        'rvol_asc',
        'signal_count_asc',
        // screener-technical-filters-api: the technical indicator columns.
        'adx_asc',
        'adx_desc',
        'bb_pctb_asc',
        'bb_pctb_desc',
        'bb_width_asc',
        'bb_width_desc',
        'atr_pct_asc',
        'atr_pct_desc',
        'stoch_k_asc',
        'stoch_k_desc',
    ];

    /**
     * Every order accepted by `?sort` (all with a ticker-ASC tie-break, nulls
     * last in both directions).
     */
    public const ALL_SORTS = [...self::SORTS, ...self::COLUMN_SORTS];

    public const DEFAULT_SORT = 'rvol_desc';

    /**
     * The fixed GICS sector names accepted by `?sector`: the canonical source
     * values stored on Instruments (`database/data/sp500.csv`); the SPA
     * localizes the labels.
     */
    public const SECTORS = [
        'Communication Services',
        'Consumer Discretionary',
        'Consumer Staples',
        'Energy',
        'Financials',
        'Health Care',
        'Industrials',
        'Information Technology',
        'Materials',
        'Real Estate',
        'Utilities',
    ];

    /**
     * Maximum length of `?search` (ticker or company fragment).
     */
    public const SEARCH_MAX_LENGTH = 100;

    /**
     * Maximum length of `?industry` (the stored column is 120 characters).
     */
    public const INDUSTRY_MAX_LENGTH = 120;

    /**
     * Inclusive bounds accepted by every numeric column param.
     *
     * @var array<string, array{float, float}>
     */
    public const NUMERIC_BOUNDS = [
        'rsi_min' => [0.0, 100.0],
        'rsi_max' => [0.0, 100.0],
        'min_rvol' => [0.0, 1000.0],
        'rvol_max' => [0.0, 1000.0],
        'close_min' => [0.0, 1000000.0],
        'close_max' => [0.0, 1000000.0],
        'change_min' => [-100.0, 1000.0],
        'change_max' => [-100.0, 1000.0],
        // screener-technical-filters-api (technical-indicators-expansion D7):
        // %B is 0 on the lower band and 1 on the upper, so it can leave 0..1;
        // bandwidth is a percent of the middle band, ATR a percent of the close.
        'adx_min' => [0.0, 100.0],
        'adx_max' => [0.0, 100.0],
        'bb_pctb_min' => [-1.0, 2.0],
        'bb_pctb_max' => [-1.0, 2.0],
        'bb_width_min' => [0.0, 1000.0],
        'bb_width_max' => [0.0, 1000.0],
        'atr_pct_min' => [0.0, 100.0],
        'atr_pct_max' => [0.0, 100.0],
        'stoch_k_min' => [0.0, 100.0],
        'stoch_k_max' => [0.0, 100.0],
    ];

    /**
     * Each `[min, max]` pair: a min greater than its max is a `422` (on the min
     * key), so an inverted range is rejected instead of silently matching
     * nothing.
     */
    public const RANGE_PAIRS = [
        ['rsi_min', 'rsi_max'],
        ['min_rvol', 'rvol_max'],
        ['close_min', 'close_max'],
        ['change_min', 'change_max'],
        ['adx_min', 'adx_max'],
        ['bb_pctb_min', 'bb_pctb_max'],
        ['bb_width_min', 'bb_width_max'],
        ['atr_pct_min', 'atr_pct_max'],
        ['stoch_k_min', 'stoch_k_max'],
    ];

    /**
     * Values accepted by `?sector_trend` (the `MarketSectorSnapshot` trends):
     * the trend of the stock's sector ETF (market-context-patterns, C5).
     */
    public const SECTOR_TRENDS = [
        MarketSectorSnapshot::TREND_UP,
        MarketSectorSnapshot::TREND_MIXED,
        MarketSectorSnapshot::TREND_DOWN,
    ];

    /**
     * Upper bound of `?sector_rs_rank_max` (the 11 SPDR sectors).
     */
    public const SECTOR_RS_RANK_MAX = 11;

    /**
     * The filters that need the `view-market-context` ability.
     */
    public const MARKET_CONTEXT_KEYS = ['sector_trend', 'sector_rs_rank_max'];

    /**
     * Resolve and validate the filter/sort params from a raw input array
     * (a query string array or decoded JSON).
     *
     * The table column criteria (screener-table-column-controls) are `search`
     * (ticker or company fragment), `sector` (GICS name), `industry`
     * (fragment), `country` (ISO alpha-2) and the inclusive bounds
     * `close_min`/`close_max`, `change_min`/`change_max` and `rvol_max`; RSI
     * and the RVOL minimum keep `rsi_min`/`rsi_max`/`min_rvol`.
     *
     * @param  array<mixed>  $input
     * @return array{signal: list<string>, rsi_min: ?float, rsi_max: ?float, min_rvol: ?float, price_above_sma200: bool, ma_cross: ?string, pattern: list<string>, pattern_status: string, sort: string, sector_trend: ?string, sector_rs_rank_max: ?int, search: ?string, sector: ?string, industry: ?string, country: ?string, rvol_max: ?float, close_min: ?float, close_max: ?float, change_min: ?float, change_max: ?float, adx_min: ?float, adx_max: ?float, bb_pctb_min: ?float, bb_pctb_max: ?float, bb_width_min: ?float, bb_width_max: ?float, atr_pct_min: ?float, atr_pct_max: ?float, stoch_k_min: ?float, stoch_k_max: ?float}
     *
     * @throws ValidationException
     */
    public static function fromArray(array $input): array
    {
        $filters = [
            'signal' => self::signalTypes($input['signal'] ?? null),
            'rsi_min' => self::numeric($input, 'rsi_min'),
            'rsi_max' => self::numeric($input, 'rsi_max'),
            'min_rvol' => self::numeric($input, 'min_rvol'),
            'price_above_sma200' => self::booleanFlag($input, 'price_above_sma200'),
            'ma_cross' => self::maCross($input['ma_cross'] ?? null),
            'pattern' => self::patternTypes($input['pattern'] ?? null),
            'pattern_status' => self::patternStatus($input['pattern_status'] ?? null),
            'sort' => self::sort($input['sort'] ?? null),
            'sector_trend' => self::sectorTrend($input['sector_trend'] ?? null),
            'sector_rs_rank_max' => self::sectorRsRankMax($input['sector_rs_rank_max'] ?? null),
            'search' => self::text($input['search'] ?? null, 'search', self::SEARCH_MAX_LENGTH),
            'sector' => self::sector($input['sector'] ?? null),
            'industry' => self::text($input['industry'] ?? null, 'industry', self::INDUSTRY_MAX_LENGTH),
            'country' => self::country($input['country'] ?? null),
            'rvol_max' => self::numeric($input, 'rvol_max'),
            'close_min' => self::numeric($input, 'close_min'),
            'close_max' => self::numeric($input, 'close_max'),
            'change_min' => self::numeric($input, 'change_min'),
            'change_max' => self::numeric($input, 'change_max'),
            'adx_min' => self::numeric($input, 'adx_min'),
            'adx_max' => self::numeric($input, 'adx_max'),
            'bb_pctb_min' => self::numeric($input, 'bb_pctb_min'),
            'bb_pctb_max' => self::numeric($input, 'bb_pctb_max'),
            'bb_width_min' => self::numeric($input, 'bb_width_min'),
            'bb_width_max' => self::numeric($input, 'bb_width_max'),
            'atr_pct_min' => self::numeric($input, 'atr_pct_min'),
            'atr_pct_max' => self::numeric($input, 'atr_pct_max'),
            'stoch_k_min' => self::numeric($input, 'stoch_k_min'),
            'stoch_k_max' => self::numeric($input, 'stoch_k_max'),
        ];

        foreach (self::RANGE_PAIRS as [$minKey, $maxKey]) {
            if ($filters[$minKey] !== null && $filters[$maxKey] !== null && $filters[$minKey] > $filters[$maxKey]) {
                throw ValidationException::withMessages([
                    $minKey => [__('messages.screener.range_inverted', ['min' => $minKey, 'max' => $maxKey])],
                ]);
            }
        }

        return $filters;
    }

    /**
     * Resolve a free-text fragment (`search`, `industry`): trimmed, inner
     * whitespace collapsed; absent/blank is off. A non-string, invalid UTF-8,
     * a control character or more than `$max` characters is a `422`. The value
     * is only ever bound as a `LIKE` parameter, never used as SQL structure.
     */
    private static function text(mixed $value, string $key, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
            throw ValidationException::withMessages([
                $key => [__('messages.screener.text_invalid', ['parameter' => $key, 'max' => $max])],
            ]);
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([
                $key => [__('messages.screener.text_invalid', ['parameter' => $key, 'max' => $max])],
            ]);
        }

        return $text;
    }

    /**
     * Resolve `sector` to one of the eleven GICS sector names; anything else
     * (an array included) is a `422`.
     */
    private static function sector(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! in_array($value, self::SECTORS, true)) {
            throw ValidationException::withMessages([
                'sector' => [__('messages.screener.sector_invalid')],
            ]);
        }

        return $value;
    }

    /**
     * Resolve `country` to an upper-case ISO 3166-1 alpha-2 code; anything but
     * two ASCII letters is a `422`. An unused code simply matches nothing.
     */
    private static function country(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^[A-Za-z]{2}$/', $value) !== 1) {
            throw ValidationException::withMessages([
                'country' => [__('messages.screener.country_invalid')],
            ]);
        }

        return strtoupper($value);
    }

    /**
     * Whether resolved filters use a sector context key, which needs the
     * `view-market-context` ability (market-context-patterns, C5).
     *
     * @param  array<string, mixed>  $filters
     */
    public static function usesMarketContext(array $filters): bool
    {
        foreach (self::MARKET_CONTEXT_KEYS as $key) {
            if (($filters[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve `sector_trend`; anything but one of the three trends (an array
     * included) is a `422`.
     */
    private static function sectorTrend(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! in_array($value, self::SECTOR_TRENDS, true)) {
            throw ValidationException::withMessages([
                'sector_trend' => [__('messages.screener.sector_trend_invalid')],
            ]);
        }

        return $value;
    }

    /**
     * Resolve `sector_rs_rank_max` to a whole number from 1 to 11; `0`, `12`,
     * `3.5`, `abc`, an array or a bool is a `422`.
     */
    private static function sectorRsRankMax(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $rank = is_string($value) || is_int($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::SECTOR_RS_RANK_MAX]])
            : false;

        if ($rank === false) {
            throw ValidationException::withMessages([
                'sector_rs_rank_max' => [__('messages.screener.sector_rs_rank_max_invalid')],
            ]);
        }

        return $rank;
    }

    /**
     * Resolve `pattern` (comma string and/or array, OR-combined) like `signal`.
     *
     * @return list<string>
     */
    private static function patternTypes(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $types = [];

        foreach (is_array($raw) ? $raw : explode(',', (string) $raw) as $value) {
            if (! is_string($value)) {
                throw ValidationException::withMessages([
                    'pattern' => [__('messages.screener.pattern_invalid')],
                ]);
            }

            $type = trim($value);

            if ($type === '') {
                continue;
            }

            if (! in_array($type, self::PATTERN_TYPES, true)) {
                throw ValidationException::withMessages([
                    'pattern' => [__('messages.screener.pattern_invalid')],
                ]);
            }

            $types[$type] = true;
        }

        return array_keys($types);
    }

    /**
     * Resolve `pattern_status` (`any` by default); it only narrows `pattern`.
     */
    private static function patternStatus(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'any';
        }

        if (! is_string($value) || ! in_array($value, self::PATTERN_STATUSES, true)) {
            throw ValidationException::withMessages([
                'pattern_status' => [__('messages.screener.pattern_status_invalid')],
            ]);
        }

        return $value;
    }

    /**
     * Resolve `signal` from the comma-string and/or array form.
     *
     * Multiple types combine with OR. An unknown type is a `422`; an absent or
     * empty value means the filter is not applied.
     *
     * @return list<string>
     */
    private static function signalTypes(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $values = is_array($raw) ? $raw : explode(',', (string) $raw);
        $types = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw ValidationException::withMessages([
                    'signal' => [__('messages.screener.signal_unknown_types')],
                ]);
            }

            $type = trim($value);

            if ($type === '') {
                continue;
            }

            if (! in_array($type, self::SIGNAL_TYPES, true)) {
                throw ValidationException::withMessages([
                    'signal' => [__('messages.screener.signal_invalid')],
                ]);
            }

            $types[$type] = true;
        }

        return array_keys($types);
    }

    /**
     * Resolve a nullable numeric param; a bad value is a `422`.
     *
     * An absent or empty value means the filter is not applied. A value outside
     * its `NUMERIC_BOUNDS` (inclusive) is a `422`, and so is a min greater than
     * its max (checked in `fromArray()`).
     *
     * @param  array<mixed>  $input
     */
    private static function numeric(array $input, string $key): ?float
    {
        $value = $input[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                $key => [__('messages.screener.parameter_number', ['parameter' => $key])],
            ]);
        }

        $number = (float) $value;
        [$min, $max] = self::NUMERIC_BOUNDS[$key];

        if (! is_finite($number) || $number < $min || $number > $max) {
            throw ValidationException::withMessages([
                $key => [__('messages.screener.parameter_range', ['parameter' => $key, 'min' => self::bound($min), 'max' => self::bound($max)])],
            ]);
        }

        return $number;
    }

    /**
     * A bound for a validation message (`100`, not `100.0`).
     */
    private static function bound(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * Resolve a boolean flag; an unrecognized value is a `422`.
     *
     * Absent/empty means the filter is off.
     *
     * @param  array<mixed>  $input
     */
    private static function booleanFlag(array $input, string $key): bool
    {
        $value = $input[$key] ?? null;

        if ($value === null || $value === '') {
            return false;
        }

        $flag = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($flag === null) {
            throw ValidationException::withMessages([
                $key => [__('messages.screener.parameter_boolean', ['parameter' => $key])],
            ]);
        }

        return $flag;
    }

    /**
     * Resolve `ma_cross` to `bullish`/`bearish`; anything else is a `422`.
     */
    private static function maCross(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! in_array($value, self::MA_CROSSES, true)) {
            throw ValidationException::withMessages([
                'ma_cross' => [__('messages.screener.ma_cross_invalid')],
            ]);
        }

        return $value;
    }

    /**
     * Resolve `sort` to a supported order; anything else is a `422`.
     */
    private static function sort(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_SORT;
        }

        if (! is_string($value) || ! in_array($value, self::ALL_SORTS, true)) {
            throw ValidationException::withMessages([
                'sort' => [__('messages.screener.sort_invalid', ['values' => implode(', ', self::ALL_SORTS)])],
            ]);
        }

        return $value;
    }
}
