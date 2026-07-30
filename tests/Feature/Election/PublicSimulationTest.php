<?php

use App\Election\Core\ActivityJournal;
use App\Election\PublicSimulation\PublicSimulationAdmissionCapacity;
use App\Election\PublicSimulation\PublicSimulationAdmissionQueue;
use App\Election\PublicSimulation\PublicSimulationContentionReport;
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
        );
    $this->post(route('election.public-simulation.audit.select', [$round, $precinct]), $credentials)
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
        ->and($watcher->inertiaProps('ballot.contests.0.title'))->toBe('SENATOR - PHILIPPINES')
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
