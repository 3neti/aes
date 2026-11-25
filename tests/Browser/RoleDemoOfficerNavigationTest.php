<?php

use App\Election\Support\ElectionStorage;
use Pest\Browser\Playwright\Playwright;

beforeEach(function (): void {
    config()->set('election.review.access.enabled', false);
    config()->set('election.public_simulation.enabled', true);
    config()->set('election.public_simulation.participation_required', false);
    app(ElectionStorage::class)->reset();
});

test('officer switches between one visible workstation step at a time', function (): void {
    Playwright::setTimeout(20000);

    visit(route('election.role-demo.officer'))
        ->assertSee('Admit next voter')
        ->assertDontSee('Generate a four-digit control number after physical identity checking.')
        ->assertAttribute('Tally Votes', 'href', route('election.role-demo.precinct-tally'))
        ->assertDontSee('Check VVPAT readiness')
        ->assertDontSee('Current / interim election return')
        ->click('Step 2 Print VVPAT')
        ->assertSee('Check VVPAT readiness')
        ->assertDontSee('Enter the voter’s control number to check whether their submitted ballot is ready.')
        ->assertDontSee('Admit next voter')
        ->click('Step 3 Election return')
        ->assertSee('Current / interim election return')
        ->assertDontSee('Check VVPAT readiness')
        ->click('Step 1 Admit voter')
        ->assertSee('Admit next voter')
        ->assertNoJavaScriptErrors();
});
