<?php

namespace Tests\Unit;

use App\Models\MarketRegimeSnapshot;
use App\Services\MarketRegime\MarketRegimeRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fixed Market Regime rules (docs/specs/market-regime.md, Domain Rules
 * 1-2): every threshold at, just inside and just outside its boundary, with
 * the exact operator of the spec table, and the label boundaries.
 */
class MarketRegimeRulesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int|float|string, 2: int}>
     */
    public static function scores(): array
    {
        return [
            // breadth: >= 60 -> +1, <= 40 -> -1.
            'breadth 60 is favourable' => ['breadth', 60.0, 1],
            'breadth 59.9999 is neutral' => ['breadth', 59.9999, 0],
            'breadth 40 is adverse' => ['breadth', 40.0, -1],
            'breadth 40.0001 is neutral' => ['breadth', 40.0001, 0],
            // index_trend: uptrend +1, downtrend -1, mixed 0.
            'trend up' => ['index_trend', 'uptrend', 1],
            'trend mixed' => ['index_trend', 'mixed', 0],
            'trend down' => ['index_trend', 'downtrend', -1],
            // sector_participation: >= 7 -> +1, <= 3 -> -1.
            'sectors 7 is favourable' => ['sector_participation', 7, 1],
            'sectors 6 is neutral' => ['sector_participation', 6, 0],
            'sectors 4 is neutral' => ['sector_participation', 4, 0],
            'sectors 3 is adverse' => ['sector_participation', 3, -1],
            // yield_curve: >= 0.5 -> +1, < 0 -> -1 (0 itself is neutral).
            'curve 0.5 is favourable' => ['yield_curve', 0.5, 1],
            'curve 0.4999 is neutral' => ['yield_curve', 0.4999, 0],
            'curve 0 is neutral' => ['yield_curve', 0.0, 0],
            'curve -0.0001 is inverted' => ['yield_curve', -0.0001, -1],
            // financial_conditions (NFCI): < -0.25 -> +1, > 0 -> -1.
            'nfci -0.2501 is favourable' => ['financial_conditions', -0.2501, 1],
            'nfci -0.25 is neutral' => ['financial_conditions', -0.25, 0],
            'nfci 0 is neutral' => ['financial_conditions', 0.0, 0],
            'nfci 0.0001 is adverse' => ['financial_conditions', 0.0001, -1],
            // liquidity (13-week %): >= 2 -> +1, <= -2 -> -1.
            'liquidity 2 is favourable' => ['liquidity', 2.0, 1],
            'liquidity 1.9999 is neutral' => ['liquidity', 1.9999, 0],
            'liquidity -1.9999 is neutral' => ['liquidity', -1.9999, 0],
            'liquidity -2 is adverse' => ['liquidity', -2.0, -1],
            // volatility (VIX): < 18 -> +1, > 28 -> -1.
            'vix 17.9999 is favourable' => ['volatility', 17.9999, 1],
            'vix 18 is neutral' => ['volatility', 18.0, 0],
            'vix 28 is neutral' => ['volatility', 28.0, 0],
            'vix 28.0001 is adverse' => ['volatility', 28.0001, -1],
            // Compared after rounding to 4 dp (the stored precision).
            'breadth 59.99996 rounds to 60' => ['breadth', 59.99996, 1],
            'curve -0.00004 rounds to 0' => ['yield_curve', -0.00004, 0],
        ];
    }

    #[DataProvider('scores')]
    public function test_each_component_scores_with_the_exact_operator(string $key, int|float|string $value, int $expected): void
    {
        $this->assertSame($expected, MarketRegimeRules::score($key, $value));
    }

    public function test_an_unknown_component_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MarketRegimeRules::score('options_sentiment', 50.0);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: string}>
     */
    public static function labels(): array
    {
        return [
            '+3 of 4 is favourable' => [3, 4, MarketRegimeSnapshot::REGIME_FAVOURABLE],
            '+2 of 4 is neutral' => [2, 4, MarketRegimeSnapshot::REGIME_NEUTRAL],
            '-3 of 4 is adverse' => [-3, 4, MarketRegimeSnapshot::REGIME_ADVERSE],
            '-2 of 5 is neutral' => [-2, 5, MarketRegimeSnapshot::REGIME_NEUTRAL],
            '+3 of 3 is insufficient' => [3, 3, MarketRegimeSnapshot::REGIME_INSUFFICIENT],
            '-4 of 3 is insufficient' => [-4, 3, MarketRegimeSnapshot::REGIME_INSUFFICIENT],
            '0 of 0 is insufficient' => [0, 0, MarketRegimeSnapshot::REGIME_INSUFFICIENT],
            '+7 of 7 is favourable' => [7, 7, MarketRegimeSnapshot::REGIME_FAVOURABLE],
        ];
    }

    #[DataProvider('labels')]
    public function test_the_label_needs_four_available_components_and_three_points(int $sum, int $available, string $expected): void
    {
        $this->assertSame($expected, MarketRegimeRules::label($sum, $available));
    }

    public function test_the_constants_match_the_spec(): void
    {
        $this->assertSame(1, MarketRegimeRules::RULES_VERSION);
        $this->assertSame(
            ['breadth', 'index_trend', 'sector_participation', 'yield_curve', 'financial_conditions', 'liquidity', 'volatility'],
            MarketRegimeRules::COMPONENTS,
        );
        $this->assertSame(4, MarketRegimeRules::MIN_AVAILABLE);
        $this->assertSame(3, MarketRegimeRules::FAVOURABLE_MIN_SCORE);
        $this->assertSame(-3, MarketRegimeRules::ADVERSE_MAX_SCORE);
        $this->assertSame(11, MarketRegimeRules::SECTOR_COUNT);
        $this->assertSame(7, MarketRegimeRules::MARKET_STALE_AFTER_DAYS);
    }
}
