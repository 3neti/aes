<?php

use App\Election\Core\ActivityJournal;
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
        ->assertSee('Admit the next voter')
        ->assertSee('Issue Voter Control Number')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('voter tablet presents four-digit control number entry', function (): void {
    app(LifecycleState::class)->set(Lifecycle::Voting);

    visit('/election/voter')
        ->assertSee('Enter your Voter Control Number')
        ->assertSee('The Election Board gives one four-digit control number')
        ->assertSee('Voter Control Number')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('expired voter code can be replaced without re-pairing', function (): void {
    config()->set('election.voter.authorization_ttl_seconds', 1);
    app(LifecycleState::class)->set(Lifecycle::Voting);

    visit('/election/voting')
        ->fill('officer_code', 'SIM-OFFICER-001')
        ->fill('officer_pin', '123456')
        ->click('Issue Voter Control Number')
        ->assertSee('Expires in')
        ->wait(2)
        ->assertSee('Control number expired')
        ->fill('officer_code', 'SIM-OFFICER-001')
        ->fill('officer_pin', '123456')
        ->click('Generate replacement control number')
        ->assertSee('Control number ready')
        ->assertSee('Expires in')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(collect(app(ActivityJournal::class)->entries())->pluck('event_type'))
        ->toContain(
            'voter.authorization_expired',
            'voter.authorization_replaced',
        );
});

test('review mode loads and clears temporary operator defaults', function (): void {
    config()->set('election.review.enabled', true);

    visit('/election/provision')
        ->assertSee('Temporary presentation values are already loaded')
        ->assertSee('Click this next')
        ->assertSee('Chairperson ID')
        ->assertSee('SIM-OFFICER-002')
        ->assertSee('Review PIN')
        ->assertSee('123456')
        ->assertValue('chairperson_code', 'SIM-OFFICER-001')
        ->assertValue('chairperson_pin', '123456')
        ->assertValue('device_serial', 'AES-PI-39010001-001')
        ->click('Clear review defaults')
        ->assertSee('Temporary defaults are cleared')
        ->assertValue('chairperson_code', '')
        ->assertValue('chairperson_pin', '')
        ->click('Reload review defaults')
        ->assertValue('chairperson_code', 'SIM-OFFICER-001')
        ->assertValue('chairperson_pin', '123456')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('certification advances immediately after manual verification', function (): void {
    config()->set('election.review.enabled', true);

    visit('/election/certification')
        ->click('Run Certification')
        ->assertSee('3/3 checks passed')
        ->click('Run Manual Verification')
        ->assertSee('Matched')
        ->assertSee('Manual Verification')
        ->assertSee('PASS')
        ->assertSee('Run Discrepancy Analysis')
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
