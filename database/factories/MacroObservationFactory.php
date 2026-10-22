<?php

namespace Database\Factories;

use App\Models\MacroObservation;
use App\Models\MacroSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MacroObservation>
 */
class MacroObservationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'macro_series_id' => MacroSeries::factory(),
            'date' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'value' => fake()->randomFloat(6, 0, 10),
        ];
    }
}
