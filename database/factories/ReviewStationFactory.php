<?php

namespace Database\Factories;

use App\Election\ReviewRoom\ReviewStationRole;
use App\Models\ReviewRoom;
use App\Models\ReviewStation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReviewStation>
 */
class ReviewStationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'review_room_id' => ReviewRoom::factory(),
            'role' => ReviewStationRole::Voter,
            'label' => 'Voter Tablet 1',
            'slot' => 1,
            'join_token' => $token = Str::random(64),
            'join_token_hash' => hash('sha256', $token),
            'session_id_hash' => null,
            'joined_at' => null,
            'last_seen_at' => null,
        ];
    }
}
