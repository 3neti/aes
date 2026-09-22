<?php

use App\Election\Core\ActivityJournal;
use App\Election\PublicSimulation\PublicSimulationAdmissionCapacity;
use App\Election\PublicSimulation\PublicSimulationScope;
use App\Election\Support\ElectionStorage;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Illuminate\Filesystem\Filesystem;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('election.review.access.enabled', false);
    config()->set('election.public_simulation.enabled', true);
    config()->set('election.public_simulation.participation_required', false);
    config()->set('election.public_simulation.demo_control_number_share.enabled', true);
    config()->set('election.voter.demo_random_fill_enabled', true);
    config()->set('election.voter.ballot_ui_profile', 'comelec_2022_facsimile');
    config()->set('election.voter.paper_facsimile_max_columns', 4);
    config()->set('election.devices.printer.driver', 'file');
    app(ElectionStorage::class)->reset();
    $this->withoutVite();
});

test('role demo runs officer voter print and watcher points of view without closing the precinct', function (): void {
    $this->get(route('election.role-demo.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/RoleDemoLobby')
            ->where('precinct.status', 'open')
            ->where('actions.officer', route('election.role-demo.officer'))
            ->where('actions.voter', route('election.role-demo.voter'))
            ->where('actions.watcher', route('election.role-demo.watcher'))
            ->where('actions.scannerTally', route('election.role-demo.scanner-tally'))
            ->where('actions.precinctTally', route('election.role-demo.precinct-tally'))
            ->where('actions.publicPrecinctTally', route('election.role-demo.precinct-tally.public', ['view' => 'all']))
            ->where('actions.canvassingDemo', route('election.canvassing-demo.show'))
            ->where('actions.publicCanvassBoard', route('election.canvassing-demo.public', ['view' => 'all']))
            ->where('actions.publicNationalCanvass', route('election.canvassing-demo.public', ['view' => 'national']))
            ->where('actions.publicLocalCanvass', route('election.canvassing-demo.public', ['view' => 'local']))
        );

    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class)
        ->and($precinct->fresh()->status)->toBe('open');

    $this->get(route('election.role-demo.officer'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/RoleDemoOfficer')
            ->where('currentTally.accepted_ballots', 0)
            ->where('actions.acceptPrint', route('election.role-demo.print.accept'))
            ->where('actions.bulkBallots', route('election.role-demo.bulk-ballots'))
            ->where('actions.precinctTally', route('election.role-demo.precinct-tally'))
            ->where('actions.publicPrecinctTally', route('election.role-demo.precinct-tally.public', ['view' => 'all']))
            ->where('navigationQrs.precinctTally', fn (string $qr): bool => str_starts_with($qr, 'data:image/png;base64,'))
            ->where('navigationQrs.publicPrecinctTally', fn (string $qr): bool => str_starts_with($qr, 'data:image/png;base64,'))
            ->where('actions.latestControlNumberReceipt', route('election.role-demo.control-number.latest'))
            ->where('actions.printControlNumberReceipt', route('election.role-demo.print.control-number.latest'))
            ->where('latestControlNumberReceipt.available', false)
            ->where('actions.printTally', route('election.role-demo.print.tally-sheet'))
            ->where('actions.returns.national', route('election.role-demo.election-return.scoped', ['scope' => 'national']))
            ->where('actions.returns.local', route('election.role-demo.election-return.scoped', ['scope' => 'local']))
            ->where('actions.returns.combined', route('election.role-demo.election-return.scoped', ['scope' => 'combined']))
            ->where('actions.printReturns.national', route('election.role-demo.print.election-return.scoped', ['scope' => 'national']))
            ->where('actions.printReturns.local', route('election.role-demo.print.election-return.scoped', ['scope' => 'local']))
            ->where('actions.printReturns.combined', route('election.role-demo.print.election-return.scoped', ['scope' => 'combined']))
            ->where('bulkBallots.enabled', true)
            ->where('bulkBallots.chunk_size', 5)
        );

    $this->post(route('election.role-demo.admit'))
        ->assertRedirectToRoute('election.role-demo.officer');

    $authorization = session('role_demo.control_number');
    expect($authorization)->toBeArray()
        ->and($authorization['code'])->toMatch('/^[0-9]{4}$/');

    $this->get(route('election.role-demo.voter', ['code' => $authorization['code']]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/VoterWelcome')
            ->where('initialControlNumber', $authorization['code'])
            ->where('claimAction', route('election.role-demo.voter.claim'))
            ->where('demoControlNumberAction', route('election.role-demo.voter.control-number'))
        );

    $this->post(route('election.role-demo.voter.claim'), [
        'code' => $authorization['code'],
    ])->assertRedirectToRoute('election.role-demo.voter.ballot');

    $this->post(route('election.role-demo.print.accept'), [
        'code' => $authorization['code'],
    ])->assertSessionHasErrors('code');

    $this->get(route('election.role-demo.voter.ballot'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/VoterBallot')
            ->where('finalizeAction', route('election.role-demo.voter.finalize'))
            ->where('ballotUiProfile', 'comelec_2022_facsimile')
            ->where('ballotMaxColumns', 4)
            ->where('demoRandomFillEnabled', true)
            ->has('ballot.contests', 8)
            ->where('ballot.contests.0.office', 'PRESIDENT')
            ->has('ballot.contests.0.candidates', 10)
        );

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $configuration = app(ElectionStorage::class)->readJson('runtime/active-precinct.json');
    $selections = collect($configuration['contests'])
        ->mapWithKeys(fn (array $contest): array => [
            $contest['id'] => collect($contest['candidates'])
                ->take(min(1, (int) $contest['max_selections']))
                ->pluck('id')
                ->all(),
        ])
        ->all();

    $this->post(route('election.role-demo.voter.finalize'), [
        'selections' => $selections,
    ])->assertRedirectToRoute('election.role-demo.voter.complete');

    $release = session('role_demo.release');
    expect($release)->toBeArray()
        ->and($release['release_code'])->toBe($authorization['code']);

    $this->get(route('election.role-demo.control-number.latest'))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('content-disposition', 'inline; filename="TONDO-01-latest-voter-control-number.pdf"');

    $this->post(route('election.role-demo.print.control-number.latest'))
        ->assertRedirectToRoute('election.role-demo.officer')
        ->assertSessionHas('role_demo.closeout_feedback', 'Voter Control Number prepared with status [printed].');

    $this->get(route('election.role-demo.officer'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('latestControlNumberReceipt.available', true)
        );

    $this->get(route('election.role-demo.voter.complete'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/VoterComplete')
            ->where('release.release_code', $release['release_code'])
            ->where('resetAction', route('election.role-demo.voter.reset'))
            ->where('demoBallotPreviewEnabled', true)
            ->where('ballotPreviewAction', route('election.role-demo.voter.complete.ballot-preview'))
        );

    $this->get(route('election.role-demo.voter.complete.ballot-preview'))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('content-disposition', 'inline; filename="role-demo-voter-ballot-preview.pdf"');

    expect(app(ElectionStorage::class)->readJson("print-releases/{$release['release_id']}.json")['status'])->toBe('pending')
        ->and(app(ElectionStorage::class)->files('counting/sealed'))->toBeEmpty()
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type'))
        ->toContain('role_demo.voter_ballot_preview_generated');

    $this->post(route('election.role-demo.print.accept'), [
        'code' => $authorization['code'],
    ])->assertRedirectToRoute('election.role-demo.officer');

    $this->get(route('election.role-demo.officer'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentTally.accepted_ballots', 1)
            ->where('printFeedback.status', 'accepted')
        );

    $this->post(route('election.role-demo.print.accept'), [
        'code' => $authorization['code'],
    ])->assertSessionHasErrors('code');

    $this->get(route('election.role-demo.scanner-tally'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/RoleDemoScannerTally')
            ->where('simulation.source', 'sealed-role-demo-ballots')
            ->where('scannerState.accepted_count', 0)
            ->where('actions.scannerIngest', route('election.role-demo.precinct-tally.scanner-events.store'))
            ->where('actions.publicBoard', route('election.role-demo.precinct-tally.public'))
            ->where('simulation.scanner.candidate_code_map.mapping_hash', $configuration['mapping_hash'])
            ->has('simulation.scanner.candidate_code_map.candidates')
            ->where('simulation.scanner.ballots.0.source', 'sealed ballot box')
            ->where('simulation.scanner.ballots.0.payload', fn (string $payload): bool => str_starts_with($payload, 'truth://v1/waes-ballot/aes-ballot-compact-1?p='))
            ->where('simulation.scanner.ballots.0.canonical_payload', fn (string $payload): bool => str_starts_with($payload, 'aes-ballot-compact-1:'))
        );

    $this->get(route('election.role-demo.watcher'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/RoleDemoWatcher')
            ->where('precinct.accepted_ballots', 1)
            ->where('demoTransparencyMode', true)
            ->where('ballotReview.enabled', true)
            ->where('ballotReview.allowed', true)
            ->where('ballotReview.record_count', 1)
            ->where('ballotReview.ballots.0.sequence', 1)
            ->where('ballotReview.ballots.0.qr_decode_status', 'decoded')
            ->where('ballotReview.ballots.0.pdf_available', true)
            ->where('ballotReview.ballots.0.pdf_url', route('election.role-demo.watcher.ballot', ['sequence' => 1]))
            ->has('ballotReview.ballots.0.selected_candidates')
            ->has('ballotReview.qr_audit_tally')
            ->where('downloads.tally', route('election.role-demo.tally-sheet'))
            ->where('downloads.return', route('election.role-demo.election-return'))
            ->where('downloads.returns.national', route('election.role-demo.election-return.scoped', ['scope' => 'national']))
            ->where('downloads.returns.local', route('election.role-demo.election-return.scoped', ['scope' => 'local']))
            ->where('downloads.returns.combined', route('election.role-demo.election-return.scoped', ['scope' => 'combined']))
        );

    $this->get(route('election.role-demo.watcher.ballot', ['sequence' => 1]))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('content-disposition', 'inline; filename="TONDO-01-role-demo-ballot-001.pdf"');

    $this->get(route('election.role-demo.print.last-ballot'))
        ->assertSuccessful()
        ->assertHeader('content-disposition', 'inline; filename="role-demo-last-printed-ballot.pdf"');

    $printedRelease = app(ElectionStorage::class)->readJson("print-releases/{$release['release_id']}.json");
    $printJob = app(ElectionStorage::class)->readJson("print-jobs/{$printedRelease['ballot_id']}.json");
    $printedRecord = file_get_contents($printJob['artifact_path']);
    $printedPdf = file_get_contents($printJob['pdf_artifact_path']);
    preg_match('/QR Artifact: (?<path>.+\.png)/', $printedRecord, $qrArtifact);
    $qrImage = getimagesize($qrArtifact['path']);
    preg_match_all('/\/Type \/Page\b/', $printedPdf, $printedPdfPages);

    expect($printedPdf)
        ->toContain('q 216.00 0 0 216.00')
        ->toContain('SELECTED CANDIDATES ONLY')
        ->toContain('Ballot QR Verification')
        ->not->toContain('BALLOT QR VERIFICATION COPY')
        ->and(count($printedPdfPages[0]))->toBe(1)
        ->and($qrImage[0])->toBeGreaterThanOrEqual(1080)
        ->and($qrImage[1])->toBeGreaterThanOrEqual(1080);

    $this->get(route('election.role-demo.tally-sheet'))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf');

    $nationalElectionReturn = $this->get(route('election.role-demo.election-return.scoped', ['scope' => 'national']))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf');

    $localElectionReturn = $this->get(route('election.role-demo.election-return.scoped', ['scope' => 'local']))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf');

    $nationalElectionReturnContent = file_get_contents(
        app(ElectionStorage::class)->path("print-forms/election-return/{$configuration['precinct_id']}/national/a4.pdf"),
    );
    $localElectionReturnContent = file_get_contents(
        app(ElectionStorage::class)->path("print-forms/election-return/{$configuration['precinct_id']}/local/a4.pdf"),
    );

    $this->assertStringContainsString('TRUTHTALLY National Election Return QR', $nationalElectionReturnContent);
    $this->assertStringContainsString('q 216.00 0 0 216.00', $nationalElectionReturnContent);
    $this->assertStringContainsString('TRUTHTALLY Local Election Return QR', $localElectionReturnContent);
    $this->assertStringContainsString('q 216.00 0 0 216.00', $localElectionReturnContent);

    $this->post(route('election.role-demo.print.tally-sheet'))
        ->assertRedirectToRoute('election.role-demo.officer')
        ->assertSessionHas('role_demo.closeout_feedback', 'Tally sheet A4 evidence copy PDF is ready for browser printing.');

    $this->get(route('election.role-demo.election-return', ['profile' => 'thermal-80']))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->get(route('election.role-demo.election-return.scoped', ['scope' => 'national', 'profile' => 'thermal-80']))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->get(route('election.role-demo.election-return.scoped', ['scope' => 'local', 'profile' => 'thermal-80']))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->post(route('election.role-demo.print.election-return.scoped', ['scope' => 'national', 'profile' => 'thermal-80']))
        ->assertRedirectToRoute('election.role-demo.officer')
        ->assertSessionHas('role_demo.closeout_feedback', 'National Election Return 80 mm thermal roll PDF is ready for browser printing.');

    $this->post(route('election.role-demo.print.election-return.scoped', ['scope' => 'local']))
        ->assertRedirectToRoute('election.role-demo.officer')
        ->assertSessionHas('role_demo.closeout_feedback', 'Local Election Return A4 evidence copy PDF is ready for browser printing.');

    expect($precinct->fresh()->status)->toBe('open')
        ->and(app(ElectionStorage::class)->path('runtime/tally-sheet.pdf'))->toBeReadableFile()
        ->and(app(ElectionStorage::class)->path("returns/{$configuration['precinct_id']}-return.pdf"))->toBeReadableFile();
});

test('role demo scanner tally simulates reading ballot QR payloads into tally marks', function (): void {
    $this->get(route('election.role-demo.scanner-tally'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/RoleDemoScannerTally')
            ->where('precinct.status', 'open')
            ->where('simulation.source', 'generated-demo-ballot-payloads')
            ->where('simulation.precinct.precinct_id', fn (string $precinctId): bool => $precinctId !== '')
            ->has('simulation.ballot.contests', 8)
            ->has('simulation.scanner.ballots', 8)
            ->has('simulation.scanner.candidate_code_map.candidates')
            ->where('simulation.scanner.ballots.0.sequence', 1)
            ->where('simulation.scanner.ballots.0.source', 'generated ballot payload')
            ->where('simulation.scanner.ballots.0.payload', fn (string $payload): bool => str_starts_with($payload, 'truth://v1/waes-ballot/aes-ballot-compact-1?p='))
            ->where('simulation.scanner.ballots.0.canonical_payload', fn (string $payload): bool => str_starts_with($payload, 'aes-ballot-compact-1:'))
            ->where('simulation.scanner.ballots.0.selections', fn (mixed $selections): bool => collect($selections)->isNotEmpty())
            ->where('simulation.scanner.ballots.0.this_ballot_tally', fn (mixed $tally): bool => collect($tally)->flatten()->contains(1))
            ->where('simulation.scanner.initial_tally', fn (mixed $tally): bool => collect($tally)->flatten()->every(fn (int $votes): bool => $votes === 0))
        );
});

test('role demo voter can generate a self service control number before claiming the ballot', function (): void {
    $this->get(route('election.role-demo.voter'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/VoterWelcome')
            ->where('claimAction', route('election.role-demo.voter.claim'))
            ->where('demoControlNumberAction', route('election.role-demo.voter.control-number'))
            ->where('publicSimulation', true)
        );

    $response = $this->postJson(route('election.role-demo.voter.control-number'));

    $response
        ->assertSuccessful()
        ->assertJsonStructure(['code', 'expires_at']);

    $code = $response->json('code');
    expect($code)->toMatch('/^[0-9]{4}$/')
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type'))
        ->toContain('role_demo.self_service_control_number_issued');

    $this->post(route('election.role-demo.voter.claim'), [
        'code' => $code,
    ])->assertRedirectToRoute('election.role-demo.voter.ballot');

    $this->get(route('election.role-demo.voter.ballot'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/VoterBallot')
            ->where('finalizeAction', route('election.role-demo.voter.finalize'))
            ->where('demoRandomFillEnabled', true)
            ->where('ballotUiProfile', 'comelec_2022_facsimile')
            ->has('ballot.contests', 8)
            ->where('ballot.contests.0.office', 'PRESIDENT')
            ->has('ballot.contests.0.candidates', 10)
        );
});

test('role demo heals an open precinct with a missing ballot package before rendering voter ballot', function (): void {
    $this->get(route('election.role-demo.index'))->assertSuccessful();
    app(ElectionStorage::class)->writeJson('runtime/active-precinct.json', [
        'precinct_id' => '39010402',
        'contests' => [],
    ]);

    $authorization = $this->postJson(route('election.role-demo.voter.control-number'))
        ->assertSuccessful()
        ->json('code');

    $this->post(route('election.role-demo.voter.claim'), [
        'code' => $authorization,
    ])->assertRedirectToRoute('election.role-demo.voter.ballot');

    $this->get(route('election.role-demo.voter.ballot'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/VoterBallot')
            ->has('ballot.contests', 8)
            ->where('ballot.contests.0.office', 'PRESIDENT')
            ->has('ballot.contests.0.candidates', 10)
        );
});

test('role demo replaces a stale open round after configured precincts change', function (): void {
    $staleRound = SimulationRound::query()->create([
        'code' => 'ROUND-STALE1',
        'name' => 'Stale Public Election Simulation',
        'status' => 'open',
        'opened_at' => now()->subHour(),
    ]);
    $staleRound->precincts()->create([
        'code' => 'TONDO-01',
        'clustered_precinct' => '39010001',
        'district' => 'FIRST DIST',
        'label' => 'Tondo Precinct 01',
        'city_municipality' => 'CITY OF MANILA',
        'province' => 'NATIONAL CAPITAL REGION',
        'status' => 'open',
        'officer_name' => 'Volunteer Election Officer 1',
        'officer_code' => 'SIM-1-OLD',
        'officer_pin_hash' => hash('sha256', '123456'),
        'opened_at' => now()->subHour(),
    ]);

    $this->get(route('election.role-demo.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/RoleDemoLobby')
            ->where('precinct.clustered_precinct', '39010402')
            ->where('precinct.status', 'open')
        );

    $freshRound = SimulationRound::query()
        ->where('status', 'open')
        ->with('precincts')
        ->sole();

    expect($staleRound->fresh()->status)->toBe('archived')
        ->and($freshRound->code)->not->toBe($staleRound->code)
        ->and($freshRound->precincts->pluck('clustered_precinct')->unique()->values()->all())->toBe(['39010402']);
});

test('role demo self service control number recycles the oldest unused issued number when capacity is full', function (): void {
    config()->set('election.public_simulation.maximum_active_admissions', 2);

    $first = $this->postJson(route('election.role-demo.voter.control-number'))
        ->assertSuccessful()
        ->json('code');
    $second = $this->postJson(route('election.role-demo.voter.control-number'))
        ->assertSuccessful()
        ->json('code');
    $replacement = $this->postJson(route('election.role-demo.voter.control-number'))
        ->assertSuccessful()
        ->json('code');

    expect($first)->toMatch('/^[0-9]{4}$/')
        ->and($second)->toMatch('/^[0-9]{4}$/')
        ->and($replacement)->toMatch('/^[0-9]{4}$/');

    $this->post(route('election.role-demo.voter.claim'), [
        'code' => $replacement,
    ])->assertRedirectToRoute('election.role-demo.voter.ballot');

    $authorizationStatuses = collect(app(ElectionStorage::class)->files('voter-authorizations'))
        ->map(fn (string $path): array => app(ElectionStorage::class)->readJson('voter-authorizations/'.basename($path)))
        ->countBy('status');

    expect($first)->not->toBe($second)
        ->and($replacement)->not->toBe($first)
        ->and($replacement)->not->toBe($second)
        ->and($authorizationStatuses['expired'] ?? 0)->toBe(1)
        ->and(app(PublicSimulationAdmissionCapacity::class)->summary()['active_admissions'])->toBe(2)
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type'))
        ->toContain('role_demo.self_service_control_number_recycled');
});

test('role demo reset replaces the live precinct with a freshly opened one', function (): void {
    $this->get(route('election.role-demo.index'))->assertSuccessful();
    $firstRound = SimulationRound::query()->sole();
    $storage = app(ElectionStorage::class);

    app(Filesystem::class)->deleteDirectory($storage->activeRunPath());

    $this->post(route('election.role-demo.reset'))
        ->assertRedirectToRoute('election.role-demo.index');

    $freshRound = SimulationRound::query()
        ->where('status', 'open')
        ->with('precincts')
        ->sole();

    expect($firstRound->fresh()->status)->toBe('archived')
        ->and(SimulationRound::query()->where('status', 'open')->count())->toBe(1)
        ->and($freshRound->precincts->where('status', 'open'))->toHaveCount(1);
});

test('role demo officer can bulk generate deposited ballots for watcher review', function (): void {
    config()->set('election.public_simulation.role_demo_bulk_ballots.max_count', 12);
    config()->set('election.public_simulation.role_demo_bulk_ballots.chunk_size', 5);
    config()->set('election.public_simulation.role_demo_bulk_ballots.rendered_pdf_limit', 3);
    config()->set('election.public_simulation.role_demo_bulk_ballots.presets', [3, 12]);

    $this->get(route('election.role-demo.index'))->assertSuccessful();

    $this->postJson(route('election.role-demo.bulk-ballots'), [
        'count' => 12,
    ])
        ->assertSuccessful()
        ->assertJsonPath('summary.status', 'running')
        ->assertJsonPath('summary.generated', 5)
        ->assertJsonPath('summary.remaining', 7);

    $this->postJson(route('election.role-demo.bulk-ballots'), [
        'count' => 12,
    ])
        ->assertSuccessful()
        ->assertJsonPath('summary.status', 'running')
        ->assertJsonPath('summary.generated', 10)
        ->assertJsonPath('summary.remaining', 2);

    $this->postJson(route('election.role-demo.bulk-ballots'), [
        'count' => 12,
    ])
        ->assertSuccessful()
        ->assertJsonPath('summary.status', 'complete')
        ->assertJsonPath('summary.generated', 12)
        ->assertJsonPath('summary.remaining', 0);

    $this->get(route('election.role-demo.officer'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/RoleDemoOfficer')
            ->where('currentTally.accepted_ballots', 12)
            ->where('bulkBallots.max_count', 12)
            ->where('bulkBallots.chunk_size', 5)
            ->where('bulkBallots.rendered_pdf_limit', 3)
            ->where('bulkBallots.run.status', 'complete')
            ->where('bulkBallots.run.generated', 12)
            ->where('bulkBallots.run.remaining', 0)
        );

    $this->get(route('election.role-demo.watcher'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/RoleDemoWatcher')
            ->where('precinct.accepted_ballots', 12)
            ->where('ballotReview.record_count', 12)
            ->where('ballotReview.ballots.0.pdf_available', true)
            ->where('ballotReview.ballots.0.pdf_url', route('election.role-demo.watcher.ballot', ['sequence' => 1]))
            ->where('ballotReview.ballots.2.pdf_available', true)
            ->where('ballotReview.ballots.3.pdf_available', false)
            ->where('ballotReview.ballots.3.pdf_url', null)
            ->has('ballotReview.qr_audit_tally')
        );

    $this->get(route('election.role-demo.watcher.ballot', ['sequence' => 1]))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->get(route('election.role-demo.watcher.ballot', ['sequence' => 4]))
        ->assertNotFound();

    expect(app(ElectionStorage::class)->files('counting/sealed'))->toHaveCount(12)
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type'))
        ->toContain('role_demo.bulk_ballots_chunk_generated')
        ->toContain('role_demo.bulk_ballot_print_simulated')
        ->not->toContain('role_demo.interim_forms_generated');
});

test('role demo bulk ballot generation respects the configured maximum', function (): void {
    config()->set('election.public_simulation.role_demo_bulk_ballots.max_count', 5);

    $this->get(route('election.role-demo.index'))->assertSuccessful();

    $this->post(route('election.role-demo.bulk-ballots'), [
        'count' => 6,
    ])
        ->assertRedirect()
        ->assertSessionHasErrors('count');

    expect(app(ElectionStorage::class)->files('counting/sealed'))->toBeEmpty();
});
