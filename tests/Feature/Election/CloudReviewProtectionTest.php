<?php

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
