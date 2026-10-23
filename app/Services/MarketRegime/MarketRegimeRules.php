<?php

namespace App\Services\MarketRegime;

use App\Models\MarketRegimeSnapshot;
use App\Models\MarketSectorSnapshot;
use InvalidArgumentException;

/**
 * The fixed rules of the Market Regime (docs/specs/market-regime.md, Domain
 * Rules 1-2 and 5). Pure and static: no database, no configuration.
 *
 * Every threshold is an uncalibrated initial value and the label is
 * descriptive only: never a forecast or advice. Bump RULES_VERSION whenever a
 * threshold or the component set changes, then recompute with `--from`.
 */
final class MarketRegimeRules
{
    /** Version of these rules, stored on every row. */
    public const RULES_VERSION = 1;

    /**
     * The components, in the key order of the stored `components` object.
     * Feature 6 (`options-put-call`) appends `options_sentiment`.
     */
    public const COMPONENTS = [
        'breadth',
        'index_trend',
        'sector_participation',
        'yield_curve',
        'financial_conditions',
        'liquidity',
        'volatility',
    ];

    /** Components read from the market snapshots (the rest are macro readings). */
    public const MARKET_COMPONENTS = ['breadth', 'index_trend', 'sector_participation'];

    /** breadth: % of the main Universe above its SMA200. */
    public const BREADTH_FAVOURABLE_MIN = 60.0;

    public const BREADTH_ADVERSE_MAX = 40.0;

    /** sector_participation: sector ETFs in `uptrend`, out of SECTOR_COUNT. */
    public const SECTOR_COUNT = 11;

    public const SECTORS_FAVOURABLE_MIN = 7;

    public const SECTORS_ADVERSE_MAX = 3;

    /** yield_curve: DGS10 - DGS3MO, percentage points. */
    public const CURVE_FAVOURABLE_MIN = 0.5;

    public const CURVE_INVERTED_BELOW = 0.0;

    /** financial_conditions: Chicago Fed NFCI. */
    public const NFCI_FAVOURABLE_BELOW = -0.25;

    public const NFCI_ADVERSE_ABOVE = 0.0;

    /** liquidity: 13-week % change of net liquidity. */
    public const LIQUIDITY_FAVOURABLE_MIN = 2.0;

    public const LIQUIDITY_ADVERSE_MAX = -2.0;

    /** volatility: VIX close. */
    public const VIX_FAVOURABLE_BELOW = 18.0;

    public const VIX_ADVERSE_ABOVE = 28.0;

    /** Fewer `ok` components than this gives `insufficient`. */
    public const MIN_AVAILABLE = 4;

    public const FAVOURABLE_MIN_SCORE = 3;

    public const ADVERSE_MAX_SCORE = -3;

    /** A market reading older than this many calendar days is stale (the daily limit). */
    public const MARKET_STALE_AFTER_DAYS = 7;

    /**
     * The component's score (-1, 0 or +1) for `$value`. Numeric values are
     * compared after rounding to 4 dp (the stored precision).
     *
     * @throws InvalidArgumentException for an unknown component key
     */
    public static function score(string $key, int|float|string $value): int
    {
        if ($key === 'index_trend') {
            return match ((string) $value) {
                MarketSectorSnapshot::TREND_UP => 1,
                MarketSectorSnapshot::TREND_DOWN => -1,
                default => 0,
            };
        }

        if (! in_array($key, self::COMPONENTS, true)) {
            throw new InvalidArgumentException("Unknown market regime component: {$key}");
        }

        $v = round((float) $value, 4);

        return match ($key) {
            'breadth' => $v >= self::BREADTH_FAVOURABLE_MIN ? 1 : ($v <= self::BREADTH_ADVERSE_MAX ? -1 : 0),
            'sector_participation' => $v >= self::SECTORS_FAVOURABLE_MIN ? 1 : ($v <= self::SECTORS_ADVERSE_MAX ? -1 : 0),
            'yield_curve' => $v >= self::CURVE_FAVOURABLE_MIN ? 1 : ($v < self::CURVE_INVERTED_BELOW ? -1 : 0),
            'financial_conditions' => $v < self::NFCI_FAVOURABLE_BELOW ? 1 : ($v > self::NFCI_ADVERSE_ABOVE ? -1 : 0),
            'liquidity' => $v >= self::LIQUIDITY_FAVOURABLE_MIN ? 1 : ($v <= self::LIQUIDITY_ADVERSE_MAX ? -1 : 0),
            'volatility' => $v < self::VIX_FAVOURABLE_BELOW ? 1 : ($v > self::VIX_ADVERSE_ABOVE ? -1 : 0),
        };
    }

    /**
     * The regime label from the sum of the `ok` scores and their count.
     */
    public static function label(int $sum, int $available): string
    {
        if ($available < self::MIN_AVAILABLE) {
            return MarketRegimeSnapshot::REGIME_INSUFFICIENT;
        }

        if ($sum >= self::FAVOURABLE_MIN_SCORE) {
            return MarketRegimeSnapshot::REGIME_FAVOURABLE;
        }

        return $sum <= self::ADVERSE_MAX_SCORE
            ? MarketRegimeSnapshot::REGIME_ADVERSE
            : MarketRegimeSnapshot::REGIME_NEUTRAL;
    }
}
