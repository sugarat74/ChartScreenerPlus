<?php

namespace Database\Factories;

use App\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Instrument>
 */
class InstrumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticker' => strtoupper(fake()->unique()->lexify('????')),
            'company' => fake()->company(),
            'sector' => fake()->randomElement([
                'Communication Services',
                'Consumer Discretionary',
                'Consumer Staples',
                'Energy',
                'Financials',
                'Health Care',
                'Industrials',
                'Information Technology',
                'Materials',
                'Real Estate',
                'Utilities',
            ]),
            'exchange' => fake()->randomElement(['NYSE', 'NASDAQ']),
            'active' => true,
        ];
    }

    /**
     * Indicate that the instrument is no longer tracked.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }
}
