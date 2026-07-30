<?php

use App\Election\Core\ActivityJournal;
use App\Election\Counting\CountingService;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Preparation\PrecinctSetupService;
use App\Election\Printing\BallotPrinter;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Tabulation\DeviceTabulationLedger;
use App\Election\Tabulation\TabulationProfile;
use App\Election\Tabulation\TabulationProfileResolver;
use App\Election\Voting\AnonymousVoterAuthorization;
use App\Election\Voting\PrivateBallotRelease;
use App\Election\Voting\SealedBallotBox;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
    app(ElectionClock::class)->unfreeze();
    app(ActivateSamplePackage::class)->handle();
    app(PrecinctSetupService::class)->record(config('election.simulation.precinct_setup'));
    app(LifecycleState::class)->set(Lifecycle::Voting);
});

test('an officer issues a four-digit single-use voter control number', function (): void {
    $response = $this->post(route('election.voting.voter-authorizations.issue'), [
        'officer_code' => 'SIM-OFFICER-001',
        'officer_pin' => '123456',
    ]);

    $response
        ->assertRedirect(route('election.voting'))
        ->assertSessionHas('voter_authorization', fn (array $authorization): bool => preg_match(
            '/^[0-9]{4}$/',
            $authorization['code'],
        ) === 1);

    $path = app(ElectionStorage::class)->files('voter-authorizations')[0];
    $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($record)
        ->not->toHaveKeys(['voter_name', 'voter_id', 'code'])
        ->and($record['status'])->toBe('issued')
        ->and($record['schema_version'])->toBe('voter-control-number-1')
        ->and($record['code_hash'])->toHaveLength(64);
});

test('a voter control number must contain exactly four digits', function (): void {
    $this->post(route('election.voter.claim'), ['code' => '12AB'])
        ->assertSessionHasErrors('code');

    $this->post(route('election.voter.claim'), ['code' => '123'])
        ->assertSessionHasErrors('code');

    $this->post(route('election.voter.claim'), ['code' => '12345'])
        ->assertSessionHasErrors('code');
});

test('a voter code can be claimed once and expires', function (): void {
    $authorizations = app(AnonymousVoterAuthorization::class);
    $authorization = $authorizations->issue();

    $this->post(route('election.voter.claim'), ['code' => $authorization['code']])
        ->assertRedirect(route('election.voter.ballot'));

    $this->post(route('election.voter.claim'), ['code' => $authorization['code']])
        ->assertSessionHasErrors('code');

    app(ElectionClock::class)->freeze('2026-05-11 08:00:00');
    $expired = $authorizations->issue();
    app(ElectionClock::class)->tick(301);

    $this->post(route('election.voter.claim'), ['code' => $expired['code']])
        ->assertSessionHasErrors('code');
});

