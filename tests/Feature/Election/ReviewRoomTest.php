<?php

use App\Election\Core\CanonicalJson;
use App\Election\ReviewRoom\ReviewRoomPresenter;
use App\Election\ReviewRoom\ReviewRoomService;
use App\Election\ReviewRoom\ReviewStationRole;
use App\Election\Support\ElectionStorage;
use App\Models\ReviewRoom;
use App\Models\ReviewStation;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('election.review.enabled', true);
    config()->set('election.review_room.enabled', true);
    app(ElectionStorage::class)->reset();
    $this->withoutVite();
});

function createReviewRoom(int $voterStations = 3): ReviewRoom
{
    return app(ReviewRoomService::class)->create(
        'COMELEC Multi-Tablet Review',
        $voterStations,
        '39010001',
        'review-run-001',
    );
}

function reviewStationJoinUrl(ReviewRoom $room, ReviewStation $station): string
{
    return URL::temporarySignedRoute(
        'election.review-room.join',
        now()->addHour(),
        [
            'room' => $room,
            'station' => $station,
            'token' => $station->join_token,
        ],
    );
}

test('review room remains disabled without changing the offline application routes', function (): void {
    config()->set('election.review_room.enabled', false);

    $this->get(route('election.review-room.index'))->assertNotFound();
    $this->get(route('election.home'))->assertSuccessful();
});

test('facilitator creates the complete assigned station set and hash chained event', function (): void {
    $this->post(route('election.review-room.store'), [
        'name' => 'COMELEC Technical Review',
        'voter_stations' => 3,
    ])->assertRedirect(route('election.review-room.index'));

    $room = ReviewRoom::query()->with(['stations', 'events'])->sole();

    expect($room->stations)->toHaveCount(7)
        ->and($room->stations->where('role', ReviewStationRole::Officer))->toHaveCount(1)
        ->and($room->stations->where('role', ReviewStationRole::Voter))->toHaveCount(3)
        ->and($room->stations->where('role', ReviewStationRole::PrintStation))->toHaveCount(1)
        ->and($room->stations->where('role', ReviewStationRole::Watcher))->toHaveCount(1)
        ->and($room->stations->where('role', ReviewStationRole::Presentation))->toHaveCount(1)
        ->and($room->events)->toHaveCount(1);

    $event = $room->events->first();
    $expectedHash = app(CanonicalJson::class)->hash([
        'room_id' => $room->id,
        'sequence' => 1,
        'event_type' => 'review-room.created',
        'occurred_at' => $event->occurred_at->toIso8601String(),
        'payload' => $event->payload,
        'previous_hash' => null,
    ]);

    expect($event->event_hash)->toBe($expectedHash);

    $this->get(route('election.review-room.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/ReviewRoom')
            ->where('isFacilitator', true)
            ->where('room.code', $room->code)
            ->has('room.stations', 7)
            ->has('room.stations.0.join_url')
            ->has('room.stations.0.join_qr_url')
        );
});

test('unpaired browsers see pairing instructions without station credentials', function (): void {
    $room = createReviewRoom(2);

    $this->get(route('election.review-room.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/ReviewRoom')
            ->where('isFacilitator', false)
            ->where('room.code', $room->code)
            ->has('room.stations', 6)
            ->missing('room.stations.0.join_url')
            ->missing('room.stations.0.join_qr_url')
        );
});

test('signed join pairs one station to one browser and enforces its route role', function (): void {
    $room = createReviewRoom(1);
    $station = $room->stations()
        ->where('role', ReviewStationRole::Voter)
        ->sole();

    $this->get(reviewStationJoinUrl($room, $station))
        ->assertRedirect(route('election.voter'))
        ->assertSessionHas('election_review_station_id', $station->id)
        ->assertSessionHas('election_review_station_role', ReviewStationRole::Voter->value);

    $this->get(route('election.home'))->assertForbidden();

    $station->refresh();

    expect(fn () => app(ReviewRoomService::class)->join(
        $station,
        $station->join_token,
        'a-different-browser-session',
    ))->toThrow(RuntimeException::class, 'already paired');

    $this->get(reviewStationJoinUrl($room, $station).'tampered')
        ->assertForbidden();
});

