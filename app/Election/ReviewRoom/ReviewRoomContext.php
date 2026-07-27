<?php

namespace App\Election\ReviewRoom;

use App\Models\ReviewStation;
use Illuminate\Http\Request;

final class ReviewRoomContext
{
    public function __construct(private readonly ReviewRoomService $rooms) {}

    /**
     * @return array<string, mixed>
     */
    public function forRequest(Request $request): array
    {
        $enabled = (bool) config('election.review_room.enabled', false);

        if (! $enabled) {
            return ['enabled' => false, 'station' => null];
        }

        $station = $this->station($request);

        return [
            'enabled' => true,
            'station' => $station === null ? null : [
                'id' => $station->id,
                'role' => $station->role->value,
                'role_label' => $station->role->label(),
                'label' => $station->label,
                'room_code' => $station->room->code,
                'room_name' => $station->room->name,
                'room_status' => $station->room->status,
            ],
        ];
    }

    public function station(Request $request): ?ReviewStation
    {
        $stationId = $request->session()->get('election_review_station_id');

        if (! is_string($stationId) || $stationId === '') {
            return null;
        }

        $pairingKey = $request->session()->get('election_review_station_pairing_key');

        if (! is_string($pairingKey) || $pairingKey === '') {
            return null;
        }

        $station = ReviewStation::query()->with('room')->find($stationId);

        if (
            $station === null
            || $station->room->status !== 'open'
            || ! $this->rooms->isPairedWithKey($station, $pairingKey)
        ) {
            return null;
        }

        return $station;
    }
}
