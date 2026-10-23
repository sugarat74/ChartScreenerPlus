<?php

namespace App\Services\Screener\Ai;

use App\Services\Screener\ScreenerFilterParser;
use Illuminate\Validation\ValidationException;

/**
 * The `screener-query-v2` JSON Schema sent to the provider and the
 * hand-written validator applied to every answer before it is used.
 *
 * The model can only fill the existing Screener filters (public API names of
 * `GET /api/screener`) plus a short plain-text `explanation` and the
 * `unsupported` parts of the request. Every enum comes from
 * `ScreenerFilterParser`, and the validated filters are run through the same
 * parser as the public API, so an answer can never express a filter the API
 * would reject. Ranges and list sizes are enforced here (not with schema
 * keywords some providers reject). Changing the schema requires bumping
 * `config('ai_screener.schema_version')` and `prompt_version`.
 */
class ScreenerQuerySchema
{
    public const NAME = 'screener_query_v2';

    public const MAX_SIGNALS = 9;

    public const MAX_PATTERNS = 10; // the whole ChartPattern::TYPES vocabulary

    public const MAX_UNSUPPORTED = 5;

    public const MAX_UNSUPPORTED_LENGTH = 120;

    public const MAX_EXPLANATION_LENGTH = 300;

    public const MAX_RSI = 100;

    public const MAX_RVOL = 20;

    private const FILTER_KEYS = [
        'signal', 'rsi_min', 'rsi_max', 'min_rvol', 'price_above_sma200',
        'ma_cross', 'pattern', 'pattern_status', 'sort', 'sector_trend', 'sector_rs_rank_max',
    ];

    private const KEYS = [...self::FILTER_KEYS, 'unsupported', 'explanation'];

    /**
     * Strict JSON Schema: every property required, nullability by type unions
     * and no additional properties.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $nullableNumber = static fn (string $description): array => [
            'type' => ['number', 'null'],
            'description' => $description,
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => self::KEYS,
            'properties' => [
                'signal' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ScreenerFilterParser::SIGNAL_TYPES],
                    'description' => 'Signals on the latest bar, OR-combined. At most 9, no duplicates. Empty when not requested.',
                ],
                'rsi_min' => $nullableNumber('Minimum RSI(14), 0 to 100, or null.'),
                'rsi_max' => $nullableNumber('Maximum RSI(14), 0 to 100, or null.'),
                'min_rvol' => $nullableNumber('Minimum relative volume (RVOL), 0 to 20, or null.'),
                'price_above_sma200' => [
                    'type' => 'boolean',
                    'description' => 'True only when the close must be above the 200-day simple moving average.',
                ],
                'ma_cross' => [
                    'type' => ['string', 'null'],
                    'enum' => [...ScreenerFilterParser::MA_CROSSES, null],
                    'description' => 'bullish = SMA50 above SMA200, bearish = SMA50 below SMA200, or null.',
                ],
                'pattern' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ScreenerFilterParser::PATTERN_TYPES],
                    'description' => 'Chartist patterns, OR-combined. At most 10, no duplicates. Empty when not requested.',
                ],
                'pattern_status' => [
                    'type' => 'string',
                    'enum' => ScreenerFilterParser::PATTERN_STATUSES,
                    'description' => 'Narrows pattern: any, forming or confirmed. Use any when pattern is empty.',
                ],
                'sort' => [
                    'type' => 'string',
                    'enum' => ScreenerFilterParser::SORTS,
                    'description' => 'Ranking order. rvol_desc unless the user asks for another order.',
                ],
                'sector_trend' => [
                    'type' => ['string', 'null'],
                    'enum' => [...ScreenerFilterParser::SECTOR_TRENDS, null],
                    'description' => 'Trend of the stock\'s sector ETF: uptrend, mixed, downtrend, or null.',
                ],
                'sector_rs_rank_max' => [
                    'type' => ['integer', 'null'],
                    'description' => 'Only stocks whose sector is among the N strongest of 11 by relative strength, 1 to 11, or null.',
                ],
                'unsupported' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Short plain-text parts of the request that these filters cannot express. At most 5 items of 120 characters.',
                ],
                'explanation' => [
                    'type' => 'string',
                    'description' => 'One short plain-text sentence describing the applied filters, at most 300 characters.',
                ],
            ],
        ];
    }

    /**
     * Validate a decoded provider answer and resolve its filters through
     * `ScreenerFilterParser`.
     *
     * @param  array<mixed>  $data
     * @return array{filters: array{signal: list<string>, rsi_min: ?float, rsi_max: ?float, min_rvol: ?float, price_above_sma200: bool, ma_cross: ?string, pattern: list<string>, pattern_status: string, sort: string, sector_trend: ?string, sector_rs_rank_max: ?int}, unsupported: list<string>, explanation: string}
     *
     * @throws InvalidScreenerQuery
     */
    public static function validate(array $data): array
    {
        $keys = array_map('strval', array_keys($data));
        $expected = self::KEYS;
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new InvalidScreenerQuery('key set');
        }

