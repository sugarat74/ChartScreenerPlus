<?php

namespace Database\Factories;

use App\Models\Instrument;
use App\Models\MarketSectorSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketSectorSnapshot>
 */
class MarketSectorSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ret20 = fake()->randomFloat(4, -10, 10);
        $ret60 = fake()->randomFloat(4, -20, 20);

        return [
            'date' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'instrument_id' => Instrument::factory()->etf(),
            'sector' => 'Information Technology',
            'ret_5' => fake()->randomFloat(4, -5, 5),
            'ret_20' => $ret20,
            'ret_60' => $ret60,
            'rs_20' => fake()->randomFloat(4, -5, 5),
            'rs_60' => fake()->randomFloat(4, -10, 10),
            'rs_rank' => fake()->numberBetween(1, 11),
            'trend' => fake()->randomElement([
                MarketSectorSnapshot::TREND_UP,
                MarketSectorSnapshot::TREND_DOWN,
                MarketSectorSnapshot::TREND_MIXED,
            ]),
            'breadth_200' => fake()->randomFloat(4, 0, 100),
        ];
    }
}
