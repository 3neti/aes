<?php

namespace Database\Factories;

use App\Models\ReviewRoom;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReviewRoom>
 */
class ReviewRoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Str::upper(fake()->unique()->bothify('ROOM####')),
            'name' => 'COMELEC Multi-Tablet Review',
            'precinct_id' => '39010001',
            'run_id' => null,
            'status' => 'open',
            'voter_station_count' => 3,
            'opened_at' => now(),
            'closed_at' => null,
        ];
    }
}