        $raw = [
            'signal' => self::enumList($data['signal'], ScreenerFilterParser::SIGNAL_TYPES, self::MAX_SIGNALS),
            'rsi_min' => self::number($data['rsi_min'], self::MAX_RSI),
            'rsi_max' => self::number($data['rsi_max'], self::MAX_RSI),
            'min_rvol' => self::number($data['min_rvol'], self::MAX_RVOL),
            'price_above_sma200' => self::boolean($data['price_above_sma200']),
            'ma_cross' => self::nullableEnum($data['ma_cross'], ScreenerFilterParser::MA_CROSSES),
            'pattern' => self::enumList($data['pattern'], ScreenerFilterParser::PATTERN_TYPES, self::MAX_PATTERNS),
            'pattern_status' => self::enum($data['pattern_status'], ScreenerFilterParser::PATTERN_STATUSES),
            'sort' => self::enum($data['sort'], ScreenerFilterParser::SORTS),
            'sector_trend' => self::nullableEnum($data['sector_trend'], ScreenerFilterParser::SECTOR_TRENDS),
            'sector_rs_rank_max' => self::rank($data['sector_rs_rank_max']),
        ];

        try {
            $filters = ScreenerFilterParser::fromArray($raw);
        } catch (ValidationException) {
            throw new InvalidScreenerQuery('parser');
        }

        return [
            'filters' => $filters,
            'unsupported' => self::textList($data['unsupported']),
            'explanation' => self::text($data['explanation'], self::MAX_EXPLANATION_LENGTH, true),
        ];
    }

    /**
     * Plain text: control characters become spaces, format characters
     * (zero-width, bidi overrides) are removed and whitespace is collapsed.
     * Returns null for a non-string or invalid UTF-8.
     */
    public static function clean(mixed $value): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $value = preg_replace('/\p{Cc}+/u', ' ', $value);
        $value = $value === null ? null : preg_replace('/\p{Cf}+/u', '', $value);
        $value = $value === null ? null : preg_replace('/\s+/u', ' ', $value);

        return $value === null ? null : trim($value);
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function enumList(mixed $value, array $allowed, int $max): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $max) {
            throw new InvalidScreenerQuery('list');
        }

        $seen = [];
        foreach ($value as $item) {
            if (! is_string($item) || ! in_array($item, $allowed, true)) {
                throw new InvalidScreenerQuery('enum item');
            }
            $seen[$item] = true;
        }

        return array_keys($seen);
    }

    private static function number(mixed $value, int $max): ?float
    {
        if ($value === null) {
            return null;
        }
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0 || $value > $max) {
            throw new InvalidScreenerQuery('number');
        }

        return (float) $value;
    }

    /**
     * `null` or an exact integer from 1 to 11 (a float such as 3.0, a string,
     * 0 or 12 is invalid).
     */
    private static function rank(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 1 || $value > ScreenerFilterParser::SECTOR_RS_RANK_MAX) {
            throw new InvalidScreenerQuery('rank');
        }

        return $value;
    }

    private static function boolean(mixed $value): bool
    {
        if (! is_bool($value)) {
            throw new InvalidScreenerQuery('boolean');
        }

        return $value;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function nullableEnum(mixed $value, array $allowed): ?string
    {
        return $value === null ? null : self::enum($value, $allowed);
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function enum(mixed $value, array $allowed): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidScreenerQuery('enum');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function textList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_UNSUPPORTED) {
            throw new InvalidScreenerQuery('unsupported list');
        }

        $items = [];
        foreach ($value as $item) {
            $text = self::text($item, self::MAX_UNSUPPORTED_LENGTH, false);
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return $items;
    }

    private static function text(mixed $value, int $max, bool $required): string
    {
        $text = self::clean($value);
        if ($text === null || mb_strlen($text) > $max || ($required && $text === '')) {
            throw new InvalidScreenerQuery('text');
        }

        return $text;
    }
}
