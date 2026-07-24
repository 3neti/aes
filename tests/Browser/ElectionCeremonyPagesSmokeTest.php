<?php

use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Support\ElectionStorage;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
    app(ActivateSamplePackage::class)->handle();
});

test('ceremony pages have no browser smoke failures', function (): void {
    $ceremonies = [
        ['/', 'Alternative Election System'],
        ['/election/provision', 'Precinct Setup'],
        ['/election/certification', 'Certification'],
        ['/election/voting', 'Voting'],
        ['/election/printing', 'Official Ballot Artifact'],
        ['/election/counting', 'Counting'],
        ['/election/returns', 'Election Return'],
        ['/election/diagnostics', 'Diagnostics'],
    ];

    foreach ($ceremonies as [$path, $label]) {
        visit($path)
            ->assertSee($label)
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    }

    visit('/election/voting')
        ->on()->mobile()
        ->assertSee('Precinct Run')
        ->assertSee('Next required action')
        ->assertSee('Evidence at a glance')
        ->assertSee('Voting')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('opening ceremony exposes the final authorization that begins voting', function (): void {
    app(LifecycleState::class)->set(Lifecycle::OpenPolls);

    visit('/election/voting')
        ->assertSee('Begin active voting')
        ->assertDontSee('Voting actions are unavailable')
        ->fill('officer_code', 'SIM-OFFICER-001')
        ->fill('officer_pin', '123456')
        ->click('Begin voting')
        ->assertSee('Prepare the voter ballot')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('official handoff unlocks the delivery receipt after both parties verify custody', function (): void {
    $this->artisan('election:scenario election-return-copy-distribution')
        ->assertSuccessful();

    $this->post(route('election.returns.close'))
        ->assertRedirect(route('election.transmission'));

    $this->post(route('election.transmission.send'))
        ->assertRedirect(route('election.transmission'));

    $this->post(route('election.transmission.officer-verification'), [
        'officer_code' => 'SIM-OFFICER-001',
        'officer_pin' => '123456',
        'verification_note' => 'Browser handoff verification.',
        'stage' => app(LifecycleState::class)->current(),
    ])->assertRedirect(route('election.transmission'));

    $this->post(route('election.transmission.recipient-verification'), [
        'recipient' => 'City Board of Canvassers Receiving Officer',
        'recipient_role' => 'Receiving Officer',
        'handoff_date' => '2026-05-08',
        'handoff_time' => '14:30',
        'delivery_method' => 'manual',
        'acknowledged' => true,
        'acknowledgement_note' => 'Recipient accepted custody.',
        'stage' => app(LifecycleState::class)->current(),
    ])->assertRedirect(route('election.transmission'));

    $this->post(route('election.transmission.prepare'))
        ->assertRedirect(route('election.transmission'));

    visit('/election/transmission')
        ->assertButtonEnabled('Generate Delivery Receipt')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
