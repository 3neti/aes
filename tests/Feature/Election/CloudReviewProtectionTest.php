<?php

use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\ReviewRoom\ReviewRoomService;
use App\Election\ReviewRoom\ReviewStationRole;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
});

test('review access protection is disabled by default', function (): void {
    config()->set('election.review.access.enabled', false);

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertHeaderMissing('X-Robots-Tag');
});

test('enabled review protection rejects anonymous and invalid access', function (string $authorization): void {
    configureReviewAccess();

    $this->withHeaders(['Authorization' => $authorization])
        ->get(route('home'))
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="AES COMELEC Review", charset="UTF-8"')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet')
        ->assertHeader('Cache-Control', 'no-store, private');
})->with([
    'anonymous' => '',
    'wrong username' => basicAuthorization('wrong-user', 'review-secret'),
    'wrong password' => basicAuthorization('comelec-review', 'wrong-password'),
]);

test('enabled review protection permits configured credentials and prohibits indexing', function (): void {
    configureReviewAccess();

    $this->withHeaders([
        'Authorization' => basicAuthorization('comelec-review', 'review-secret'),
    ])->get(route('home'))
        ->assertSuccessful()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Pragma', 'no-cache');
});

test('enabled review protection permits simple demo credentials when configured', function (): void {
    configureReviewAccess();
    config()->set('election.review.access.demo_credentials.enabled', true);
    config()->set('election.review.access.demo_credentials.username', 'user');
    config()->set('election.review.access.demo_credentials.password', 'user');

    $this->withHeaders([
        'Authorization' => basicAuthorization('user', 'user'),
    ])->get(route('home'))
        ->assertSuccessful()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Pragma', 'no-cache');
});

test('basic authenticated browser reclaims facilitator access to an open room', function (): void {
    configureReviewAccess();
    config()->set('election.review.enabled', true);
    config()->set('election.review_room.enabled', true);
    $room = app(ReviewRoomService::class)->create(
        'Recoverable review room',
        2,
        '39010001',
        'review-run',
    );

    $headers = [
        'Authorization' => basicAuthorization('comelec-review', 'review-secret'),
    ];

    $this->withHeaders($headers)
        ->get(route('election.review-room.index'))
        ->assertSuccessful()
        ->assertSessionHas('election_review_facilitator_room_id', $room->id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('isFacilitator', true)
            ->where('canStartFresh', true)
            ->has('room.stations.0.join_url')
            ->has('room.stations.0.join_qr_url')
        );

    $station = $room->stations()->firstOrFail();

    $this->withHeaders($headers)
        ->get(route('election.review-room.station-qr', [$room, $station]))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'image/png');
});

test('a valid station qr bypasses browser credentials and preserves role isolation', function (): void {
    configureReviewAccess();
    config()->set('election.review.enabled', true);
    config()->set('election.review_room.enabled', true);

    $room = app(ReviewRoomService::class)->create(
        'Protected station review',
        1,
        '39010001',
        'review-run',
    );
    $station = $room->stations->first(
        fn ($station): bool => $station->role === ReviewStationRole::Voter,
    );

    expect($station)->not->toBeNull();

    $joinUrl = URL::temporarySignedRoute(
        'election.review-room.join',
        now()->addMinutes(30),
        [
            'room' => $room,
            'station' => $station,
            'token' => $station->join_token,
        ],
    );

    $this->get($joinUrl)
        ->assertRedirect(route('election.voter'))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');

    app(LifecycleState::class)->set(Lifecycle::Voting);

    $this->get(route('election.voter'))
        ->assertSuccessful()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');

    $this->get(route('election.provision'))
        ->assertForbidden()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');

    $this->withHeaders([
        'Authorization' => basicAuthorization('comelec-review', 'review-secret'),
    ])->get(route('election.review-room.index'))
        ->assertSuccessful()
        ->assertSessionMissing('election_review_facilitator_room_id')
        ->assertInertia(fn (Assert $page) => $page
            ->where('isFacilitator', false)
            ->where('canStartFresh', false)
            ->missing('room.stations.0.join_url')
            ->missing('room.stations.0.join_qr_url')
        );

    $this->post(route('election.review-room.store'), [
        'name' => 'Unauthorized replacement room',
        'voter_stations' => 1,
    ])->assertForbidden();

    $this->post(route('election.review-room.start-fresh'))->assertForbidden();
});

test('an invalid station qr still requires browser credentials', function (): void {
    configureReviewAccess();
    config()->set('election.review.enabled', true);
    config()->set('election.review_room.enabled', true);

    $room = app(ReviewRoomService::class)->create(
        'Invalid station review',
        1,
        '39010001',
        'review-run',
    );
    $station = $room->stations->first(
        fn ($station): bool => $station->role === ReviewStationRole::Voter,
    );

    $this->get(route('election.review-room.join', [
        'room' => $room,
        'station' => $station,
        'token' => $station?->join_token,
    ]))
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="AES COMELEC Review", charset="UTF-8"');
});

test('a signed station qr fails closed when review credentials are missing', function (): void {
    config()->set('election.review.enabled', true);
    config()->set('election.review_room.enabled', true);
    config()->set('election.review.access.enabled', true);
    config()->set('election.review.access.username', '');
    config()->set('election.review.access.password', '');

    $room = app(ReviewRoomService::class)->create(
        'Unconfigured access review',
        1,
        '39010001',
        'review-run',
    );
    $station = $room->stations->first(
        fn ($station): bool => $station->role === ReviewStationRole::Voter,
    );
    $joinUrl = URL::temporarySignedRoute(
        'election.review-room.join',
        now()->addMinutes(30),
        [
            'room' => $room,
            'station' => $station,
            'token' => $station?->join_token,
        ],
    );

    $this->get($joinUrl)
        ->assertServiceUnavailable()
        ->assertSee('Review access is not configured.');
});

test('enabled review protection fails closed when credentials are missing', function (): void {
    config()->set('election.review.access.enabled', true);
    config()->set('election.review.access.username', '');
    config()->set('election.review.access.password', '');

    $this->get(route('home'))
        ->assertServiceUnavailable()
        ->assertSee('Review access is not configured.')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
});

test('robots file prohibits all crawling', function (): void {
    expect(file_get_contents(public_path('robots.txt')))->toBe(
        "User-agent: *\nDisallow: /\n",
    );
});

function configureReviewAccess(): void
{
    config()->set('election.review.access.enabled', true);
    config()->set('election.review.access.username', 'comelec-review');
    config()->set('election.review.access.password', 'review-secret');
}

function basicAuthorization(string $username, string $password): string
{
    return 'Basic '.base64_encode("{$username}:{$password}");
}
