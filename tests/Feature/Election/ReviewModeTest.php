<?php

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
        );
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
