<?php

namespace Database\Factories;

use App\Models\ReviewRoom;
use App\Models\ReviewRoomEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReviewRoomEvent>
 */
class ReviewRoomEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $occurredAt = now()->toIso8601String();
        $payload = ['source' => 'factory'];

        return [
            'review_room_id' => ReviewRoom::factory(),
            'sequence' => 1,
            'event_type' => 'review-room.created',
            'payload' => $payload,
            'previous_hash' => null,
            'event_hash' => hash('sha256', Str::uuid()->toString()),
            'occurred_at' => $occurredAt,
        ];
    }
}
