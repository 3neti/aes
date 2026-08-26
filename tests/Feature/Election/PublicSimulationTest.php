<?php

use App\Election\Core\ActivityJournal;
use App\Election\PublicSimulation\PublicSimulationAdmissionCapacity;
use App\Election\PublicSimulation\PublicSimulationAdmissionIntake;
use App\Election\PublicSimulation\PublicSimulationAdmissionQueue;
use App\Election\PublicSimulation\PublicSimulationContentionReport;
use App\Election\PublicSimulation\PublicSimulationObservationReview;
use App\Election\PublicSimulation\PublicSimulationOperationalObservation;
use App\Election\PublicSimulation\PublicSimulationParticipation;
use App\Election\PublicSimulation\PublicSimulationRetentionReview;
use App\Election\PublicSimulation\PublicSimulationReviewKit;
use App\Election\PublicSimulation\PublicSimulationScope;
use App\Election\PublicSimulation\PublicVvdatAuditExportVerifier;
use App\Election\Support\ElectionStorage;
use App\Election\Tabulation\DeviceTabulationLedger;
use App\Election\Voting\AnonymousVoterAuthorization;
use App\Election\Voting\StandardQrCode;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Illuminate\Support\Facades\Crypt;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('election.review.access.enabled', false);
    config()->set('election.public_simulation.enabled', true);
    config()->set('election.public_simulation.participation_required', false);
    config()->set('election.devices.printer.driver', 'file');
    $this->withoutVite();
});

