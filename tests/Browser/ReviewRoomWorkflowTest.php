<?php

use App\Election\ReviewRoom\ReviewRoomService;
use App\Election\ReviewRoom\ReviewStationRole;
use App\Election\Support\ElectionStorage;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    config()->set('election.review.enabled', true);
    config()->set('election.review_room.enabled', true);
    app(ElectionStorage::class)->reset();
});

test('facilitator prepares all review stations from the browser', function (): void {
    visit('/election/review-room')
        ->assertSee('Multi-Tablet Review Room')
        ->fill('name', 'COMELEC Browser Review')
        ->fill('voter_stations', '5')
        ->click('Create review room')
        ->assertSee('Election Officer')
        ->assertSee('Voter Tablet 1')
        ->assertSee('Voter Tablet 5')
        ->assertSee('Private Print Station')
        ->assertSee('Poll Watcher')
        ->assertSee('Presentation Screen')
        ->assertSee('0 of 9 connected')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('presentation screen shows operational status without sensitive ballot data', function (): void {
    $room = app(ReviewRoomService::class)->create(
        'COMELEC Presentation Review',
        2,
        '39010001',
        'browser-review-run',
    );
    $station = $room->stations()
        ->where('role', ReviewStationRole::Presentation)
        ->sole();
    $url = URL::temporarySignedRoute(
        'election.review-room.join',
        now()->addHour(),
        [
            'room' => $room,
            'station' => $station,
            'token' => $station->join_token,
        ],
    );

    visit($url)
        ->assertSee('COMELEC Presentation Review')
        ->assertSee('Current ceremony')
        ->assertSee('Presentation Screen')
        ->assertSee('Candidate totals remain sealed')
        ->assertDontSee('join_url')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
