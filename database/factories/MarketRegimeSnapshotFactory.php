<?php

namespace Database\Factories;

use App\Models\MarketRegimeSnapshot;
use App\Models\MarketSectorSnapshot;
use App\Services\MarketRegime\MarketRegimeRules;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketRegimeSnapshot>
 */
class MarketRegimeSnapshotFactory extends Factory
{
    /**
     * Define the model's default state: a `neutral` row whose seven
     * components are all `ok` with a zero score.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d');
        $values = [
            'breadth' => 50.0,
            'index_trend' => MarketSectorSnapshot::TREND_MIXED,
            'sector_participation' => 5,
            'yield_curve' => 0.25,
            'financial_conditions' => -0.1,
            'liquidity' => 0.5,
            'volatility' => 22.0,
        ];

        $components = [];
        foreach (MarketRegimeRules::COMPONENTS as $key) {
            $components[$key] = [
                'status' => MarketRegimeSnapshot::STATUS_OK,
                'score' => 0,
                'value' => $values[$key],
                'as_of' => $date,
                'stale' => false,
            ];
        }

        return [
            'date' => $date,
            'regime' => MarketRegimeSnapshot::REGIME_NEUTRAL,
            'score' => 0,
            'available' => count($components),
            'components' => $components,
            'rules_version' => MarketRegimeRules::RULES_VERSION,
        ];
    }
}
