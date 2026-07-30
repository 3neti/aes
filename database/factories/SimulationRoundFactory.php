<?php

namespace Database\Factories;

use App\Models\SimulationRound;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SimulationRound>
 */
class SimulationRoundFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->bothify('ROUND-####')),
            'name' => 'Public Election Simulation',
            'status' => 'open',
            'opened_at' => now(),
        ];
    }
}
