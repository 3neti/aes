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
    $this->post(route('election.counting.rma.propose'), [
        'payload' => $this->auditPayload['qr_payload'],
    ])->assertRedirect(route('election.counting'))
        ->assertSessionHas('rma_feedback.status', 'proposed');

    $this->get(route('election.counting'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Counting')
            ->where('randomManualAudit.enabled', true)
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

    $this->get(route('election.counting'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('randomManualAudit.proposed_ballots', 0)
            ->where('randomManualAudit.approved_ballots', 1)
            ->where('randomManualAudit.tally.president.pres-ada', 1)
        );
});

test('a random manual audit requires two distinct valid officer approvals', function (): void {
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
