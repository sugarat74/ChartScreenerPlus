<?php

namespace Database\Factories;

use App\Models\SavedScreener;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedScreener>
 */
class SavedScreenerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default `filters` object is the complete, canonical seven-key
     * definition (API param names, `sort` included) so a factory-made row is
     * always re-appliable.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'filters' => [
                'signal' => [],
                'rsi_min' => null,
                'rsi_max' => null,
                'min_rvol' => null,
                'price_above_sma200' => false,
                'ma_cross' => null,
                'sort' => 'rvol_desc',
            ],
        ];
    }
}
