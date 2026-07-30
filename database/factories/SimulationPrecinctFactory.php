<?php

namespace Database\Factories;

use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SimulationPrecinct>
 */
class SimulationPrecinctFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'simulation_round_id' => SimulationRound::factory(),
            'code' => strtoupper(fake()->bothify('P-###')),
            'clustered_precinct' => '39010001',
            'district' => 'FIRST DIST',
            'label' => 'Tondo Demonstration Precinct',
            'city_municipality' => 'CITY OF MANILA',
            'province' => 'NCR',
            'status' => 'ready',
            'officer_name' => 'Simulation Election Officer',
            'officer_code' => 'SIM-P-001',
            'officer_pin_hash' => hash('sha256', '123456'),
        ];
    }
}
