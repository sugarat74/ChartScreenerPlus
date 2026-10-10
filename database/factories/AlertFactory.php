<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    /**
     * Define the model's default state (a Watchlist signal alert).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => Alert::KIND_WATCHLIST,
            'saved_screener_id' => null,
            'signal_types' => ['golden_cross'],
            'active' => true,
        ];
    }
}