test('a public precinct keeps its voting, VVDAT, tally, and return evidence isolated', function (): void {
    $this->get(route('election.public-simulation.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/PublicSimulationLobby')
            ->has('round.precincts', 3)
        );

    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $credentials = [
        'officer_code' => $precinct->officer_code,
        'officer_pin' => '123456',
    ];

    $this->post(route('election.public-simulation.open', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.public-simulation.show', [$round, $precinct]));

    $precinct->refresh();
    expect($precinct->status)->toBe('open');

    $this->post(route('election.public-simulation.admit', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.public-simulation.show', [$round, $precinct]));

    $authorization = session('public_simulation.control_number');
    expect($authorization)->toBeArray()->and($authorization['code'])->toMatch('/^[0-9]{4}$/');

    $this->post(route('election.public-simulation.voter.claim', [$round, $precinct]), [
        'code' => $authorization['code'],
    ])->assertRedirect(route('election.public-simulation.voter.ballot', [$round, $precinct]));

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

    $this->post(route('election.public-simulation.voter.finalize', [$round, $precinct]), [
        'selections' => $selections,
    ])->assertRedirect(route('election.public-simulation.voter.complete', [$round, $precinct]));

    $release = session("public_simulation.{$precinct->id}.release");
    expect($release)->toBeArray()->and($release['release_code'])->toBeString();

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $privateRelease = app(ElectionStorage::class)->readJson("print-releases/{$release['release_id']}.json");
    $auditQrPayload = json_decode(Crypt::decryptString($privateRelease['encrypted_payload']), true, flags: JSON_THROW_ON_ERROR)['qr_payload'];

    $this->post(route('election.public-simulation.print.redeem', [$round, $precinct]), [
        'code' => $release['release_code'],
    ])->assertRedirect(route('election.public-simulation.print.station', [$round, $precinct]));
    $this->post(route('election.public-simulation.print.print', [$round, $precinct]))
        ->assertRedirect(route('election.public-simulation.print.station', [$round, $precinct]));
    $this->post(route('election.public-simulation.print.deposit', [$round, $precinct]))
        ->assertRedirect(route('election.public-simulation.print.station', [$round, $precinct]));

    $this->post(route('election.public-simulation.close', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.public-simulation.show', [$round, $precinct]));

    $precinct->refresh();
    expect($precinct->status)->toBe('results_ready');

    $this->get(route('election.public-simulation.audit.show', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/PublicSimulationRandomManualAudit')
            ->where('audit.sample', [])
            ->where('officerDefaults.assigned.officer_code', $precinct->officer_code)
            ->where('officerDefaults.assigned.officer_pin', '123456')
            ->where('officerDefaults.first_board.officer_code', 'SIM-OFFICER-001')
            ->where('officerDefaults.first_board.officer_pin', '123456')
            ->where('officerDefaults.second_board.officer_code', 'SIM-OFFICER-002')
            ->where('officerDefaults.second_board.officer_pin', '123456')
        );
    $this->post(route('election.public-simulation.audit.select', [$round, $precinct]), [
        'officer_code' => 'SIM-OFFICER-001',
        'officer_pin' => '123456',
    ])
        ->assertRedirect(route('election.public-simulation.audit.show', [$round, $precinct]));
    config()->set('election.devices.scanner.driver', 'camera');
    $this->post(route('election.public-simulation.audit.propose', [$round, $precinct]), [
        ...$credentials,
        'payload' => 'data:image/png;base64,'.base64_encode(app(StandardQrCode::class)->renderPng($auditQrPayload)),
    ])->assertRedirect(route('election.public-simulation.audit.show', [$round, $precinct]));
    $this->post(route('election.public-simulation.audit.discrepancy', [$round, $precinct]), [
        ...$credentials,
        'payload_hash' => $privateRelease['payload_hash'],
        'reason' => 'Simulation paper comparison intentionally recorded for review.',
        'first_officer_code' => 'SIM-OFFICER-001',
        'first_officer_pin' => '123456',
        'second_officer_code' => 'SIM-OFFICER-002',
        'second_officer_pin' => '123456',
    ])->assertRedirect(route('election.public-simulation.audit.show', [$round, $precinct]));
    $this->post(route('election.public-simulation.audit.reconcile', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.public-simulation.audit.show', [$round, $precinct]));
    $this->post(route('election.public-simulation.audit.evidence-pack', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.public-simulation.audit.show', [$round, $precinct]));
    $this->get(route('election.public-simulation.audit.download', [$round, $precinct]))
        ->assertDownload("{$precinct->code}-random-manual-audit.pdf");

    $this->get(route('election.public-simulation.watcher.show', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/PublicSimulationWatcher')
            ->where('published', false)
        );

    $this->post(route('election.public-simulation.publish', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.public-simulation.show', [$round, $precinct]));
    $this->post(route('election.public-simulation.audit.publish', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.public-simulation.audit.show', [$round, $precinct]));

    $watcher = $this->get(route('election.public-simulation.watcher.show', [$round, $precinct]))
        ->assertSuccessful();
    expect($watcher->inertiaProps('published'))->toBeTrue()
        ->and($watcher->inertiaProps('ballot.contests.0.title'))->toBe('PRESIDENT - PHILIPPINES')
        ->and($watcher->inertiaProps('randomManualAudit.discrepancy_ballots'))->toBe(1)
        ->and($watcher->inertiaProps('randomManualAudit.passed'))->toBeFalse();
    expect(array_keys($watcher->inertiaProps('randomManualAudit')))->toBe([
        'sample_hash',
        'sample_size',
        'source_record_count',
        'verified_ballots',
        'discrepancy_ballots',
        'pending_ballots',
        'device_record_issues',
        'complete',
        'passed',
        'outcome',
        'summary_hash',
        'privacy_notice',
    ]);
    $this->get(route('election.public-simulation.watcher.rma-audit', [$round, $precinct]))
        ->assertDownload("{$precinct->code}-random-manual-audit-summary.pdf");

    $precinct->refresh();
    expect($precinct->status)->toBe('published');

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $storage = app(ElectionStorage::class);
    $configuration = $storage->readJson('runtime/active-precinct.json');
    $return = $storage->readJson("returns/{$configuration['precinct_id']}-return.json");

    expect($storage->files('device-tabulation-ledger'))->toHaveCount(1)
        ->and($storage->readJson('counting/vvdat-ledger-freeze.json')['record_count'])->toBe(1)
        ->and($storage->readJson('returns/publication-manifest.json')['precinct_code'])->toBe($precinct->code)
        ->and($storage->readJson('returns/public-rma-audit-summary.json')['discrepancy_ballots'])->toBe(1)
        ->and($storage->readJson('returns/public-rma-audit-summary.json'))->not->toHaveKeys(['paper_ballot_serial', 'payload_hash', 'selections', 'officer_identities'])
        ->and($storage->path('runtime/tally-sheet.pdf'))->toBeReadableFile()
        ->and($return['accepted_ballots'])->toBe(1)
        ->and($return['tally_source'])->toContain('VVDAT');

    expect(fn () => app(DeviceTabulationLedger::class)->recordDepositedBallot([]))
        ->toThrow(RuntimeException::class, 'VVDAT ledger is frozen');

    config()->set('election.public_simulation.vvdat_audit_export.minimum_records', 2);
    $this->get(route('election.public-simulation.watcher.show', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auditExportAvailable', false)
        );
    $this->get(route('election.public-simulation.watcher.vvdat-audit-export', [$round, $precinct]))
        ->assertNotFound();

    config()->set('election.public_simulation.vvdat_audit_export.minimum_records', 1);

    $this->get(route('election.public-simulation.watcher.vvdat-audit-export', [$round, $precinct]))
        ->assertSuccessful();

    $export = $storage->readJson('returns/vvdat-audit-export.json');
    expect($export['record_count'])->toBe(1)
        ->and($export['records'][0])->toHaveKeys(['record_hash', 'selections'])
        ->and($export['records'][0])->not->toHaveKeys(['ballot_id', 'paper_ballot_serial', 'recorded_at']);

    expect(app(PublicVvdatAuditExportVerifier::class)->verify(json_encode($export, JSON_THROW_ON_ERROR))['passed'])->toBeTrue();

    $this->artisan('election:public-simulation:verify-vvdat-export', ['path' => $export['artifact_path']])
        ->expectsOutput('VVDAT audit export verified for 1 records.')
        ->assertSuccessful();
});

test('a precinct admission capacity is reserved atomically inside the scoped election lock', function (): void {
    config()->set('election.public_simulation.maximum_active_admissions', 1);
    $round = SimulationRound::factory()->create();
    $precinct = SimulationPrecinct::factory()->for($round, 'round')->create();

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $storage = app(ElectionStorage::class);
    $storage->reset();
    $first = app(PublicSimulationAdmissionCapacity::class)->issue(app(AnonymousVoterAuthorization::class));

    expect($first['code'])->toMatch('/^[0-9]{4}$/')
        ->and(fn () => app(PublicSimulationAdmissionCapacity::class)->issue(app(AnonymousVoterAuthorization::class)))
        ->toThrow(RuntimeException::class, 'active voter-admission limit');
});

test('a voter completion refresh after precinct close shows a safe closed precinct screen', function (): void {
    $this->get(route('election.public-simulation.index'))->assertSuccessful();

    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $credentials = [
        'officer_code' => $precinct->officer_code,
        'officer_pin' => '123456',
    ];

    $this->post(route('election.public-simulation.open', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.public-simulation.show', [$round, $precinct]));

    $this->post(route('election.public-simulation.close', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.public-simulation.show', [$round, $precinct]));

    $precinct->refresh();
    expect($precinct->status)->toBe('results_ready');

    $this->get(route('election.public-simulation.voter.complete', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/VoterComplete')
            ->where('precinctClosed', true)
            ->where('release', null)
            ->where('precinct.code', $precinct->code)
            ->where('returnAction', route('election.public-simulation.show', [$round, $precinct]))
            ->missing('release.release_code')
            ->missing('release.release_qr_data_uri')
        );
});

test('watcher ballot review can show a QR-derived ballot carousel before close for demo mode', function (): void {
    config()->set('election.public_simulation.watcher_ballot_viewer.during_voting', true);

    $this->get(route('election.public-simulation.index'))->assertSuccessful();

    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $credentials = ['officer_code' => $precinct->officer_code, 'officer_pin' => '123456'];
    $this->post(route('election.public-simulation.open', [$round, $precinct]), $credentials)
        ->assertRedirect();

    publicSimulationDepositBallot($this, $round, $precinct, 0);
    publicSimulationDepositBallot($this, $round, $precinct, 1);

    $watcher = $this->get(route('election.public-simulation.watcher.show', [$round, $precinct]))
        ->assertSuccessful();

    expect($watcher->inertiaProps('published'))->toBeFalse()
        ->and($watcher->inertiaProps('demoTransparencyMode'))->toBeTrue()
        ->and($watcher->inertiaProps('ballotReview.allowed'))->toBeTrue()
        ->and($watcher->inertiaProps('ballotReview.record_count'))->toBe(2)
        ->and($watcher->inertiaProps('ballotReview.ballots.0.sequence'))->toBe(1)
        ->and($watcher->inertiaProps('ballotReview.ballots.1.sequence'))->toBe(2)
        ->and($watcher->inertiaProps('ballotReview.ballots.0.qr_decode_status'))->toBe('decoded')
        ->and($watcher->inertiaProps('ballotReview.ballots.0.selected_candidates.0.candidates.0.name'))->toContain('1 ')
        ->and($watcher->inertiaProps('ballotReview.ballots.0.cumulative_tally'))->not->toBeEmpty()
        ->and($watcher->inertiaProps('ballotReview.ballots.1.cumulative_tally'))->not->toBeEmpty()
        ->and($watcher->inertiaProps('ballotReview.ballots.0.pdf_url'))->toContain('/watch/ballots/1');

    $this->get(route('election.public-simulation.watcher.ballot-pdf', [
        $round,
        $precinct,
        'sequence' => 1,
    ]))->assertSuccessful()
        ->assertHeader('content-disposition', 'inline; filename="'.$precinct->code.'-ballot-001.pdf"');
});

test('watcher ballot review is locked before close when demo transparency is disabled', function (): void {
    config()->set('election.public_simulation.watcher_ballot_viewer.during_voting', false);

    $this->get(route('election.public-simulation.index'))->assertSuccessful();

    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $credentials = ['officer_code' => $precinct->officer_code, 'officer_pin' => '123456'];
    $this->post(route('election.public-simulation.open', [$round, $precinct]), $credentials)
        ->assertRedirect();

    publicSimulationDepositBallot($this, $round, $precinct, 0);

    $watcher = $this->get(route('election.public-simulation.watcher.show', [$round, $precinct]))
        ->assertSuccessful();

    expect($watcher->inertiaProps('demoTransparencyMode'))->toBeFalse()
        ->and($watcher->inertiaProps('ballotReview.allowed'))->toBeFalse()
        ->and($watcher->inertiaProps('ballotReview.record_count'))->toBe(0);

    $this->get(route('election.public-simulation.watcher.ballot-pdf', [
        $round,
        $precinct,
        'sequence' => 1,
    ]))->assertNotFound();
});

test('an anonymous waiting ticket is released in order without exposing a control number', function (): void {
    config()->set('election.public_simulation.maximum_active_admissions', 1);
    config()->set('election.public_simulation.admission_queue.maximum_waiting_voters', 2);

    $this->get(route('election.public-simulation.index'))->assertSuccessful();
    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $credentials = ['officer_code' => $precinct->officer_code, 'officer_pin' => '123456'];
    $this->post(route('election.public-simulation.open', [$round, $precinct]), $credentials)->assertRedirect();
    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));

    $queue = app(PublicSimulationAdmissionQueue::class);
    $first = $queue->join();
    $second = $queue->join();
    $release = $queue->releaseNext(app(AnonymousVoterAuthorization::class));

    expect($first['ticket_number'])->toBe('001')
        ->and($second['ticket_number'])->toBe('002')
        ->and($release['ticket']['ticket_number'])->toBe('001')
        ->and($release['authorization']['code'])->toMatch('/^[0-9]{4}$/')
        ->and($queue->status($release['ticket']['ticket_id']))->toHaveKeys(['ticket_id', 'ticket_number', 'status', 'expires_at'])
        ->and($queue->status($release['ticket']['ticket_id']))->not->toHaveKey('code')
        ->and($queue->status($second['ticket_id'])['position'])->toBe(1)
        ->and(fn (): array => $queue->releaseNext(app(AnonymousVoterAuthorization::class)))
        ->toThrow(RuntimeException::class, 'active voter-admission limit');

    expect(collect(app(ActivityJournal::class)->entries())->pluck('event_type')->all())
        ->toContain('public_simulation.admission_queue_joined', 'public_simulation.admission_queue_released');
});

test('closeout serializes public voter work and refuses unresolved sessions', function (): void {
    $this->get(route('election.public-simulation.index'))->assertSuccessful();

    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $credentials = ['officer_code' => $precinct->officer_code, 'officer_pin' => '123456'];
    $this->post(route('election.public-simulation.open', [$round, $precinct]), $credentials)
        ->assertRedirect();
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

    $this->post(route('election.public-simulation.admit', [$round, $precinct]), $credentials)
        ->assertRedirect();
    $authorization = session('public_simulation.control_number');
    $this->post(route('election.public-simulation.voter.claim', [$round, $precinct]), ['code' => $authorization['code']])
        ->assertRedirect();

    $this->post(route('election.public-simulation.close', [$round, $precinct]), $credentials)
        ->assertRedirect()
        ->assertSessionHasErrors('officer_pin');
    expect($precinct->fresh()->status)->toBe('open');

    $this->post(route('election.public-simulation.voter.finalize', [$round, $precinct]), ['selections' => $selections])
        ->assertRedirect();
    $release = session("public_simulation.{$precinct->id}.release");
    expect($release)->toBeArray();

    $this->post(route('election.public-simulation.close', [$round, $precinct]), $credentials)
        ->assertRedirect()
        ->assertSessionHasErrors('officer_pin');
    expect($precinct->fresh()->status)->toBe('open');

    $this->post(route('election.public-simulation.print.redeem', [$round, $precinct]), ['code' => $release['release_code']])
        ->assertRedirect();
    $this->post(route('election.public-simulation.close', [$round, $precinct]), $credentials)
        ->assertRedirect()
        ->assertSessionHasErrors('officer_pin');
    expect($precinct->fresh()->status)->toBe('open');

    $this->post(route('election.public-simulation.print.print', [$round, $precinct]))->assertRedirect();
    $this->post(route('election.public-simulation.print.deposit', [$round, $precinct]))->assertRedirect();

    $this->post(route('election.public-simulation.admit', [$round, $precinct]), $credentials)
        ->assertRedirect();
    $lateAuthorization = session('public_simulation.control_number');

    $this->post(route('election.public-simulation.close', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.public-simulation.show', [$round, $precinct]));
    $this->post(route('election.public-simulation.voter.claim', [$round, $precinct]), ['code' => $lateAuthorization['code']])
        ->assertRedirect()
        ->assertSessionHasErrors('code');

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    expect($precinct->fresh()->status)->toBe('results_ready')
        ->and(app(ElectionStorage::class)->files('device-tabulation-ledger'))->toHaveCount(1)
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type')->all())
        ->toContain('public_simulation.close_blocked_pending_voters');
});

test('demo room can force finalize unresolved voter work before closeout', function (): void {
    $this->get(route('election.demo-room.index'))->assertSuccessful();

    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $credentials = ['officer_code' => $precinct->officer_code, 'officer_pin' => '123456'];
    $this->post(route('election.demo-room.open', [$round, $precinct]), $credentials)->assertRedirect();
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

    $this->post(route('election.demo-room.admit', [$round, $precinct]), $credentials)->assertRedirect();
    $pendingAuthorization = session('public_simulation.control_number');
    $this->post(route('election.public-simulation.voter.claim', [$round, $precinct]), ['code' => $pendingAuthorization['code']])->assertRedirect();
    $this->post(route('election.public-simulation.voter.finalize', [$round, $precinct]), ['selections' => $selections])->assertRedirect();

    $this->post(route('election.demo-room.admit', [$round, $precinct]), $credentials)->assertRedirect();
    $printedAuthorization = session('public_simulation.control_number');
    $this->post(route('election.public-simulation.voter.claim', [$round, $precinct]), ['code' => $printedAuthorization['code']])->assertRedirect();
    $this->post(route('election.public-simulation.voter.finalize', [$round, $precinct]), ['selections' => $selections])->assertRedirect();
    $release = session("public_simulation.{$precinct->id}.release");
    $this->post(route('election.public-simulation.print.redeem', [$round, $precinct]), ['code' => $release['release_code']])->assertRedirect();
    $this->post(route('election.public-simulation.print.print', [$round, $precinct]))->assertRedirect();

    $this->post(route('election.demo-room.admit', [$round, $precinct]), $credentials)->assertRedirect();
    $unfinishedAuthorization = session('public_simulation.control_number');
    $this->post(route('election.public-simulation.voter.claim', [$round, $precinct]), ['code' => $unfinishedAuthorization['code']])->assertRedirect();

    $this->post(route('election.demo-room.close', [$round, $precinct]), $credentials)
        ->assertRedirect()
        ->assertSessionHasErrors('officer_pin');

    $this->post(route('election.demo-room.force-close', [$round, $precinct]), [
        ...$credentials,
        'confirm_force_closeout' => 'FINALIZE',
    ])->assertRedirect(route('election.demo-room.officer', [$round, $precinct]));

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $storage = app(ElectionStorage::class);

    expect($precinct->fresh()->status)->toBe('results_ready')
        ->and($storage->files('device-tabulation-ledger'))->toHaveCount(2)
        ->and($storage->readJson("voter-authorizations/{$unfinishedAuthorization['authorization_id']}.json")['status'])->toBe('cancelled_by_officer_closeout')
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type')->all())
        ->toContain('demo_room.force_closeout_requested', 'demo_room.force_closeout_completed');
});

test('officer and god mode screens show the fixed booth print PIN handoff without private data', function (): void {
    config()->set('election.public_simulation.god_mode.enabled', true);
    $this->get(route('election.public-simulation.index'))->assertSuccessful();

    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $credentials = ['officer_code' => $precinct->officer_code, 'officer_pin' => '123456'];
    $this->post(route('election.public-simulation.open', [$round, $precinct]), $credentials)->assertRedirect();
    $this->post(route('election.public-simulation.admit', [$round, $precinct]), $credentials)->assertRedirect();
    $authorization = session('public_simulation.control_number');

    $this->post(route('election.public-simulation.voter.claim', [$round, $precinct]), ['code' => $authorization['code']])
        ->assertRedirect();

    $this->get(route('election.public-simulation.show', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('operationsBoard.booths.active', 1)
            ->where('operationsBoard.print_station.pending_pins', 0)
            ->where('operationsBoard.closeout.can_close', false)
            ->where('operationsBoard.closeout.next_required_action', 'Wait for every voter booth to finalize or reset.')
            ->missing('operationsBoard.control_number')
            ->missing('operationsBoard.release_code')
            ->reloadOnly('operationsBoard', fn (Assert $reload) => $reload
                ->has('operationsBoard')
                ->missing('precinct')
            )
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

    $this->post(route('election.public-simulation.voter.finalize', [$round, $precinct]), ['selections' => $selections])
        ->assertRedirect();
    $release = session("public_simulation.{$precinct->id}.release");

    $this->get(route('election.public-simulation.god-mode', $round))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('round.precincts.0.operations_board.booths.active', 0)
            ->where('round.precincts.0.operations_board.print_station.pending_pins', 1)
            ->where('round.precincts.0.operations_board.closeout.next_required_action', 'Send voters with print PINs to the central print station.')
            ->where('round.precincts.0.operations_board.timeline.0.label', 'Officer issued a voter control number')
            ->missing('round.precincts.0.operations_board.timeline.0.payload')
            ->reloadOnly('round', fn (Assert $reload) => $reload
                ->has('round.precincts.0.operations_board')
                ->missing('privacyNotice')
            )
        );

    $this->post(route('election.public-simulation.print.redeem', [$round, $precinct]), ['code' => $release['release_code']])
        ->assertRedirect();

    $this->get(route('election.public-simulation.show', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('operationsBoard.print_station.pending_pins', 0)
            ->where('operationsBoard.print_station.redeemed_pins', 1)
            ->where('operationsBoard.closeout.next_required_action', 'Print the claimed paper ballots at the central print station.')
        );

    $this->post(route('election.public-simulation.print.print', [$round, $precinct]))->assertRedirect();

    $this->get(route('election.public-simulation.show', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('operationsBoard.print_station.redeemed_pins', 0)
            ->where('operationsBoard.print_station.printed_awaiting_deposit', 1)
            ->where('operationsBoard.closeout.next_required_action', 'Verify and deposit every printed paper ballot.')
        );

    $this->post(route('election.public-simulation.print.deposit', [$round, $precinct]))->assertRedirect();

    $this->get(route('election.public-simulation.show', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('operationsBoard.print_station.printed_awaiting_deposit', 0)
            ->where('operationsBoard.print_station.deposited', 1)
            ->where('operationsBoard.closeout.can_close', true)
            ->where('operationsBoard.closeout.next_required_action', 'No unresolved voter work. The officer may close polls when ready.')
        );
});

test('an officer contention report preserves only aggregate public simulation pressure', function (): void {
    config()->set('election.public_simulation.maximum_active_admissions', 10);
    config()->set('election.public_simulation.admission_queue.maximum_waiting_voters', 25);
    $this->get(route('election.public-simulation.index'))->assertSuccessful();

    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $credentials = ['officer_code' => $precinct->officer_code, 'officer_pin' => '123456'];
    $this->post(route('election.public-simulation.open', [$round, $precinct]), $credentials)
        ->assertRedirect();
    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));

    $queue = app(PublicSimulationAdmissionQueue::class);
    $ticket = $queue->join();
    $release = $queue->releaseNext(app(AnonymousVoterAuthorization::class));
    app(AnonymousVoterAuthorization::class)->claim($release['authorization']['code']);

    $this->post(route('election.public-simulation.close', [$round, $precinct]), $credentials)
        ->assertRedirect()
        ->assertSessionHasErrors('officer_pin');
    $this->post(route('election.public-simulation.contention-report', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.public-simulation.show', [$round, $precinct]));

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $storage = app(ElectionStorage::class);
    $report = $storage->readJson('contention-reports/000001-contention-report.json');

    expect($report['capacity'])->toMatchArray(['active_admissions' => 1, 'maximum_active_admissions' => 10, 'available_admissions' => 9])
        ->and($report['waiting_line'])->toMatchArray(['waiting_voters' => 0, 'maximum_waiting_voters' => 25])
        ->and($report['activity'])->toMatchArray([
            'control_numbers_issued' => 1,
            'tickets_joined' => 1,
            'tickets_released' => 1,
            'tickets_expired' => 0,
            'close_attempts_blocked' => 1,
        ])
        ->and($report['privacy_notice'])->toContain('aggregate counts only')
        ->and(json_encode($report, JSON_THROW_ON_ERROR))->not->toContain($ticket['ticket_id'], $release['authorization']['authorization_id'], $release['authorization']['code'])
        ->and(app(PublicSimulationContentionReport::class)->summary()['activity']['close_attempts_blocked'])->toBe(1)
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type')->all())
        ->toContain('public_simulation.contention_report_generated');
});

test('an officer can pause new anonymous tickets without invalidating issued control numbers', function (): void {
    $this->get(route('election.public-simulation.index'))->assertSuccessful();

    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $credentials = ['officer_code' => $precinct->officer_code, 'officer_pin' => '123456'];
    $this->post(route('election.public-simulation.open', [$round, $precinct]), $credentials)
        ->assertRedirect();
    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $authorization = app(PublicSimulationAdmissionCapacity::class)->issue(app(AnonymousVoterAuthorization::class));

    $this->post(route('election.public-simulation.admission-intake', [$round, $precinct]), [
        ...$credentials,
        'operation' => 'pause',
    ])->assertRedirect(route('election.public-simulation.show', [$round, $precinct]));

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    expect(app(PublicSimulationAdmissionIntake::class)->status()['status'])->toBe('paused')
        ->and(fn (): array => app(PublicSimulationAdmissionQueue::class)->join())
        ->toThrow(RuntimeException::class, 'temporarily paused')
        ->and(app(AnonymousVoterAuthorization::class)->claim($authorization['code'])['status'])->toBe('claimed');

    $this->get(route('election.public-simulation.voter.show', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('admissionQueue.status', 'paused'));
    $this->post(route('election.public-simulation.admission-intake', [$round, $precinct]), [
        ...$credentials,
        'operation' => 'resume',
    ])->assertRedirect();

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    expect(app(PublicSimulationAdmissionQueue::class)->join()['status'])->toBe('waiting')
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type')->all())
        ->toContain('public_simulation.admission_intake_paused', 'public_simulation.admission_intake_resumed');
});

test('a public voter acknowledges the simulation policy without creating identity evidence', function (): void {
    config()->set('election.public_simulation.participation_required', true);
    config()->set('election.public_simulation.retention_days', 30);
    $this->get(route('election.public-simulation.index'))->assertSuccessful();

    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);
    $credentials = ['officer_code' => $precinct->officer_code, 'officer_pin' => '123456'];
    $this->post(route('election.public-simulation.open', [$round, $precinct]), $credentials)->assertRedirect();

    $this->get(route('election.public-simulation.voter.show', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/PublicSimulationParticipation')
            ->where('policy.retention_days', 30)
        );
    $this->post(route('election.public-simulation.voter.participation.accept', [$round, $precinct]))
        ->assertRedirect(route('election.public-simulation.voter.show', [$round, $precinct]));
    $this->get(route('election.public-simulation.voter.show', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Election/VoterWelcome'));

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $policy = app(ElectionStorage::class)->readJson('public-simulation-participation-policy.json');
    expect($policy['retention_days'])->toBe(30)
        ->and($policy)->not->toHaveKeys(['voter_id', 'authorization_id', 'ballot_id', 'session_id'])
        ->and(app(PublicSimulationParticipation::class)->policy()['policy_hash'])->toBe($policy['policy_hash'])
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type')->all())
        ->toContain('public_simulation.participation_policy_published', 'public_simulation.participation_accepted');
});

test('a review kit indexes only privacy-safe public simulation artifacts', function (): void {
    $this->get(route('election.public-simulation.index'))->assertSuccessful();
    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $credentials = ['officer_code' => $precinct->officer_code, 'officer_pin' => '123456'];
    $this->post(route('election.public-simulation.open', [$round, $precinct]), $credentials)->assertRedirect();
    $this->get(route('election.public-simulation.voter.show', [$round, $precinct]))->assertSuccessful();

    $kit = app(PublicSimulationReviewKit::class)->build($round->fresh('precincts'));
    expect($kit['round'])->toMatchArray(['code' => $round->code, 'status' => 'open'])
        ->and($kit['precincts'])->toHaveCount(3)
        ->and($kit['privacy_notice'])->toContain('excludes voter identities')
        ->and(collect($kit['precincts'][0]['review_artifacts'])->contains(fn (array $artifact): bool => str_ends_with($artifact['path'], '/public-simulation-participation-policy.json')))
        ->toBeTrue()
        ->and($kit['artifact_path'])->toBeReadableFile();

    $this->artisan('election:public-simulation:review-kit', ['round' => $round->code])
        ->expectsOutputToContain("Review Kit generated for {$round->code}")
        ->assertSuccessful();

    $persisted = json_decode(file_get_contents($kit['artifact_path']), true, flags: JSON_THROW_ON_ERROR);
    expect($persisted['kit_hash'])->toBe($kit['kit_hash'])
        ->and($persisted['precincts'][0])->not->toHaveKeys(['officer_code', 'authorization_id', 'ballot_id', 'session_id']);
});

test('a retention review requires a human evidence-disposition decision without deleting anything', function (): void {
    config()->set('election.public_simulation.retention_days', 30);
    $this->get(route('election.public-simulation.index'))->assertSuccessful();
    $round = SimulationRound::query()->with('precincts')->sole();
    $round->forceFill([
        'status' => 'archived',
        'archived_at' => now()->subDays(31),
    ])->save();

    $report = app(PublicSimulationRetentionReview::class)->review($round->fresh('precincts'));
    expect($report)->toMatchArray([
        'round_code' => $round->code,
        'round_status' => 'archived',
        'retention_days' => 30,
        'review_status' => 'review_due',
        'disposition_policy' => 'manual-review-required-no-automatic-deletion',
    ])
        ->and($report['next_required_action'])->toContain('never deletes evidence automatically')
        ->and($report['artifact_path'])->toBeReadableFile()
        ->and($report)->not->toHaveKeys(['voter_id', 'authorization_id', 'ballot_id', 'session_id']);

    $this->artisan('election:public-simulation:retention-review', ['round' => $round->code])
        ->expectsOutputToContain("Retention review review_due for {$round->code}")
        ->assertSuccessful();
});

test('a field rehearsal proves active-cohort closeout protection and publishes a cohort result', function (): void {
    config()->set('election.public_simulation.maximum_active_admissions', 3);
    $this->get(route('election.public-simulation.index'))->assertSuccessful();
    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $this->artisan('election:public-simulation:field-rehearsal', [
        'round' => $round->code,
        'precinct' => $precinct->code,
        '--voters' => 3,
    ])->expectsOutputToContain("Field rehearsal completed for {$round->code}/{$precinct->code}")
        ->assertSuccessful();

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $storage = app(ElectionStorage::class);
    $report = $storage->readJson('field-rehearsals/field-rehearsal-000001.json');
    $configuration = $storage->readJson('runtime/active-precinct.json');
    $return = $storage->readJson("returns/{$configuration['precinct_id']}-return.json");

    expect($precinct->fresh()->status)->toBe('published')
        ->and($report['observations'])->toMatchArray([
            'cohort_claimed_before_completion' => 3,
            'closeout_blocked_while_active' => true,
            'private_releases_completed' => 3,
            'device_tabulated_ballots' => 3,
            'results_published' => true,
        ])
        ->and($report['evidence']['publication_manifest_hash'])->toBeString()
        ->and($report)->not->toHaveKeys(['voter_id', 'authorization_id', 'ballot_id', 'session_id', 'selections'])
        ->and($return['accepted_ballots'])->toBe(3)
        ->and($storage->path('runtime/tally-sheet.pdf'))->toBeReadableFile()
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type')->all())
        ->toContain('public_simulation.close_blocked_pending_voters', 'public_simulation.field_rehearsal_completed');
});

test('a published public precinct records a structured operational observation without journaling its note', function (): void {
    $this->get(route('election.public-simulation.index'))->assertSuccessful();
    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);
    $precinct->forceFill(['status' => 'published'])->save();

    $note = 'The shared voter entry point was clear during the facilitated rehearsal.';
    $this->post(route('election.public-simulation.observation', [$round, $precinct]), [
        'officer_code' => $precinct->officer_code,
        'officer_pin' => '123456',
        'reported_role' => 'facilitator',
        'ceremony' => 'admission',
        'assessment' => 'clear',
        'note' => $note,
    ])->assertRedirect(route('election.public-simulation.show', [$round, $precinct]));

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $storage = app(ElectionStorage::class);
    $observation = $storage->readJson('operational-observations/000001-observation.json');
    $journalEntry = collect(app(ActivityJournal::class)->entries())
        ->firstWhere('event_type', 'public_simulation.operational_observation_recorded');

    expect($observation)->toMatchArray([
        'sequence' => 1,
        'reported_role' => 'facilitator',
        'ceremony' => 'admission',
        'assessment' => 'clear',
        'note' => $note,
    ])
        ->and(app(PublicSimulationOperationalObservation::class)->summary())->toMatchArray([
            'total' => 1,
            'clear' => 1,
            'needs_attention' => 0,
            'blocking' => 0,
        ])
        ->and($journalEntry['payload'])->not->toHaveKey('note')
        ->and($journalEntry['payload']['observation_hash'])->toBe($observation['observation_hash']);
});

test('a facilitator observation review isolates follow-up notes in private audit evidence', function (): void {
    $this->get(route('election.public-simulation.index'))->assertSuccessful();
    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);
    $precinct->forceFill(['status' => 'published'])->save();
    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));

    $observations = app(PublicSimulationOperationalObservation::class);
    $observations->record('election_officer', 'admission', 'clear', 'Control-number handoff was clear.');
    $observations->record('watcher', 'results', 'needs_attention', 'The published tally should use a larger projected display.');

    $this->artisan('election:public-simulation:observation-review', [
        'round' => $round->code,
        'precinct' => $precinct->code,
    ])->expectsOutputToContain("Observation review 1 created for {$round->code}/{$precinct->code}")
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $review = $storage->readJson('observation-review/000001-observation-review.json');
    $journalEntry = collect(app(ActivityJournal::class)->entries())
        ->firstWhere('event_type', 'public_simulation.operational_observations_reviewed');

    expect($review['summary'])->toMatchArray([
        'total' => 2,
        'by_role' => ['election_officer' => 1, 'watcher' => 1],
        'by_ceremony' => ['admission' => 1, 'results' => 1],
        'by_assessment' => ['clear' => 1, 'needs_attention' => 1],
    ])
        ->and($review['follow_up_observations'])->toHaveCount(1)
        ->and($review['follow_up_observations'][0]['note'])->toBe('The published tally should use a larger projected display.')
        ->and($journalEntry['payload'])->not->toHaveKey('note')
        ->and($journalEntry['payload']['follow_up_count'])->toBe(1)
        ->and(app(PublicSimulationObservationReview::class)->build()['sequence'])->toBe(2);
});

test('a facilitator improvement backlog prioritizes reviewed public simulation findings', function (): void {
    $this->get(route('election.public-simulation.index'))->assertSuccessful();
    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);
    $precinct->forceFill(['status' => 'published'])->save();
    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));

    $observations = app(PublicSimulationOperationalObservation::class);
    $observations->record('election_officer', 'admission', 'clear', 'Control-number handoff was clear.');
    $observations->record('watcher', 'results', 'needs_attention', 'The published tally should use a larger projected display.');
    app(PublicSimulationObservationReview::class)->build();

    $this->artisan('election:public-simulation:improvement-backlog', [
        'round' => $round->code,
        'precinct' => $precinct->code,
    ])->expectsOutputToContain("Improvement backlog 1 created for {$round->code}/{$precinct->code}")
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $backlog = $storage->readJson('improvement-backlog/000001-improvement-backlog.json');
    $journalEntry = collect(app(ActivityJournal::class)->entries())
        ->firstWhere('event_type', 'public_simulation.improvement_backlog_created');

    expect($backlog['summary'])->toMatchArray([
        'total_items' => 1,
        'by_priority' => ['medium' => 1],
        'by_ceremony' => ['results' => 1],
    ])
        ->and($backlog['items'][0])->toMatchArray([
            'source_observation_sequence' => 2,
            'reported_role' => 'watcher',
            'ceremony' => 'results',
            'assessment' => 'needs_attention',
            'priority' => 'medium',
            'status' => 'open',
            'recommended_owner' => 'transparency-and-audit',
            'problem_statement' => 'The published tally should use a larger projected display.',
        ])
        ->and($journalEntry['payload'])->not->toHaveKey('problem_statement')
        ->and($journalEntry['payload']['total_items'])->toBe(1);
});

test('a ready public precinct receives a privacy-safe external usability session kit', function (): void {
    $this->get(route('election.public-simulation.index'))->assertSuccessful();
    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class)
        ->and($precinct->status)->toBe('ready');

    $this->artisan('election:public-simulation:usability-session-kit', [
        'round' => $round->code,
        'precinct' => $precinct->code,
    ])->expectsOutputToContain("Usability session kit prepared for {$round->code}/{$precinct->code}")
        ->assertSuccessful();

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $storage = app(ElectionStorage::class);
    $kit = $storage->readJson('usability-session-kit/session-kit.json');
    $guide = $storage->readText('usability-session-kit/facilitator-guide.md');
    $sheet = $storage->readText('usability-session-kit/participant-observation-sheet.md');

    expect($kit)->toMatchArray([
        'round_code' => $round->code,
        'precinct_code' => $precinct->code,
        'purpose' => 'Facilitated external usability session for the public election simulation.',
    ])
        ->and($kit['privacy_notice'])->toContain('Do not collect participant names')
        ->and($kit)->not->toHaveKeys(['officer_code', 'officer_pin', 'authorization_id', 'ballot_id'])
        ->and($guide)->toContain('## Success Criteria')
        ->and($sheet)->toContain('Record no participant name')
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type')->all())
        ->toContain('public_simulation.usability_session_kit_prepared');
});

test('a facilitated usability simulation runs the full public flow and persists report pointers', function (): void {
    $this->get(route('election.public-simulation.index'))->assertSuccessful();
    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->sortBy('code')->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $this->artisan('election:public-simulation:facilitated-usability-simulation', [
        'round' => $round->code,
        'precinct' => $precinct->code,
        '--voters' => 3,
    ])->expectsOutputToContain("Facilitated usability simulation completed for {$round->code}/{$precinct->code}")
        ->assertSuccessful();

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $storage = app(ElectionStorage::class);
    $configuration = $storage->readJson('runtime/active-precinct.json');
    $precinctId = (string) $configuration['precinct_id'];
    $report = $storage->readJson('usability-simulations/000001-facilitated-usability-simulation.json');
    $backlog = $storage->readJson('improvement-backlog/000001-improvement-backlog.json');

    expect($precinct->fresh()->status)->toBe('published')
        ->and($report)->toMatchArray([
            'simulation_kind' => 'synthetic_facilitated_usability_dry_run',
            'round_code' => $round->code,
            'precinct_code' => $precinct->code,
            'voter_cohort_size' => 3,
            'statistics' => [
                'field_rehearsal_voters' => 3,
                'device_tabulated_ballots' => 3,
                'observations_recorded' => 3,
                'follow_up_observations' => 2,
                'backlog_items' => 2,
            ],
        ])
        ->and($report['flow'])->toContain('election_return_generated', 'private_improvement_backlog_created')
        ->and($report['artifacts']['tally_sheet_pdf']['absolute_path'])->toBeReadableFile()
        ->and($report['artifacts']['election_return_pdf']['absolute_path'])->toBeReadableFile()
        ->and($storage->path("returns/{$precinctId}-return.pdf"))->toBe($report['artifacts']['election_return_pdf']['absolute_path'])
        ->and($backlog['items'])->toHaveCount(2)
        ->and($report)->not->toHaveKeys(['officer_code', 'officer_pin', 'authorization_id', 'ballot_id', 'session_id', 'selections'])
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type')->all())
        ->toContain('public_simulation.facilitated_usability_simulation_completed');
});

test('multiple public voters create independent sealed records without crossing precinct evidence roots', function (): void {
    $this->get(route('election.public-simulation.index'))->assertSuccessful();

    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    $otherPrecinct = $round->precincts->skip(1)->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class)
        ->and($otherPrecinct)->toBeInstanceOf(SimulationPrecinct::class);

    $credentials = ['officer_code' => $precinct->officer_code, 'officer_pin' => '123456'];
    $this->post(route('election.public-simulation.open', [$round, $precinct]), $credentials)
        ->assertRedirect();

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

    foreach (range(1, 3) as $voter) {
        $this->post(route('election.public-simulation.admit', [$round, $precinct]), $credentials)
            ->assertRedirect();
        $authorization = session('public_simulation.control_number');

        $this->post(route('election.public-simulation.voter.claim', [$round, $precinct]), ['code' => $authorization['code']])
            ->assertRedirect();
        $this->post(route('election.public-simulation.voter.finalize', [$round, $precinct]), ['selections' => $selections])
            ->assertRedirect();
        $release = session("public_simulation.{$precinct->id}.release");

        $this->post(route('election.public-simulation.print.redeem', [$round, $precinct]), ['code' => $release['release_code']])
            ->assertRedirect();
        $this->post(route('election.public-simulation.print.print', [$round, $precinct]))->assertRedirect();
        $this->post(route('election.public-simulation.print.deposit', [$round, $precinct]))->assertRedirect();
    }

    $this->post(route('election.public-simulation.close', [$round, $precinct]), $credentials)
        ->assertRedirect();

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $storage = app(ElectionStorage::class);
    $configuration = $storage->readJson('runtime/active-precinct.json');
    $return = $storage->readJson("returns/{$configuration['precinct_id']}-return.json");
    $contestId = array_key_first($selections);
    $candidateId = $selections[$contestId][0];

    expect($storage->files('device-tabulation-ledger'))->toHaveCount(3)
        ->and($return['accepted_ballots'])->toBe(3)
        ->and($return['tally'][$contestId][$candidateId])->toBe(3);

    app(PublicSimulationScope::class)->apply($otherPrecinct->fresh('round'));
    expect(app(ElectionStorage::class)->files('device-tabulation-ledger'))->toBeEmpty();
});

test('a public simulation round archives only after every precinct is published', function (): void {
    $round = SimulationRound::factory()->create();
    $unfinished = SimulationPrecinct::factory()->for($round, 'round')->create(['status' => 'open']);

    $this->artisan('election:public-simulation:archive', ['round' => $round->code])
        ->expectsOutputToContain('Every precinct must publish')
        ->assertFailed();

    $unfinished->forceFill(['status' => 'published'])->save();
    SimulationPrecinct::factory()->for($round, 'round')->create(['status' => 'published']);

    $this->artisan('election:public-simulation:archive', ['round' => $round->code])
        ->expectsOutputToContain("Public simulation round {$round->code} archived")
        ->assertSuccessful();

    expect($round->fresh()->status)->toBe('archived')
        ->and($round->fresh()->archived_at)->not->toBeNull();
});

test('a controlled reset archives a published public round before creating a fresh lobby', function (): void {
    $round = SimulationRound::factory()->create();
    SimulationPrecinct::factory()->count(3)->for($round, 'round')->create(['status' => 'published']);

    $this->artisan('election:public-simulation:reset', ['round' => $round->code])
        ->expectsOutputToContain("Archived {$round->code}; created fresh public simulation round")
        ->assertSuccessful();

    $fresh = SimulationRound::query()->where('status', 'open')->sole();

    expect($round->fresh()->status)->toBe('archived')
        ->and($round->fresh()->archived_at)->not->toBeNull()
        ->and($fresh->code)->not->toBe($round->code)
        ->and($fresh->precincts()->count())->toBe(3);
});

test('the facilitator god mode remains disabled until explicitly enabled', function (): void {
    $round = SimulationRound::factory()->create();
    SimulationPrecinct::factory()->for($round, 'round')->create();

    $this->get(route('election.public-simulation.god-mode', $round))->assertNotFound();

    config()->set('election.public_simulation.god_mode.enabled', true);

    $this->get(route('election.public-simulation.god-mode', $round))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/PublicSimulationGodMode')
            ->where('privacyNotice', 'This facilitator screen intentionally excludes voter selections, control numbers, print releases, paper serials, QR payloads, and participant identity.')
        );
});

function publicSimulationDepositBallot(mixed $test, SimulationRound $round, SimulationPrecinct $precinct, int $candidateOffset = 0): void
{
    $credentials = ['officer_code' => $precinct->officer_code, 'officer_pin' => '123456'];
    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $configuration = app(ElectionStorage::class)->readJson('runtime/active-precinct.json');
    $selections = collect($configuration['contests'])
        ->mapWithKeys(fn (array $contest): array => [
            $contest['id'] => collect($contest['candidates'])
                ->slice($candidateOffset)
                ->take(min(1, (int) $contest['max_selections']))
                ->pluck('id')
                ->all(),
        ])
        ->all();

    $test->post(route('election.public-simulation.admit', [$round, $precinct]), $credentials)
        ->assertRedirect();
    $authorization = session('public_simulation.control_number');

    $test->post(route('election.public-simulation.voter.claim', [$round, $precinct]), ['code' => $authorization['code']])
        ->assertRedirect();
    $test->post(route('election.public-simulation.voter.finalize', [$round, $precinct]), ['selections' => $selections])
        ->assertRedirect();
    $release = session("public_simulation.{$precinct->id}.release");

    $test->post(route('election.public-simulation.print.redeem', [$round, $precinct]), ['code' => $release['release_code']])
        ->assertRedirect();
    $test->post(route('election.public-simulation.print.print', [$round, $precinct]))
        ->assertRedirect();
    $test->post(route('election.public-simulation.print.deposit', [$round, $precinct]))
        ->assertRedirect();
}
