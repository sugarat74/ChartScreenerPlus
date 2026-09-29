<?php

namespace Database\Factories;

use App\Enums\IngestionRunItemStatus;
use App\Models\IngestionRun;
use App\Models\IngestionRunItem;
use App\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngestionRunItem>
 */
class IngestionRunItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ingestion_run_id' => IngestionRun::factory(),
            'instrument_id' => Instrument::factory(),
            'status' => IngestionRunItemStatus::Success,
            'bars_stored' => fake()->numberBetween(0, 300),
            'message' => null,
        ];
    }

    /**
     * Indicate that this instrument failed during the run.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IngestionRunItemStatus::Failed,
            'bars_stored' => 0,
            'message' => 'Engine failed.',
        ]);
    }
}
