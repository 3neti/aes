<?php

use App\Election\PublicSimulation\PublicSimulationService;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\AnonymousVoterAuthorization;
use App\Models\SimulationPrecinct;

beforeEach(function (): void {
    config()->set('election.review.access.enabled', false);
    config()->set('election.public_simulation.enabled', true);
    config()->set('election.public_simulation.participation_required', false);
    config()->set('election.voter.demo_random_fill_enabled', true);
    app(ElectionStorage::class)->reset();
});

test('role demo voter reaches a populated ballot and can fill remaining choices', function (): void {
    $simulations = app(PublicSimulationService::class);
    $round = $simulations->currentRound();
    $precinct = $round->precincts()->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);
    $simulations->open($precinct, $precinct->officer_code, '123456');
    $authorization = app(AnonymousVoterAuthorization::class)->issue();

    visit(route('election.role-demo.voter', ['code' => $authorization['code']]))
        ->assertSee('Enter your Voter Control Number')
        ->click('Begin voting')
        ->assertSee('Select your candidates')
        ->assertSee('ABALOS')
        ->assertSee('Fill remaining choices')
        ->click('@fill-remaining-choices')
        ->assertSee('Review:')
        ->assertSee('Sen. (12)')
        ->assertSee('Party List (1)')
        ->click('@review-ballot')
        ->assertSee('Review your ballot')
        ->click('Finalize and get print PIN')
        ->assertSee('Write down your print PIN')
        ->assertSee('Demo shortcut: tap the PIN to preview the printable ballot.')
        ->click('@open-voter-ballot-preview')
        ->assertSee('Printable ballot preview')
        ->assertSee('This preview does not deposit, count, or accept the ballot.')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