test('presentation station receives a projection safe room view', function (): void {
    $room = createReviewRoom(2);
    $station = $room->stations()
        ->where('role', ReviewStationRole::Presentation)
        ->sole();

    $this->get(reviewStationJoinUrl($room, $station))
        ->assertRedirect(route('election.review-room.presentation'));

    $this->get(route('election.review-room.presentation'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/ReviewRoomPresentation')
            ->where('room.code', $room->code)
            ->has('room.stations', 6)
            ->missing('room.stations.0.join_url')
            ->missing('room.stations.0.join_qr_url')
            ->missing('room.tally')
            ->missing('room.ballots')
        );
});

test('closing a room appends linked evidence and blocks every assigned station', function (): void {
    $room = createReviewRoom(1);

    $this->withSession([
        'election_review_facilitator_room_id' => $room->id,
    ])->post(route('election.review-room.close', $room))
        ->assertRedirect(route('election.review-room.index'));

    $room->refresh()->load('events');
    $created = $room->events->firstWhere('sequence', 1);
    $closed = $room->events->firstWhere('sequence', 2);

    expect($room->status)->toBe('closed')
        ->and($closed->previous_hash)->toBe($created->event_hash)
        ->and($room->events)->toHaveCount(2);

    $station = $room->stations()->where('role', ReviewStationRole::Officer)->sole();
    $this->withSession([
        'election_review_station_id' => $station->id,
        'election_review_station_role' => ReviewStationRole::Officer->value,
    ])->get(route('election.home'))
        ->assertRedirect(route('election.review-room.index'));

    expect(fn () => $created->update(['event_type' => 'changed']))
        ->toThrow(RuntimeException::class, 'append-only');
});

test('only one review room may remain open', function (): void {
    createReviewRoom();

    expect(fn () => createReviewRoom())
        ->toThrow(RuntimeException::class, 'Close the active review room');
});

test('facilitator presentation uses lazy QR endpoints for every station', function (): void {
    $room = createReviewRoom(3);
    $presented = app(ReviewRoomPresenter::class)->facilitator($room);

    expect($presented['stations'])->toHaveCount(7);

    foreach ($presented['stations'] as $station) {
        expect($station['join_url'])->toStartWith('http')
            ->and($station['join_qr_url'])->toStartWith('http')
            ->and($station)->not->toHaveKey('join_qr');
    }

    $station = $room->stations()->firstOrFail();
    $response = $this->withSession([
        'election_review_facilitator_room_id' => $room->id,
    ])->get(route('election.review-room.station-qr', [$room, $station]));

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'image/png');

    expect($response->getContent())->toStartWith("\x89PNG");
});

test('facilitator releases a stranded pairing and records linked evidence', function (): void {
    $room = createReviewRoom(1);
    $station = $room->stations()
        ->where('role', ReviewStationRole::Officer)
        ->sole();
    app(ReviewRoomService::class)->join(
        $station,
        $station->join_token,
        'stranded-browser-session',
    );

    $this->withSession([
        'election_review_facilitator_room_id' => $room->id,
    ])->post(route('election.review-room.station-release', [$room, $station]))
        ->assertRedirect(route('election.review-room.index'));

    $station->refresh();
    $events = $room->events()->orderBy('sequence')->get();

    expect($station->session_id_hash)->toBeNull()
        ->and($station->joined_at)->toBeNull()
        ->and($station->last_seen_at)->toBeNull()
        ->and($events)->toHaveCount(3)
        ->and($events->last()->event_type)->toBe('review-station.released')
        ->and($events->last()->previous_hash)->toBe($events->get(1)->event_hash);
});

test('unpaired browser cannot read QR images or release station assignments', function (): void {
    $room = createReviewRoom(1);
    $station = $room->stations()
        ->where('role', ReviewStationRole::Officer)
        ->sole();

    $this->get(route('election.review-room.station-qr', [$room, $station]))
        ->assertForbidden();
    $this->post(route('election.review-room.station-release', [$room, $station]))
        ->assertForbidden();
});

test('released browser session immediately loses its station role', function (): void {
    $room = createReviewRoom(1);
    $station = $room->stations()
        ->where('role', ReviewStationRole::Voter)
        ->sole();

    $this->get(reviewStationJoinUrl($room, $station))
        ->assertRedirect(route('election.voter'));

    app(ReviewRoomService::class)->release($station, 'Test recovery.');

    $this->get(route('election.voter'))
        ->assertRedirect(route('election.review-room.index'));

    $this->get(reviewStationJoinUrl($room, $station))
        ->assertRedirect(route('election.voter'));
});

test('five and ten voter tablets pair to independent browser sessions', function (int $voterCount): void {
    $room = createReviewRoom($voterCount);
    $stations = $room->stations()
        ->where('role', ReviewStationRole::Voter)
        ->orderBy('slot')
        ->get();

    foreach ($stations as $index => $station) {
        app(ReviewRoomService::class)->join(
            $station,
            $station->join_token,
            "independent-voter-session-{$index}",
        );
    }

    $sessionHashes = $room->stations()
        ->where('role', ReviewStationRole::Voter)
        ->pluck('session_id_hash');
    $presented = app(ReviewRoomPresenter::class)->presentation($room->refresh());

    expect($stations)->toHaveCount($voterCount)
        ->and($sessionHashes->filter())->toHaveCount($voterCount)
        ->and($sessionHashes->unique())->toHaveCount($voterCount)
        ->and($presented['connected_station_count'])->toBe($voterCount);
})->with([5, 10]);
