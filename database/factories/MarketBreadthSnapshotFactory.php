<?php

namespace Database\Factories;

use App\Models\MarketBreadthSnapshot;
use App\Models\MarketSectorSnapshot;
use App\Models\Universe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketBreadthSnapshot>
 */
class MarketBreadthSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $members = fake()->numberBetween(400, 503);
        $advancers = fake()->numberBetween(0, $members);

        return [
            'universe_id' => Universe::factory(),
            'date' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'members' => $members,
            'pct_above_sma50' => fake()->randomFloat(4, 0, 100),
            'pct_above_sma200' => fake()->randomFloat(4, 0, 100),
            'new_highs' => fake()->numberBetween(0, 50),
            'new_lows' => fake()->numberBetween(0, 50),
            'advancers' => $advancers,
            'decliners' => $members - $advancers,
            'spy_trend' => fake()->randomElement([
                MarketSectorSnapshot::TREND_UP,
                MarketSectorSnapshot::TREND_DOWN,
                MarketSectorSnapshot::TREND_MIXED,
            ]),
        ];
    }
}
