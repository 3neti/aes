<?php

use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Printing\BallotPrinter;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\BallotPayloadService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
    $this->withoutVite();
});

test('ceremony page renders :component', function (string $route, string $component): void {
    $this->get(route($route))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->has('snapshot.stage')
            ->has('snapshot.ceremony')
            ->has('snapshot.nextAction')
            ->has('snapshot.journal')
        );
})->with([
    'home' => ['home', 'Election/Home'],
    'provision' => ['election.provision', 'Election/Provision'],
    'certification' => ['election.certification', 'Election/Certification'],
    'voting' => ['election.voting', 'Election/Voting'],
    'printing' => ['election.printing', 'Election/Printing'],
    'counting' => ['election.counting', 'Election/Counting'],
    'returns' => ['election.returns', 'Election/Returns'],
    'diagnostics' => ['election.diagnostics', 'Election/Diagnostics'],
]);

test('printing page exposes finalized ballot qr and artifact state', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana'],
    ], 'smoke-ballot-001');

    app(BallotPrinter::class)->print($payload);

    $this->get(route('election.printing', ['ballot' => 'smoke-ballot-001']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Printing')
            ->where('payload.ballot_id', 'smoke-ballot-001')
            ->where('payload.payload_hash', $payload['payload_hash'])
            ->where('qrImageDataUri', fn (string $value): bool => str_starts_with($value, 'data:image/png;base64,'))
            ->has('snapshot.counts')
        );
});
