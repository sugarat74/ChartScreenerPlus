<?php

namespace Database\Factories;

use App\Models\Universe;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Universe>
 */
class UniverseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            // A factory universe is a Screener universe unless a test says otherwise.
            'is_screener' => true,
        ];
    }

    /**
     * A universe that is not screened (for example Market Context).
     */
    public function notScreener(): static
    {
        return $this->state(fn (array $attributes) => ['is_screener' => false]);
    }
}
