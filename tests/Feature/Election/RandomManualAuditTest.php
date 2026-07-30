<?php

use App\Election\Core\ActivityJournal;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Preparation\PrecinctSetupService;
use App\Election\Printing\BallotPrinter;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Tabulation\TabulationProfile;
use App\Election\Voting\BallotPayloadService;
use App\Election\Voting\SealedBallotBox;
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

    $this->get(route('election.counting'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('randomManualAudit.proposed_ballots', 0)
            ->where('randomManualAudit.approved_ballots', 1)
            ->where('randomManualAudit.tally.president.pres-ada', 1)
            ->where('randomManualAudit.reconciliation_report.passed', true)
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
