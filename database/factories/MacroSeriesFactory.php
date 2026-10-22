<?php

namespace Database\Factories;

use App\Models\MacroSeries;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MacroSeries>
 */
class MacroSeriesFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'TEST'.Str::upper(Str::random(8)),
            'source' => MacroSeries::SOURCE_FRED,
            'category' => 'rates',
            'frequency' => 'daily',
            'title' => 'Test series',
            'units' => 'Percent',
        ];
    }
}
