<?php

namespace Database\Factories;

use App\Models\DailyBar;
use App\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyBar>
 */
class DailyBarFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $close = fake()->randomFloat(4, 10, 1000);
        $open = round($close * fake()->randomFloat(4, 0.98, 1.02), 4);
        $high = round(max($open, $close) * fake()->randomFloat(4, 1.0, 1.03), 4);
        $low = round(min($open, $close) * fake()->randomFloat(4, 0.97, 1.0), 4);

        return [
            'instrument_id' => Instrument::factory(),
            'date' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => fake()->numberBetween(100_000, 500_000_000),
        ];
    }
}