test('an officer replaces an expired voter code with journal evidence', function (): void {
    app(ElectionClock::class)->freeze('2026-05-11 08:00:00');

    $this->post(route('election.voting.voter-authorizations.issue'), [
        'officer_code' => 'SIM-OFFICER-001',
        'officer_pin' => '123456',
    ])->assertRedirect(route('election.voting'));

    $expiredAuthorization = session('voter_authorization');
    app(ElectionClock::class)->tick(301);

    $this->get(route('election.voting'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('voterAuthorization.authorization_id', $expiredAuthorization['authorization_id'])
            ->where('voterAuthorization.status', 'expired')
            ->where('voterAuthorization.seconds_remaining', 0)
        );

    $this->post(route('election.voting.voter-authorizations.issue'), [
        'officer_code' => 'SIM-OFFICER-001',
        'officer_pin' => '123456',
    ])->assertRedirect(route('election.voting'));

    $replacement = session('voter_authorization');
    $storage = app(ElectionStorage::class);
    $expiredRecord = $storage->readJson(
        "voter-authorizations/{$expiredAuthorization['authorization_id']}.json",
    );
    $events = collect(app(ActivityJournal::class)->entries());

    expect($replacement['authorization_id'])
        ->not->toBe($expiredAuthorization['authorization_id'])
        ->and($expiredRecord['status'])->toBe('expired')
        ->and($expiredRecord['replacement_authorization_id'])->toBe($replacement['authorization_id'])
        ->and($events->where('event_type', 'voter.authorization_expired'))->toHaveCount(1)
        ->and($events->where('event_type', 'voter.authorization_replaced'))->toHaveCount(1)
        ->and($events->firstWhere('event_type', 'voter.authorization_replaced')['payload'])
        ->not->toHaveKeys(['code', 'previous_code', 'replacement_code']);

    $this->post(route('election.voter.claim'), [
        'code' => $expiredAuthorization['code'],
    ])->assertSessionHasErrors('code');

    $this->post(route('election.voter.claim'), [
        'code' => $replacement['code'],
    ])->assertRedirect(route('election.voter.ballot'));
});

test('the private voter journey seals choices until polls close', function (): void {
    $authorization = app(AnonymousVoterAuthorization::class)->issue();

    $this->get(route('election.voter'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/VoterWelcome')
            ->where('precinct.precinct_id', '0421-A')
            ->missing('snapshot')
        );

    $this->get(route('election.voter.ballot'))->assertForbidden();

    $this->post(route('election.voter.claim'), ['code' => $authorization['code']])
        ->assertRedirect(route('election.voter.ballot'));

    $this->get(route('election.voter.ballot'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/VoterBallot')
            ->has('ballot.contests', 3)
            ->missing('snapshot')
            ->missing('journal')
        );

    $finalize = $this->post(route('election.voter.finalize'), [
        'selections' => [
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ],
    ]);
    $release = $finalize->getSession()->get('election.voter_print_release');

    $finalize->assertRedirect(route('election.voter.complete'));
    expect($release['release_qr_data_uri'])->toStartWith('data:image/png;base64,');

    $releasePath = app(ElectionStorage::class)->path("print-releases/{$release['release_id']}.json");
    $releaseContents = file_get_contents($releasePath);

    expect($releaseContents)
        ->not->toContain('pres-ada')
        ->not->toContain('mayor-lina')
        ->not->toContain('council-ana')
        ->not->toContain('"selections"');

    $this->get(route('election.voter.complete'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/VoterComplete')
            ->where('release.release_id', $release['release_id'])
            ->missing('snapshot')
        );

    $this->post(route('election.print-station.redeem'), ['code' => $release['release_code']])
        ->assertRedirect(route('election.print-station'));

    $this->get(route('election.print-station'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/PrintStation')
            ->where('release.status', 'pending')
            ->missing('release.encrypted_payload')
            ->missing('release.payload_hash')
        );

    $this->post(route('election.print-station.print'))
        ->assertRedirect(route('election.print-station'));

    $this->post(route('election.print-station.deposit'))
        ->assertRedirect(route('election.print-station'))
        ->assertSessionHas('deposit_feedback.status', 'accepted');

    $storage = app(ElectionStorage::class);
    $sealedPath = $storage->files('counting/sealed')[0];
    $sealedContents = file_get_contents($sealedPath);

    expect($storage->files('counting/accepted'))->toBeEmpty()
        ->and($sealedContents)->not->toContain('pres-ada')
        ->and($sealedContents)->not->toContain('"selections"')
        ->and(collect($storage->files('voter-ballots'))->filter(
            fn (string $path): bool => str_ends_with($path, '.json'),
        ))->toBeEmpty();

    $this->get(route('election.watchers'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Watcher')
            ->where('operations.deposited_ballots', 1)
            ->where('resultsAvailable', false)
            ->where('tallyAvailable', false)
            ->where('randomManualAudit.available', false)
            ->where('tally', [])
        );
    $this->get(route('election.watchers.rma.evidence-pack.download'))->assertNotFound();

    $this->post(route('election.voting.close-polls'))
        ->assertRedirect(route('election.counting'));

    expect(app(LifecycleState::class)->current())->toBe(Lifecycle::Counting)
        ->and($storage->files('counting/accepted'))->toBeEmpty()
        ->and(app(DeviceTabulationLedger::class)->records())->toHaveCount(1)
        ->and(app(CountingService::class)->tally()['accepted_ballots'])->toBe(1)
        ->and(app(CountingService::class)->tally()['tabulation_profile'])
        ->toBe(TabulationProfile::DeviceTabulationWithPaperAudit->value);

    $this->post(route('election.counting.scan'), ['payload' => 'not-a-routine-count'])
        ->assertRedirect(route('election.counting'))
        ->assertSessionHas('scan_feedback', fn (array $feedback): bool => $feedback['adapter'] === 'routine-scan-blocked');

    expect($storage->files('counting/rejected'))->toBeEmpty()
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type'))
        ->toContain('counting.routine_scan_blocked');
});

test('a paper-first profile remains available for a newly activated run', function (): void {
    config()->set('election.tabulation.profile', TabulationProfile::PaperFirst->value);
    app(ElectionStorage::class)->reset();
    app(ActivateSamplePackage::class)->handle();
    app(PrecinctSetupService::class)->record(config('election.simulation.precinct_setup'));
    app(LifecycleState::class)->set(Lifecycle::Voting);

    expect(app(TabulationProfileResolver::class)->current())->toBe(TabulationProfile::PaperFirst);

    $release = app(PrivateBallotRelease::class)->create('test-authorization', [
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana'],
    ]);
    app(PrivateBallotRelease::class)->print($release['release_id'], app(BallotPrinter::class));
    app(SealedBallotBox::class)->deposit($release['release_id']);

    app(LifecycleState::class)->set(Lifecycle::ClosePolls);
    app(LifecycleState::class)->set(Lifecycle::Counting);
    app(SealedBallotBox::class)->openForCounting(app(CountingService::class));

    expect(app(ElectionStorage::class)->files('counting/accepted'))->toHaveCount(1)
        ->and(app(DeviceTabulationLedger::class)->records())->toBeEmpty()
        ->and(app(CountingService::class)->tally()['accepted_ballots'])->toBe(1)
        ->and(app(CountingService::class)->tally()['tabulation_profile'])
        ->toBe(TabulationProfile::PaperFirst->value);
});

test('the server rejects ballot selections above a contest maximum', function (): void {
    $authorization = app(AnonymousVoterAuthorization::class)->issue();
    $this->post(route('election.voter.claim'), ['code' => $authorization['code']]);

    $this->post(route('election.voter.finalize'), [
        'selections' => [
            'president' => ['pres-ada', 'pres-ben'],
        ],
    ])->assertSessionHasErrors('selections');

    expect(app(ElectionStorage::class)->files('print-releases'))->toBeEmpty();
});
