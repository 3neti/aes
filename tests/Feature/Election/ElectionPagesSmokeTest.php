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

test('printing ceremony reports certification gate for cups printer driver', function (): void {
    config()->set('election.devices.printer.driver', 'cups');
    config()->set('election.devices.printer.cups.name', 'Precinct_Printer');

    try {
        app(ActivateSamplePackage::class)->handle();

        app(BallotPayloadService::class)->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'smoke-ballot-cups-gated');

        $this->from(route('election.printing', ['ballot' => 'smoke-ballot-cups-gated']))
            ->post(route('election.printing.print', ['ballot' => 'smoke-ballot-cups-gated']))
            ->assertRedirect(route('election.printing', ['ballot' => 'smoke-ballot-cups-gated']))
            ->assertSessionHasErrors('printer');

        expect(app(ElectionStorage::class)->files('print-jobs'))->toHaveCount(0);
    } finally {
        config()->set('election.devices.printer.driver', 'file');
    }
});

test('diagnostics page can run device adapter certification', function (): void {
    $this->post(route('election.diagnostics.certify-devices'))
        ->assertRedirect(route('election.diagnostics'));

    $this->get(route('election.diagnostics'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Diagnostics')
            ->where('diagnostics.device_certification.passed', true)
            ->where('diagnostics.device_certification.devices.printer.status', 'ready')
            ->where('diagnostics.device_certification.devices.scanner.status', 'ready')
            ->has('snapshot.journal')
        );
});

test('counting route uses configured handheld scanner adapter', function (): void {
    config()->set('election.devices.scanner.driver', 'handheld');
    config()->set('election.devices.scanner.handheld.name', 'USB Scanner 1');

    try {
        app(ActivateSamplePackage::class)->handle();

        $payload = app(BallotPayloadService::class)->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'smoke-ballot-handheld');

        $this->post(route('election.counting.scan'), [
            'payload' => "AES-SCAN:\n{$payload['qr_payload']}\t",
        ])->assertRedirect(route('election.counting'));

        expect(app(ElectionStorage::class)->files('counting/accepted'))->toHaveCount(1);
    } finally {
        config()->set('election.devices.scanner.driver', 'manual');
    }
});

test('counting route can scan camera qr image data uri', function (): void {
    config()->set('election.devices.scanner.driver', 'camera');
    config()->set('election.devices.scanner.camera.name', 'Precinct Camera 1');

    try {
        app(ActivateSamplePackage::class)->handle();

        $payload = app(BallotPayloadService::class)->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'smoke-ballot-camera');

        $this->post(route('election.counting.scan'), [
            'payload' => 'data:image/png;base64,'.base64_encode(file_get_contents($payload['qr_artifact_path'])),
        ])->assertRedirect(route('election.counting'));

        expect(app(ElectionStorage::class)->files('counting/accepted'))->toHaveCount(1);
    } finally {
        config()->set('election.devices.scanner.driver', 'manual');
    }
});

test('counting page shows operator feedback after accepted and rejected scans', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana'],
    ], 'smoke-ballot-feedback');

    $this->post(route('election.counting.scan'), [
        'payload' => $payload['qr_payload'],
    ])
        ->assertRedirect(route('election.counting'))
        ->assertSessionHas('scan_feedback', fn (array $feedback): bool => $feedback['status'] === 'accepted'
            && $feedback['ballot_id'] === 'smoke-ballot-feedback'
            && $feedback['adapter'] === 'manual-payload');

    $this->get(route('election.counting'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Counting')
            ->where('scanFeedback.status', 'accepted')
            ->where('scanFeedback.ballot_id', 'smoke-ballot-feedback')
            ->where('scanFeedback.adapter', 'manual-payload')
        );

    $this->post(route('election.counting.scan'), [
        'payload' => $payload['qr_payload'],
    ])
        ->assertRedirect(route('election.counting'))
        ->assertSessionHas('scan_feedback', fn (array $feedback): bool => $feedback['status'] === 'rejected'
            && str_contains($feedback['reason'], 'Duplicate ballot payload.'));

    $this->get(route('election.counting'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Counting')
            ->where('scanFeedback.status', 'rejected')
            ->where('scanFeedback.reason', 'Duplicate ballot payload.')
        );
});

test('ceremony shell can record officer attestation', function (): void {
    $this->from(route('election.certification'))
        ->post(route('election.attestations.store'), [
            'ceremony' => 'Friday Certification',
            'officer_code' => 'SIM-OFFICER-001',
            'officer_pin' => '123456',
            'stage' => 'certification',
            'statement' => 'Certification checkpoint reviewed.',
        ])
        ->assertRedirect(route('election.certification'));

    $this->get(route('election.certification'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Certification')
            ->where('snapshot.counts.attestations', 1)
            ->has('snapshot.journal')
        );
});

test('ceremony shell rejects invalid officer pin', function (): void {
    $this->from(route('election.certification'))
        ->post(route('election.attestations.store'), [
            'ceremony' => 'Friday Certification',
            'officer_code' => 'SIM-OFFICER-001',
            'officer_pin' => '000000',
            'stage' => 'certification',
            'statement' => 'Certification checkpoint reviewed.',
        ])
        ->assertRedirect(route('election.certification'))
        ->assertSessionHasErrors('officer_pin');

    expect(app(ElectionStorage::class)->files('attestations'))->toHaveCount(0);
});
