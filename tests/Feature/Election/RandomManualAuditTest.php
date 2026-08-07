<?php

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Preparation\PrecinctSetupService;
use App\Election\Printing\BallotPrinter;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Tabulation\TabulationProfile;
use App\Election\Voting\BallotPayloadService;
use App\Election\Voting\BallotQrPayload;
use App\Election\Voting\SealedBallotBox;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('election.tabulation.profile', TabulationProfile::DeviceTabulationWithPaperAudit->value);
    app(ElectionStorage::class)->reset();
    app(ElectionClock::class)->unfreeze();
    app(ActivateSamplePackage::class)->handle();
    app(PrecinctSetupService::class)->record(config('election.simulation.precinct_setup'));
    app(LifecycleState::class)->set(Lifecycle::Voting);

    $this->auditPayload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana'],
    ], 'rma-ballot-001');

    app(BallotPrinter::class)->print($this->auditPayload);
    app(SealedBallotBox::class)->depositPrintedPayload($this->auditPayload);
    app(LifecycleState::class)->set(Lifecycle::Counting);
});

test('compact ballot QR payload uses candidate codes and resolves without a stored ballot lookup', function (): void {
    $decoded = app(BallotQrPayload::class)->decode($this->auditPayload['qr_payload']);
    $mapping = app(ElectionStorage::class)->readJson('mappings/candidate-code-map.json');

    expect($this->auditPayload['qr_payload'])->toStartWith('aes-ballot-compact-1:')
        ->and($this->auditPayload['qr_payload'])->toContain('CAND')
        ->and($mapping['schema_version'])->toBe('candidate-code-map-1')
        ->and($mapping['candidates'])->toHaveKey('CAND00001')
        ->and($decoded['schema_version'])->toBe('ballot-payload-compact-1')
        ->and($decoded['payload_hash_profile'])->toBe('compact-selection-1')
        ->and($decoded['precinct_id'])->toBe($this->auditPayload['precinct_id'])
        ->and($decoded['paper_ballot_serial'])->toBe($this->auditPayload['paper_ballot_serial'])
        ->and($decoded['candidate_codes'])->not->toBeEmpty()
        ->and($decoded['selections'])->toBe($this->auditPayload['selections'])
        ->and($decoded['ballot_id'])->not->toBe($this->auditPayload['ballot_id']);
});

