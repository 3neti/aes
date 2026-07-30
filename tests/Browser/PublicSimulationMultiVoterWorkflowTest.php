<?php

use App\Election\PublicSimulation\PublicSimulationAdmissionQueue;
use App\Election\PublicSimulation\PublicSimulationCloseout;
use App\Election\PublicSimulation\PublicSimulationScope;
use App\Election\PublicSimulation\PublicSimulationService;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\AnonymousVoterAuthorization;
use App\Models\SimulationPrecinct;

beforeEach(function (): void {
    config()->set('election.review.access.enabled', false);
    config()->set('election.public_simulation.enabled', true);
    config()->set('election.public_simulation.participation_required', false);
});

test('two isolated browser voters finalize private releases in the same public precinct', function (): void {
    $simulations = app(PublicSimulationService::class);
    $round = $simulations->currentRound();
    $precinct = $round->precincts()->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $simulations->open($precinct, $precinct->officer_code, '123456');
    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $configuration = app(ElectionStorage::class)->readJson('runtime/active-precinct.json');
    $contest = $configuration['contests'][0];
    $candidate = $contest['candidates'][0];
    $candidateSelector = "@candidate-{$contest['id']}-{$candidate['id']}";
    $firstAuthorization = app(AnonymousVoterAuthorization::class)->issue();
    $secondAuthorization = app(AnonymousVoterAuthorization::class)->issue();
    $voterUrl = route('election.public-simulation.voter.show', [$round, $precinct]);

    $voters = visit([$voterUrl, $voterUrl]);
    $firstVoter = $voters[0];
    $secondVoter = $voters[1];

    $firstVoter
        ->assertSee('Enter your Voter Control Number')
        ->fill('code', $firstAuthorization['code'])
        ->click('Begin voting')
        ->assertSee('Select your candidates');
    $secondVoter
        ->assertSee('Enter your Voter Control Number')
        ->fill('code', $secondAuthorization['code'])
        ->click('Begin voting')
        ->assertSee('Select your candidates');

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    expect(fn (): array => app(PublicSimulationCloseout::class)->close($precinct, $precinct->officer_code, '123456'))
        ->toThrow(RuntimeException::class, '2 voter session(s)');

    $firstVoter
        ->click($candidateSelector)
        ->click('Review 1 selection')
        ->click('Finalize and get print code')
        ->assertSee('Print and verify your paper ballot')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $secondVoter
        ->click($candidateSelector)
        ->click('Review 1 selection')
        ->click('Finalize and get print code')
        ->assertSee('Print and verify your paper ballot')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $releases = app(ElectionStorage::class)->files('print-releases');

    expect($releases)->toHaveCount(2)
        ->and(app(AnonymousVoterAuthorization::class)->inspect($firstAuthorization['authorization_id'])['status'])->toBe('completed')
        ->and(app(AnonymousVoterAuthorization::class)->inspect($secondAuthorization['authorization_id'])['status'])->toBe('completed');
});

test('a browser voter sees an anonymous waiting ticket but never its released control number', function (): void {
    $simulations = app(PublicSimulationService::class);
    $round = $simulations->currentRound();
    $precinct = $round->precincts()->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $simulations->open($precinct, $precinct->officer_code, '123456');
    $voter = visit(route('election.public-simulation.voter.show', [$round, $precinct]))
        ->assertSee('Take waiting ticket')
        ->click('Take waiting ticket')
        ->assertSee('Waiting ticket 001')
        ->assertDontSee('0000')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    app(PublicSimulationAdmissionQueue::class)->releaseNext(app(AnonymousVoterAuthorization::class));

    $voter->refresh()
        ->assertSee('Waiting ticket 001 has been released.')
        ->assertSee('This page never displays the control number.')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('an officer can record a redacted contention report from the public precinct screen', function (): void {
    $simulations = app(PublicSimulationService::class);
    $round = $simulations->currentRound();
    $precinct = $round->precincts()->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $simulations->open($precinct, $precinct->officer_code, '123456');

    visit(route('election.public-simulation.show', [$round, $precinct]))
        ->assertSee('Contention monitor')
        ->assertSee('The saved report contains aggregate counts only')
        ->fill('@contention-officer-code', $precinct->officer_code)
        ->fill('@contention-officer-pin', '123456')
        ->click('@generate-contention-report')
        ->assertSee('Redacted contention report 1 has been recorded')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    expect(app(ElectionStorage::class)->files('contention-reports'))->toHaveCount(1);
});

test('an officer pause makes the public waiting line unavailable without affecting the voter code screen', function (): void {
    $simulations = app(PublicSimulationService::class);
    $round = $simulations->currentRound();
    $precinct = $round->precincts()->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $simulations->open($precinct, $precinct->officer_code, '123456');
    visit(route('election.public-simulation.show', [$round, $precinct]))
        ->assertSee('Anonymous entry control')
        ->fill('@intake-officer-code', $precinct->officer_code)
        ->fill('@intake-officer-pin', '123456')
        ->click('@toggle-admission-intake')
        ->assertSee('New anonymous waiting tickets are paused')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    visit(route('election.public-simulation.voter.show', [$round, $precinct]))
        ->assertSee('Admission line temporarily paused')
        ->assertDontSee('Take waiting ticket')
        ->assertSee('Enter your Voter Control Number')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('a browser voter reviews the public simulation retention notice before reaching the voter code screen', function (): void {
    config()->set('election.public_simulation.participation_required', true);
    $simulations = app(PublicSimulationService::class);
    $round = $simulations->currentRound();
    $precinct = $round->precincts()->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $simulations->open($precinct, $precinct->officer_code, '123456');
    visit(route('election.public-simulation.voter.show', [$round, $precinct]))
        ->assertSee('Public election simulation')
        ->assertSee('Simulation evidence is scheduled for review for 30 days.')
        ->click('Continue to voting station')
        ->assertSee('Enter your Voter Control Number')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('an officer records a facilitated debrief observation after watcher publication', function (): void {
    $simulations = app(PublicSimulationService::class);
    $round = $simulations->currentRound();
    $precinct = $round->precincts()->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);
    $precinct->forceFill(['status' => 'published'])->save();

    visit(route('election.public-simulation.show', [$round, $precinct]))
        ->assertSee('Facilitated debrief')
        ->assertSee('Record operational observation')
        ->fill('note', 'The voter tablet flow was clear in the facilitated browser rehearsal.')
        ->fill('@observation-officer-code', $precinct->officer_code)
        ->fill('@observation-officer-pin', '123456')
        ->click('@record-operational-observation')
        ->assertSee('Operational observation 1 has been recorded')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    expect(app(ElectionStorage::class)->files('operational-observations'))->toHaveCount(1);
});
