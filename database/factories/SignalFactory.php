<?php

namespace Database\Factories;

use App\Models\Instrument;
use App\Models\Signal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Signal>
 */
class SignalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'instrument_id' => Instrument::factory(),
            'date' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'type' => fake()->randomElement([
                'golden_cross',
                'death_cross',
                'ma_alignment_bullish',
                'ma_alignment_bearish',
                'pivot_breakout_rvol',
                'rsi_overbought',
                'rsi_oversold',
                'macd_bullish_cross',
                'macd_bearish_cross',
            ]),
            'metadata' => [
                'note' => fake()->sentence(4),
            ],
        ];
    }
}