test('a QR-assisted random manual audit writes a separately dual-approved audit record', function (): void {
    $this->post(route('election.counting.rma.select-sample'))
        ->assertRedirect(route('election.counting'))
        ->assertSessionHas('rma_feedback.status', 'sample-selected');

    $this->post(route('election.counting.rma.propose'), [
        'payload' => $this->auditPayload['qr_payload'],
    ])->assertRedirect(route('election.counting'))
        ->assertSessionHas('rma_feedback.status', 'proposed');

    $this->get(route('election.counting'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Counting')
            ->where('randomManualAudit.enabled', true)
            ->where('randomManualAudit.sample_selection.sample_size', 1)
            ->where('randomManualAudit.proposed_ballots', 1)
            ->where('randomManualAudit.pending_proposal.ballot_id', 'rma-ballot-001')
            ->where('randomManualAudit.pending_proposal.selections.president.0', 'pres-ada')
        );

    $this->post(route('election.counting.rma.approve'), [
        'payload_hash' => $this->auditPayload['payload_hash'],
        'paper_matches_payload' => true,
        'first_officer_code' => 'SIM-OFFICER-001',
        'first_officer_pin' => '123456',
        'second_officer_code' => 'SIM-OFFICER-002',
        'second_officer_pin' => '123456',
    ])->assertRedirect(route('election.counting'))
        ->assertSessionHas('rma_feedback.status', 'approved');

    $storage = app(ElectionStorage::class);
    $record = json_decode(file_get_contents($storage->files('rma/accepted')[0]), true, flags: JSON_THROW_ON_ERROR);

    expect($storage->files('counting/accepted'))->toBeEmpty()
        ->and($record['schema_version'])->toBe('random-manual-audit-record-1')
        ->and($record['paper_comparison_confirmed'])->toBeTrue()
        ->and($record['approvals'])->toHaveCount(2)
        ->and($record['approvals'][0]['code_hash'])->not->toBe($record['approvals'][1]['code_hash'])
        ->and($record['selections']['president'])->toBe(['pres-ada'])
        ->and($record['artifact_path'])->toContain('12-audit-and-reconciliation/random-manual-audit/accepted')
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type')->all())
        ->toContain('rma.ballot_proposed', 'rma.ballot_approved');

    $scanRecord = json_decode(file_get_contents($storage->files('rma/scans')[0]), true, flags: JSON_THROW_ON_ERROR);
    $auditTally = $storage->readJson('rma/audit-tally.json');

    expect($scanRecord['schema_version'])->toBe('random-manual-audit-scan-1')
        ->and($scanRecord['candidate_codes'])->not->toBeEmpty()
        ->and($scanRecord['resolved_selections']['president'])->toBe(['pres-ada'])
        ->and($auditTally['schema_version'])->toBe('random-manual-audit-tally-1')
        ->and($auditTally['accepted_scans'])->toBe(1)
        ->and($auditTally['latest']['candidate_ids'])->toContain('pres-ada')
        ->and($auditTally['tally']['president']['pres-ada'])->toBe(1)
        ->and(file_get_contents($storage->path('rma/audit-tally.pdf')))->toStartWith('%PDF-');

    $this->post(route('election.counting.rma.reconciliation-report'))
        ->assertRedirect(route('election.counting'))
        ->assertSessionHas('rma_feedback.status', 'reconciliation-generated');

    $reconciliation = $storage->readJson('rma/reconciliation-report.json');

    expect($reconciliation['passed'])->toBeTrue()
        ->and($reconciliation['complete'])->toBeTrue()
        ->and($reconciliation['verified_ballots'])->toBe(1)
        ->and($reconciliation['entries'][0]['status'])->toBe('verified')
        ->and($reconciliation['artifact_path'])->toContain('12-audit-and-reconciliation/random-manual-audit')
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type')->all())
        ->toContain('rma.reconciliation_report_generated');

    $this->post(route('election.counting.rma.evidence-pack.build'))
        ->assertRedirect(route('election.counting'))
        ->assertSessionHas('rma_feedback.status', 'evidence-pack-built');

    $evidencePack = $storage->readJson('rma/evidence-pack.json');

    expect($evidencePack['schema_version'])->toBe('random-manual-audit-evidence-pack-1')
        ->and($evidencePack['sample_selection']['sample_hash'])->toBe($reconciliation['sample_hash'])
        ->and($evidencePack['reconciliation_report']['report_hash'])->toBe($reconciliation['report_hash'])
        ->and($evidencePack['approved_paper_comparisons'])->toHaveCount(1)
        ->and($evidencePack['paper_discrepancies'])->toBeEmpty()
        ->and(file_get_contents($storage->path('rma/evidence-pack.pdf')))
        ->toStartWith('%PDF-');

    $this->get(route('election.counting.rma.evidence-pack.download'))
        ->assertDownload('random-manual-audit-evidence-pack.json');
    $this->get(route('election.counting.rma.evidence-pack.print'))
        ->assertDownload('random-manual-audit-evidence-pack.pdf');

    $this->get(route('election.watchers'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Watcher')
            ->where('randomManualAudit.available', true)
            ->where('randomManualAudit.sample_size', 1)
            ->where('randomManualAudit.reconciliation.passed', true)
            ->where('randomManualAudit.evidence_pack_available', true)
            ->where('tallyAvailable', true)
            ->where('tally.accepted_ballots', 1)
            ->where('tally.tally.president.pres-ada', 1)
            ->missing('randomManualAudit.sample_selection')
            ->missing('randomManualAudit.approved_paper_comparisons')
        );
    $this->get(route('election.watchers.rma.evidence-pack.download'))
        ->assertDownload('random-manual-audit-evidence-pack.json');
    $this->get(route('election.watchers.rma.evidence-pack.print'))
        ->assertDownload('random-manual-audit-evidence-pack.pdf');
    $this->get(route('election.watchers.tally-sheet.download'))->assertDownload('precinct-tally-sheet.pdf');
    $this->get(route('election.watchers.tally.download'))->assertDownload('precinct-tally.json');

    $this->post(route('election.watchers.rma.evidence-pack.verify'), [
        'evidence_pack' => UploadedFile::fake()->createWithContent(
            'random-manual-audit-evidence-pack.json',
            json_encode($evidencePack, JSON_THROW_ON_ERROR),
        ),
    ])->assertRedirect(route('election.watchers'))
        ->assertSessionHas('rma_verification.passed', true);

    $this->get(route('election.watchers'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('randomManualAuditVerification.passed', true)
            ->where('randomManualAuditVerification.verified_ballots', 1)
        );

    $tamperedPack = [...$evidencePack, 'evidence_pack_hash' => str_repeat('0', 64)];

    $this->post(route('election.watchers.rma.evidence-pack.verify'), [
        'evidence_pack' => UploadedFile::fake()->createWithContent(
            'tampered-random-manual-audit-evidence-pack.json',
            json_encode($tamperedPack, JSON_THROW_ON_ERROR),
        ),
    ])->assertRedirect(route('election.watchers'))
        ->assertSessionHas('rma_verification.passed', false)
        ->assertSessionHas('rma_verification.errors', fn (array $errors): bool => str_contains($errors[0], 'hash'));

    $internallyTamperedPack = $evidencePack;
    $internallyTamperedPack['approved_paper_comparisons'][0]['selections']['president'] = ['tampered-candidate'];
    $internallyTamperedPack['evidence_pack_hash'] = app(CanonicalJson::class)->hash(
        array_diff_key($internallyTamperedPack, ['evidence_pack_hash' => true]),
    );

    $this->post(route('election.watchers.rma.evidence-pack.verify'), [
        'evidence_pack' => UploadedFile::fake()->createWithContent(
            'internally-tampered-random-manual-audit-evidence-pack.json',
            json_encode($internallyTamperedPack, JSON_THROW_ON_ERROR),
        ),
    ])->assertRedirect(route('election.watchers'))
        ->assertSessionHas('rma_verification.passed', false)
        ->assertSessionHas('rma_verification.errors', fn (array $errors): bool => str_contains($errors[0], 'approved'));

    $offlineReportPath = storage_path('app/election-testing/offline-rma-verification.json');

    $this->artisan('election:rma-pack-verify', [
        'path' => $storage->path('rma/evidence-pack.json'),
        '--report' => $offlineReportPath,
    ])->expectsOutput('Random manual audit evidence pack verified.')
        ->expectsOutput('Sample size: 1')
        ->assertSuccessful();

    expect(json_decode(file_get_contents($offlineReportPath), true, flags: JSON_THROW_ON_ERROR)['passed'])->toBeTrue();

    $this->get(route('election.counting'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('randomManualAudit.proposed_ballots', 0)
            ->where('randomManualAudit.approved_ballots', 1)
            ->where('randomManualAudit.audit_tally.accepted_scans', 1)
            ->where('randomManualAudit.audit_tally.latest.candidate_ids', fn (mixed $candidateIds): bool => collect($candidateIds)->contains('pres-ada'))
            ->where('randomManualAudit.tally.president.pres-ada', 1)
            ->where('randomManualAudit.reconciliation_report.passed', true)
            ->where('randomManualAudit.evidence_pack.artifact_count', 3)
        );
});

test('a random manual audit requires two distinct valid officer approvals', function (): void {
    $this->post(route('election.counting.rma.select-sample'));

    $this->post(route('election.counting.rma.propose'), [
        'payload' => $this->auditPayload['qr_payload'],
    ])->assertRedirect(route('election.counting'));

    $this->post(route('election.counting.rma.approve'), [
        'payload_hash' => $this->auditPayload['payload_hash'],
        'paper_matches_payload' => true,
        'first_officer_code' => 'SIM-OFFICER-001',
        'first_officer_pin' => '123456',
        'second_officer_code' => 'SIM-OFFICER-001',
        'second_officer_pin' => '123456',
    ])->assertSessionHasErrors('second_officer_code');

    expect(app(ElectionStorage::class)->files('rma/accepted'))->toBeEmpty();
});

test('a random manual audit rejects QR codes absent from the device tabulation record', function (): void {
    $unrecordedPayload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana'],
    ], 'rma-unrecorded-001');

    $this->post(route('election.counting.rma.select-sample'));

    $this->post(route('election.counting.rma.propose'), [
        'payload' => $unrecordedPayload['qr_payload'],
    ])->assertSessionHasErrors('payload');

    expect(app(ElectionStorage::class)->files('rma/proposals'))->toBeEmpty();
});

test('a random manual audit is available only during the counting ceremony', function (): void {
    app(LifecycleState::class)->set(Lifecycle::Voting);

    $this->post(route('election.counting.rma.propose'), [
        'payload' => $this->auditPayload['qr_payload'],
    ])->assertSessionHasErrors('lifecycle');

    expect(app(ElectionStorage::class)->files('rma/proposals'))->toBeEmpty();
});

test('the recorded audit sample is deterministic and excludes non-selected ballots', function (): void {
    $secondPayload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana'],
    ], 'rma-ballot-002');
    app(BallotPrinter::class)->print($secondPayload);
    app(SealedBallotBox::class)->depositPrintedPayload($secondPayload);

    $this->post(route('election.counting.rma.select-sample'))
        ->assertRedirect(route('election.counting'));

    $storage = app(ElectionStorage::class);
    $firstSelection = $storage->readJson('rma/sample-selection.json');

    $this->post(route('election.counting.rma.select-sample'))
        ->assertRedirect(route('election.counting'));

    $secondSelection = $storage->readJson('rma/sample-selection.json');
    $selectedHash = $firstSelection['selected_ballots'][0]['payload_hash'];
    $unselectedPayload = $selectedHash === $this->auditPayload['payload_hash']
        ? $secondPayload
        : $this->auditPayload;

    expect($firstSelection['source_record_count'])->toBe(2)
        ->and($firstSelection['sample_size'])->toBe(1)
        ->and($secondSelection['sample_hash'])->toBe($firstSelection['sample_hash']);

    $this->post(route('election.counting.rma.propose'), [
        'payload' => $unselectedPayload['qr_payload'],
    ])->assertSessionHasErrors('payload');
});

