<?php

namespace Database\Factories;

use App\Models\ChartPattern;
use App\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChartPattern>
 */
class ChartPatternFactory extends Factory
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
            'as_of_date' => '2026-03-02',
            'type' => 'double_bottom',
            'status' => 'forming',
            'start_date' => '2026-01-05',
            'end_date' => '2026-02-20',
            'breakout_level' => 115.0,
            'points' => [
                ['date' => '2026-01-05', 'price' => 100.0, 'role' => 'left_low'],
                ['date' => '2026-01-30', 'price' => 115.0, 'role' => 'peak'],
                ['date' => '2026-02-20', 'price' => 100.5, 'role' => 'right_low'],
            ],
            'metadata' => ['rise_pct' => 14.43, 'gap_sessions' => 32.0],
        ];
    }
}
