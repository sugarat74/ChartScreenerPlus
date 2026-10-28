<?php

namespace Database\Factories;

use App\Models\Instrument;
use App\Models\Signal;
use App\Services\Screener\ScreenerFilterParser;
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
            'type' => fake()->randomElement(ScreenerFilterParser::SIGNAL_TYPES),
            'metadata' => [
                'note' => fake()->sentence(4),
            ],
        ];
    }
}
