<?php

namespace App\Election\ReviewRoom;

use App\Election\Support\ElectionStorage;
use App\Models\ReviewRoom;

class StartFreshReviewPresentation
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ReviewRoomService $rooms,
    ) {}

    /**
     * @return array{room: ReviewRoom, run: array<string, mixed>}
     */
    public function handle(): array
    {
        ReviewRoom::query()
            ->where('status', 'open')
            ->get()
            ->each(fn (ReviewRoom $room) => $this->rooms->close($room));

        $precinctId = (string) config('election.pop.clustered_precinct', 'unknown-precinct');
        $run = $this->storage->startRun(
            'presentation',
            $precinctId,
            now()->format('Ymd-His-u'),
            creationSource: 'review-room-start-fresh',
        );
        $voterStations = min(
            (int) config('election.review_room.default_voter_stations', 5),
            (int) config('election.review_room.max_voter_stations', 10),
        );
        $room = $this->rooms->create(
            (string) config('election.review_room.default_name', 'COMELEC Multi-Tablet Review'),
            max(1, $voterStations),
            $precinctId,
            (string) $run['run_id'],
        );

        return [
            'room' => $room,
            'run' => $run,
        ];
    }
}
