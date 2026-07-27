<?php

namespace App\Election\ReviewRoom;

use App\Election\Voting\StandardQrCode;
use App\Models\ReviewRoom;
use App\Models\ReviewRoomEvent;
use App\Models\ReviewStation;
use Illuminate\Support\Facades\URL;

final class ReviewRoomPresenter
{
    public function __construct(private readonly StandardQrCode $qrCode) {}

    /**
     * @return array<string, mixed>
     */
    public function facilitator(ReviewRoom $room): array
    {
        $room->loadMissing(['stations', 'events']);

        return [
            ...$this->summary($room),
            'stations' => $room->stations
                ->sortBy(fn (ReviewStation $station): string => $station->role->value.'-'.$station->slot)
                ->values()
                ->map(fn (ReviewStation $station): array => $this->station($room, $station, true))
                ->all(),
            'events' => $this->events($room),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentation(ReviewRoom $room): array
    {
        $room->loadMissing(['stations', 'events']);

        return [
            ...$this->summary($room),
            'stations' => $room->stations
                ->sortBy(fn (ReviewStation $station): string => $station->role->value.'-'.$station->slot)
                ->values()
                ->map(fn (ReviewStation $station): array => $this->station($room, $station, false))
                ->all(),
            'events' => $this->events($room),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ReviewRoom $room): array
    {
        $connected = $room->stations
            ->filter(fn (ReviewStation $station): bool => $this->status($station) === 'connected')
            ->count();

        return [
            'id' => $room->id,
            'code' => $room->code,
            'name' => $room->name,
            'precinct_id' => $room->precinct_id,
            'run_id' => $room->run_id,
            'status' => $room->status,
            'opened_at' => $room->opened_at->toIso8601String(),
            'closed_at' => $room->closed_at?->toIso8601String(),
            'station_count' => $room->stations->count(),
            'connected_station_count' => $connected,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function station(ReviewRoom $room, ReviewStation $station, bool $withJoinLink): array
    {
        $data = [
            'id' => $station->id,
            'role' => $station->role->value,
            'role_label' => $station->role->label(),
            'label' => $station->label,
            'slot' => $station->slot,
            'status' => $this->status($station),
            'joined_at' => $station->joined_at?->toIso8601String(),
            'last_seen_at' => $station->last_seen_at?->toIso8601String(),
        ];

        if (! $withJoinLink) {
            return $data;
        }

        $url = URL::temporarySignedRoute(
            'election.review-room.join',
            now()->addMinutes((int) config('election.review_room.join_link_ttl_minutes', 480)),
            [
                'room' => $room,
                'station' => $station,
                'token' => $station->join_token,
            ],
        );

        return [
            ...$data,
            'join_url' => $url,
            'join_qr' => 'data:image/png;base64,'.base64_encode($this->qrCode->renderPng($url)),
        ];
    }

    private function status(ReviewStation $station): string
    {
        if ($station->joined_at === null) {
            return 'waiting';
        }

        $threshold = now()->subSeconds((int) config('election.review_room.online_window_seconds', 30));

        return $station->last_seen_at?->greaterThanOrEqualTo($threshold) === true
            ? 'connected'
            : 'inactive';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function events(ReviewRoom $room): array
    {
        return $room->events
            ->sortByDesc('sequence')
            ->take(12)
            ->map(fn (ReviewRoomEvent $event): array => [
                'sequence' => $event->sequence,
                'event_type' => $event->event_type,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'payload' => $event->payload,
                'previous_hash' => $event->previous_hash,
                'event_hash' => $event->event_hash,
            ])
            ->values()
            ->all();
    }
}