test('a paper discrepancy is dual-approved and remains outside the audit tally', function (): void {
    $this->post(route('election.counting.rma.select-sample'));
    $this->post(route('election.counting.rma.propose'), [
        'payload' => $this->auditPayload['qr_payload'],
    ]);

    $this->post(route('election.counting.rma.discrepancy'), [
        'payload_hash' => $this->auditPayload['payload_hash'],
        'reason' => 'The printed contest mark does not match the decoded selection.',
        'first_officer_code' => 'SIM-OFFICER-001',
        'first_officer_pin' => '123456',
        'second_officer_code' => 'SIM-OFFICER-002',
        'second_officer_pin' => '123456',
    ])->assertRedirect(route('election.counting'))
        ->assertSessionHas('rma_feedback.status', 'discrepancy-recorded');

    $storage = app(ElectionStorage::class);
    $record = json_decode(file_get_contents($storage->files('rma/discrepancies')[0]), true, flags: JSON_THROW_ON_ERROR);

    expect($storage->files('rma/accepted'))->toBeEmpty()
        ->and($record['schema_version'])->toBe('random-manual-audit-discrepancy-1')
        ->and($record['approvals'])->toHaveCount(2)
        ->and($record['artifact_path'])->toContain('12-audit-and-reconciliation/random-manual-audit/discrepancies')
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type')->all())
        ->toContain('rma.paper_discrepancy_recorded');

    $this->post(route('election.counting.rma.reconciliation-report'));
    $reconciliation = $storage->readJson('rma/reconciliation-report.json');

    expect($reconciliation['passed'])->toBeFalse()
        ->and($reconciliation['complete'])->toBeTrue()
        ->and($reconciliation['discrepancy_ballots'])->toBe(1)
        ->and($reconciliation['entries'][0]['status'])->toBe('paper-discrepancy-recorded');
});
