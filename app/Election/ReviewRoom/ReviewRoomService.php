<?php

namespace App\Election\ReviewRoom;

use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionOperationLock;
use App\Models\ReviewRoom;
use App\Models\ReviewRoomEvent;
use App\Models\ReviewStation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ReviewRoomService
{
    public function __construct(
        private readonly ElectionOperationLock $operationLock,
        private readonly CanonicalJson $json,
    ) {}

    public function create(
        string $name,
        int $voterStationCount,
        ?string $precinctId,
        ?string $runId,
    ): ReviewRoom {
        return $this->operationLock->execute(
            'review-room:create',
            fn (): ReviewRoom => DB::transaction(function () use ($name, $voterStationCount, $precinctId, $runId): ReviewRoom {
                if (ReviewRoom::query()->where('status', 'open')->exists()) {
                    throw new RuntimeException('Close the active review room before starting another one.');
                }

                $room = ReviewRoom::query()->create([
                    'code' => $this->uniqueCode(),
                    'name' => $name,
                    'precinct_id' => $precinctId,
                    'run_id' => $runId,
                    'status' => 'open',
                    'voter_station_count' => $voterStationCount,
                    'opened_at' => now(),
                ]);

                foreach ($this->stationDefinitions($voterStationCount) as $definition) {
                    $token = Str::random(64);

                    $room->stations()->create([
                        ...$definition,
                        'join_token' => $token,
                        'join_token_hash' => hash('sha256', $token),
                    ]);
                }

                $this->appendEvent($room, 'review-room.created', [
                    'name' => $name,
                    'precinct_id' => $precinctId,
                    'run_id' => $runId,
                    'station_count' => $room->stations()->count(),
                    'voter_station_count' => $voterStationCount,
                ]);

                return $room->load(['stations', 'events']);
            }, attempts: 5),
        );
    }

    public function join(ReviewStation $station, string $token, string $pairingKey): ReviewStation
    {
        if (! hash_equals($station->join_token_hash, hash('sha256', $token))) {
            throw new RuntimeException('The review station link is invalid.');
        }

        return $this->operationLock->execute(
            "review-room:join:{$station->id}",
            fn (): ReviewStation => DB::transaction(function () use ($station, $pairingKey): ReviewStation {
                $station = ReviewStation::query()
                    ->with('room')
                    ->lockForUpdate()
                    ->findOrFail($station->id);

                if ($station->room->status !== 'open') {
                    throw new RuntimeException('The review room is closed.');
                }

                $sessionHash = $this->pairingKeyHash($pairingKey);

                if ($station->session_id_hash !== null && ! hash_equals($station->session_id_hash, $sessionHash)) {
                    throw new RuntimeException('This review station is already paired with another browser.');
                }

                $firstJoin = $station->joined_at === null;
                $station->forceFill([
                    'session_id_hash' => $sessionHash,
                    'joined_at' => $station->joined_at ?? now(),
                    'last_seen_at' => now(),
                ])->save();

                if ($firstJoin) {
                    $this->appendEvent($station->room, 'review-station.joined', [
                        'station_id' => $station->id,
                        'role' => $station->role->value,
                        'label' => $station->label,
                        'slot' => $station->slot,
                    ]);
                }

                return $station;
            }, attempts: 5),
        );
    }

    public function heartbeat(ReviewStation $station): void
    {
        $station->forceFill(['last_seen_at' => now()])->saveQuietly();
    }

    public function isPairedWithKey(ReviewStation $station, string $pairingKey): bool
    {
        return $station->session_id_hash !== null
            && hash_equals($station->session_id_hash, $this->pairingKeyHash($pairingKey));
    }

    public function release(ReviewStation $station, string $reason): ReviewStation
    {
        return $this->operationLock->execute(
            "review-room:release:{$station->id}",
            fn (): ReviewStation => DB::transaction(function () use ($station, $reason): ReviewStation {
                $station = ReviewStation::query()
                    ->with('room')
                    ->lockForUpdate()
                    ->findOrFail($station->id);

                if ($station->room->status !== 'open') {
                    throw new RuntimeException('The review room is closed.');
                }

                if ($station->session_id_hash === null) {
                    return $station;
                }

                $joinedAt = $station->joined_at?->toIso8601String();
                $station->forceFill([
                    'session_id_hash' => null,
                    'joined_at' => null,
                    'last_seen_at' => null,
                ])->save();

                $this->appendEvent($station->room, 'review-station.released', [
                    'station_id' => $station->id,
                    'role' => $station->role->value,
                    'label' => $station->label,
                    'slot' => $station->slot,
                    'previous_joined_at' => $joinedAt,
                    'reason' => $reason,
                ]);

                return $station->refresh();
            }, attempts: 5),
        );
    }

    public function close(ReviewRoom $room): ReviewRoom
    {
        return $this->operationLock->execute(
            "review-room:close:{$room->id}",
            fn (): ReviewRoom => DB::transaction(function () use ($room): ReviewRoom {
                $room = ReviewRoom::query()->lockForUpdate()->findOrFail($room->id);

                if ($room->status === 'closed') {
                    return $room;
                }

                $room->forceFill([
                    'status' => 'closed',
                    'closed_at' => now(),
                ])->save();

                $this->appendEvent($room, 'review-room.closed', [
                    'connected_station_count' => $room->stations()->whereNotNull('joined_at')->count(),
                    'station_count' => $room->stations()->count(),
                ]);

                return $room->load(['stations', 'events']);
            }, attempts: 5),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function appendEvent(ReviewRoom $room, string $eventType, array $payload): ReviewRoomEvent
    {
        $previous = ReviewRoomEvent::query()
            ->where('review_room_id', $room->id)
            ->lockForUpdate()
            ->latest('sequence')
            ->first();
        $occurredAt = now()->startOfSecond()->toIso8601String();
        $event = [
            'room_id' => $room->id,
            'sequence' => ($previous->sequence ?? 0) + 1,
            'event_type' => $eventType,
            'occurred_at' => $occurredAt,
            'payload' => $payload,
            'previous_hash' => $previous?->event_hash,
        ];

        return ReviewRoomEvent::query()->create([
            'review_room_id' => $room->id,
            'sequence' => $event['sequence'],
            'event_type' => $eventType,
            'payload' => $payload,
            'previous_hash' => $event['previous_hash'],
            'event_hash' => $this->json->hash($event),
            'occurred_at' => $occurredAt,
        ]);
    }

    private function uniqueCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (ReviewRoom::query()->where('code', $code)->exists());

        return $code;
    }

    private function pairingKeyHash(string $pairingKey): string
    {
        return hash_hmac('sha256', $pairingKey, (string) config('app.key'));
    }

    /**
     * @return array<int, array{role: string, label: string, slot: int}>
     */
    private function stationDefinitions(int $voterStationCount): array
    {
        $stations = [[
            'role' => ReviewStationRole::Officer->value,
            'label' => ReviewStationRole::Officer->label(),
            'slot' => 1,
        ]];

        for ($slot = 1; $slot <= $voterStationCount; $slot++) {
            $stations[] = [
                'role' => ReviewStationRole::Voter->value,
                'label' => ReviewStationRole::Voter->label().' '.$slot,
                'slot' => $slot,
            ];
        }

        foreach ([
            ReviewStationRole::PrintStation,
            ReviewStationRole::Watcher,
            ReviewStationRole::Presentation,
        ] as $role) {
            $stations[] = [
                'role' => $role->value,
                'label' => $role->label(),
                'slot' => 1,
            ];
        }

        return $stations;
    }
}
