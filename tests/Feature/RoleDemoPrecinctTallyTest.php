<?php

use App\Election\Support\ElectionStorage;
use App\Election\Voting\BallotPayloadService;
use Illuminate\Support\Facades\Crypt;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('election.review.access.enabled', false);
    config()->set('election.public_simulation.enabled', true);
    config()->set('election.public_simulation.participation_required', false);
    config()->set('election.devices.printer.driver', 'file');
    config()->set('election.public_simulation.role_demo_bulk_ballots.chunk_size', 5);
    config()->set('election.public_simulation.role_demo_bulk_ballots.rendered_pdf_limit', 5);
    app(ElectionStorage::class)->reset();
    $this->withoutVite();
});

test('role demo precinct tally scans loaded demo ballots into a polling public board', function (): void {
    $this->get(route('election.role-demo.officer'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/RoleDemoOfficer')
            ->where('actions.precinctTally', route('election.role-demo.precinct-tally'))
            ->where('actions.publicPrecinctTally', route('election.role-demo.precinct-tally.public', ['view' => 'all']))
        );

    $this->postJson(route('election.role-demo.bulk-ballots'), [
        'count' => 1,
    ])
        ->assertOk()
        ->assertJsonPath('summary.status', 'complete')
        ->assertJsonPath('summary.generated', 1);

    $sealedPath = app(ElectionStorage::class)->files('counting/sealed')[0];
    $sealed = json_decode((string) file_get_contents($sealedPath), true, flags: JSON_THROW_ON_ERROR);
    $payload = Crypt::decryptString((string) $sealed['encrypted_payload']);

    $this->get(route('election.role-demo.precinct-tally'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/RoleDemoScannerTally')
            ->where('scannerState.revision', 0)
            ->where('scannerState.accepted_count', 0)
            ->where('actions.scannerIngest', route('election.role-demo.precinct-tally.scanner-events.store'))
            ->where('actions.publicBoard', route('election.role-demo.precinct-tally.public'))
            ->has('simulation.scanner.ballots', 1)
        );

    $this->postJson(route('election.role-demo.precinct-tally.scanner-events.store'), [
        'payload' => $payload,
        'source' => 'keyboard_wedge',
    ])
        ->assertOk()
        ->assertJsonPath('event.status', 'accepted')
        ->assertJsonPath('state.accepted_count', 1)
        ->assertJsonCount(1, 'state.accepted_ballots')
        ->assertJsonPath('state.scan_events.0.status', 'accepted');

    $this->postJson(route('election.role-demo.precinct-tally.scanner-events.store'), [
        'payload' => $payload,
        'source' => 'keyboard_wedge',
    ])
        ->assertOk()
        ->assertJsonPath('event.status', 'duplicate')
        ->assertJsonPath('state.accepted_count', 1);

    $this->get(route('election.role-demo.precinct-tally.scanner-events.index'))
        ->assertOk()
        ->assertJsonPath('accepted_count', 1)
        ->assertJsonPath('latest_status', 'duplicate');

    $this->get(route('election.role-demo.precinct-tally.public', ['view' => 'president']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/RoleDemoPrecinctTallyPublic')
            ->where('view', 'president')
            ->where('scannerState.accepted_count', 1)
            ->where('actions.scannerState', route('election.role-demo.precinct-tally.scanner-events.index'))
            ->where('actions.operatorBoard', route('election.role-demo.precinct-tally'))
        );
});

test('precinct ballot scanner artisan command records ballot payloads through the same ingestion path', function (): void {
    $this->get(route('election.role-demo.officer'))->assertSuccessful();

    $this->postJson(route('election.role-demo.bulk-ballots'), [
        'count' => 1,
    ])
        ->assertOk()
        ->assertJsonPath('summary.status', 'complete')
        ->assertJsonPath('summary.generated', 1);

    $sealedPath = app(ElectionStorage::class)->files('counting/sealed')[0];
    $sealed = json_decode((string) file_get_contents($sealedPath), true, flags: JSON_THROW_ON_ERROR);
    $payload = Crypt::decryptString((string) $sealed['encrypted_payload']);

    $this->artisan('election:precinct-ballot-scanner-ingest', [
        '--payload' => $payload,
    ])
        ->expectsOutputToContain('ACCEPTED: Accepted ballot.')
        ->assertSuccessful();

    $this->artisan('election:precinct-ballot-scanner-ingest', [
        '--payload' => $payload,
    ])
        ->expectsOutputToContain('DUPLICATE: Ballot already accepted.')
        ->assertSuccessful();

    $this->get(route('election.role-demo.precinct-tally.scanner-events.index'))
        ->assertOk()
        ->assertJsonPath('accepted_count', 1)
        ->assertJsonPath('latest_status', 'duplicate');
});

test('precinct tally rejects ballots from another precinct session', function (): void {
    $this->get(route('election.role-demo.officer'))->assertSuccessful();

    $this->postJson(route('election.role-demo.bulk-ballots'), [
        'count' => 1,
    ])
        ->assertOk()
        ->assertJsonPath('summary.status', 'complete')
        ->assertJsonPath('summary.generated', 1);

    $storage = app(ElectionStorage::class);
    $sealedPath = $storage->files('counting/sealed')[0];
    $sealed = json_decode((string) file_get_contents($sealedPath), true, flags: JSON_THROW_ON_ERROR);
    $payload = Crypt::decryptString((string) $sealed['encrypted_payload']);
    $configuration = $storage->readJson('runtime/active-precinct.json');

    $storage->writeJson('runtime/active-precinct.json', [
        ...$configuration,
        'precinct_id' => 'OTHER-PRECINCT',
    ]);

    $this->postJson(route('election.role-demo.precinct-tally.scanner-events.store'), [
        'payload' => $payload,
        'source' => 'keyboard_wedge',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('event.status', 'rejected')
        ->assertJsonPath('event.meta', 'Hardware scan · single QR document · Ballot belongs to another precinct.')
        ->assertJsonPath('state.accepted_count', 0)
        ->assertJsonPath('state.latest_status', 'rejected');
});

test('precinct tally rejects valid ballots that are not in the loaded ballot set', function (): void {
    $this->get(route('election.role-demo.officer'))->assertSuccessful();

    $this->postJson(route('election.role-demo.bulk-ballots'), [
        'count' => 1,
    ])
        ->assertOk()
        ->assertJsonPath('summary.status', 'complete')
        ->assertJsonPath('summary.generated', 1);

    $sealedPath = app(ElectionStorage::class)->files('counting/sealed')[0];
    $sealed = json_decode((string) file_get_contents($sealedPath), true, flags: JSON_THROW_ON_ERROR);
    $sealedPayload = Crypt::decryptString((string) $sealed['encrypted_payload']);
    $sealedSelections = app(BallotPayloadService::class)->decode($sealedPayload)['selections'] ?? [];
    $undepositedPayload = app(BallotPayloadService::class)->finalize((array) $sealedSelections, 'undeposited-demo-ballot', journal: false);

    $this->postJson(route('election.role-demo.precinct-tally.scanner-events.store'), [
        'payload' => $undepositedPayload['qr_payload'],
        'source' => 'keyboard_wedge',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('event.status', 'rejected')
        ->assertJsonPath('event.meta', 'Hardware scan · single QR document · Ballot is not part of this precinct tally session.')
        ->assertJsonPath('state.accepted_count', 0)
        ->assertJsonPath('state.latest_status', 'rejected');
});
