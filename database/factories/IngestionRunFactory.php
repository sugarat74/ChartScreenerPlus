<?php

namespace Database\Factories;

use App\Enums\IngestionRunStatus;
use App\Models\IngestionRun;
use App\Models\Universe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngestionRun>
 */
class IngestionRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => IngestionRunStatus::Completed,
            'universe_id' => Universe::factory(),
            'started_at' => now(),
            'finished_at' => now(),
            'total' => 0,
            'succeeded' => 0,
            'failed' => 0,
        ];
    }

    /**
     * Indicate that the run is still in progress.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IngestionRunStatus::Running,
            'finished_at' => null,
        ]);
    }

    /**
     * Indicate that some instruments failed but the run finished.
     */
    public function partial(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IngestionRunStatus::Partial,
        ]);
    }

    /**
     * Indicate that every instrument failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IngestionRunStatus::Failed,
        ]);
    }
}
