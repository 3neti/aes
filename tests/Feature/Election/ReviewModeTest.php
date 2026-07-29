<?php

use App\Election\Core\ActivityJournal;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Scenarios\ScenarioRunner;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
    app(ElectionClock::class)->unfreeze();
    $this->withoutVite();
});

test('review mode supplies temporary defaults to operator pages', function (): void {
    config()->set('election.review.enabled', true);

    $this->get(route('election.provision'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('electionReview.enabled', true)
            ->where('electionReview.label', 'COMELEC Review Environment')
            ->where('electionReview.defaults.primary_officer.code', 'SIM-OFFICER-001')
            ->where('electionReview.defaults.primary_officer.pin', '123456')
            ->where('electionReview.defaults.setup.device_serial', 'AES-PI-39010001-001')
            ->where('electionReview.defaults.setup.ballot_box_id', 'AES-BOX-39010001-001')
            ->where(
                'electionReview.defaults.handoff.recipient',
                'City Board of Canvassers Receiving Officer',
            )
            ->where('electionReview.defaults.handoff.recipient_role', 'Receiving Officer')
        );
});

test('review mode prepares certification prerequisites during precinct activation', function (): void {
    config()->set('election.review.enabled', true);

    $this->post(route('election.provision.activate'))
        ->assertRedirect(route('election.certification'));

    $storage = app(ElectionStorage::class);
    $setup = $storage->readJson('runtime/precinct-setup.json');
    $devices = $storage->readJson('certification/device-certification-report.json');
    $initialization = $storage->readJson('diagnostics/initialization-report.json');

    expect($setup['passed'])->toBeTrue()
        ->and($devices['passed'])->toBeTrue()
        ->and($initialization['passed'])->toBeTrue()
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type'))
        ->toContain(
            'precinct.setup_recorded',
            'devices.certification_passed',
            'initialization_report.generated',
        );
});

test('review mode repairs older runs before sealing and advances the lifecycle', function (): void {
    config()->set('election.review.enabled', true);

    $this->post(route('election.provision.activate'));

    $storage = app(ElectionStorage::class);
    unlink($storage->path('runtime/precinct-setup.json'));
    unlink($storage->path('certification/device-certification-report.json'));
    unlink($storage->path('diagnostics/initialization-report.json'));

    $this->post(route('election.certification.run'));
    $certification = $storage->readJson('certification/friday-certification-report.json');
    $manualReturn = [
        'schema_version' => 'manual-return-1',
        'precinct_id' => $certification['precinct_id'],
        'accepted_ballots' => $certification['accepted_ballots'],
        'rejected_ballots' => $certification['rejected_ballots'],
        'tally' => $certification['actual_tally'],
    ];

    $this->post(route('election.certification.manual-verification'), [
        'manual_return' => json_encode($manualReturn),
    ]);
    $this->post(route('election.certification.discrepancy'));
    $this->post(route('election.certification.zero-out'));
    $this->post(route('election.certification.seal'))
        ->assertRedirect(route('election.certification'))
        ->assertSessionDoesntHaveErrors();

    expect($storage->readJson('runtime/precinct-setup.json')['passed'])->toBeTrue()
        ->and($storage->readJson('diagnostics/initialization-report.json')['passed'])->toBeTrue()
        ->and($storage->readJson('certification/sealing-report.json')['passed'])->toBeTrue()
        ->and(app(LifecycleState::class)->current())->toBe(Lifecycle::OpenPrecinct);
});

test('election day mode exposes no temporary defaults', function (): void {
    config()->set('election.review.enabled', false);

    $this->get(route('election.provision'))
        ->assertSuccessful()
        ->assertDontSee('SIM-OFFICER-001')
        ->assertDontSee('123456')
        ->assertInertia(fn (Assert $page) => $page
            ->where('electionReview.enabled', false)
            ->where('electionReview.label', null)
            ->where('electionReview.defaults', [])
        );
});

test('review credentials are withheld from isolated voter and watcher pages', function (string $route): void {
    config()->set('election.review.enabled', true);
    app(LifecycleState::class)->set(Lifecycle::Voting);

    $this->get(route($route))
        ->assertSuccessful()
        ->assertDontSee('SIM-OFFICER-001')
        ->assertDontSee('123456')
        ->assertInertia(fn (Assert $page) => $page
            ->where('electionReview.enabled', false)
            ->where('electionReview.defaults', [])
        );
})->with([
    'voter tablet' => 'election.voter',
    'poll watcher' => 'election.watchers',
    'print station' => 'election.print-station',
]);

test('scenario reports identify review mode without recording credentials', function (): void {
    config()->set('election.review.enabled', true);

    $report = app(ScenarioRunner::class)->run('voting-legal-edge-cases');

    expect($report['review_mode'])->toBe([
        'enabled' => true,
        'temporary_defaults_enabled' => true,
        'label' => 'COMELEC Review Environment',
    ])->and($report['review_mode'])->not->toHaveKeys(['defaults', 'pin']);
});
