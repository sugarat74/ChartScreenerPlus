<?php

namespace Database\Factories;

use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IndicatorSnapshot>
 */
class IndicatorSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $middle = fake()->randomFloat(4, 10, 1000);
        $upper = round($middle * fake()->randomFloat(4, 1.0, 1.1), 4);
        $lower = round($middle * fake()->randomFloat(4, 0.9, 1.0), 4);
        $macd = fake()->randomFloat(4, -20, 20);

        return [
            'instrument_id' => Instrument::factory(),
            'date' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'sma20' => $middle,
            'sma50' => round($middle * fake()->randomFloat(4, 0.9, 1.1), 4),
            'sma200' => round($middle * fake()->randomFloat(4, 0.8, 1.2), 4),
            'ema21' => $middle,
            'ema55' => round($middle * fake()->randomFloat(4, 0.9, 1.1), 4),
            'rsi14' => fake()->randomFloat(4, 0, 100),
            'adx' => fake()->randomFloat(4, 0, 100),
            'macd' => $macd,
            'macd_signal' => round($macd * fake()->randomFloat(4, -1, 1), 4),
            'macd_hist' => fake()->randomFloat(4, -2, 2),
            'bb_upper' => $upper,
            'bb_middle' => $middle,
            'bb_lower' => $lower,
            'rvol' => fake()->randomFloat(4, 0.1, 5),
        ];
    }
}
