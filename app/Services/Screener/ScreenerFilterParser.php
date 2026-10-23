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
     * The fixed signal vocabulary owned by `signals-detect` / `SignalFactory`.
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
     * The supported sort orders (all with a ticker-ASC tie-break, nulls last).
     */
    public const SORTS = [
        'rvol_desc',
        'rsi_desc',
        'rsi_asc',
        'change_desc',
        'change_asc',
        'signal_count_desc',
    ];

    public const DEFAULT_SORT = 'rvol_desc';

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
     * @param  array<mixed>  $input
     * @return array{signal: list<string>, rsi_min: ?float, rsi_max: ?float, min_rvol: ?float, price_above_sma200: bool, ma_cross: ?string, pattern: list<string>, pattern_status: string, sort: string, sector_trend: ?string, sector_rs_rank_max: ?int}
     *
     * @throws ValidationException
     */
    public static function fromArray(array $input): array
    {
        return [
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
        ];
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
     * An absent or empty value means the filter is not applied. An explicit
     * `rsi_min` greater than `rsi_max` is valid and simply matches nothing.
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

        return (float) $value;
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

        if (! is_string($value) || ! in_array($value, self::SORTS, true)) {
            throw ValidationException::withMessages([
                'sort' => [__('messages.screener.sort_invalid', ['values' => implode(', ', self::SORTS)])],
            ]);
        }

        return $value;
    }
}
