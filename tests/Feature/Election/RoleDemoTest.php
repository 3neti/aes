<?php

use App\Election\Core\ActivityJournal;
use App\Election\PublicSimulation\PublicSimulationAdmissionCapacity;
use App\Election\PublicSimulation\PublicSimulationScope;
use App\Election\Support\ElectionStorage;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('election.review.access.enabled', false);
    config()->set('election.public_simulation.enabled', true);
    config()->set('election.public_simulation.participation_required', false);
    config()->set('election.public_simulation.demo_control_number_share.enabled', true);
    config()->set('election.voter.demo_random_fill_enabled', true);
    config()->set('election.voter.ballot_ui_profile', 'comelec_2022_facsimile');
    config()->set('election.voter.paper_facsimile_max_columns', 4);
    config()->set('election.devices.printer.driver', 'file');
    app(ElectionStorage::class)->reset();
    $this->withoutVite();
});

test('role demo runs officer voter print and watcher points of view without closing the precinct', function (): void {
    $this->get(route('election.role-demo.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/RoleDemoLobby')
            ->where('precinct.status', 'open')
            ->where('actions.officer', route('election.role-demo.officer'))
            ->where('actions.voter', route('election.role-demo.voter'))
            ->where('actions.watcher', route('election.role-demo.watcher'))
        );

    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class)
        ->and($precinct->fresh()->status)->toBe('open');

    $this->get(route('election.role-demo.officer'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/RoleDemoOfficer')
            ->where('currentTally.accepted_ballots', 0)
            ->where('actions.acceptPrint', route('election.role-demo.print.accept'))
        );

    $this->post(route('election.role-demo.admit'))
        ->assertRedirectToRoute('election.role-demo.officer');

    $authorization = session('role_demo.control_number');
    expect($authorization)->toBeArray()
        ->and($authorization['code'])->toMatch('/^[0-9]{4}$/');

    $this->get(route('election.role-demo.voter', ['code' => $authorization['code']]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/VoterWelcome')
            ->where('initialControlNumber', $authorization['code'])
            ->where('claimAction', route('election.role-demo.voter.claim'))
            ->where('demoControlNumberAction', route('election.role-demo.voter.control-number'))
        );

    $this->post(route('election.role-demo.voter.claim'), [
        'code' => $authorization['code'],
    ])->assertRedirectToRoute('election.role-demo.voter.ballot');

    $this->get(route('election.role-demo.voter.ballot'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/VoterBallot')
            ->where('finalizeAction', route('election.role-demo.voter.finalize'))
            ->where('ballotUiProfile', 'comelec_2022_facsimile')
            ->where('ballotMaxColumns', 4)
            ->where('demoRandomFillEnabled', true)
            ->has('ballot.contests', 8)
            ->where('ballot.contests.0.office', 'PRESIDENT')
            ->has('ballot.contests.0.candidates', 10)
        );

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $configuration = app(ElectionStorage::class)->readJson('runtime/active-precinct.json');
    $selections = collect($configuration['contests'])
        ->mapWithKeys(fn (array $contest): array => [
            $contest['id'] => collect($contest['candidates'])
                ->take(min(1, (int) $contest['max_selections']))
                ->pluck('id')
                ->all(),
        ])
        ->all();

    $this->post(route('election.role-demo.voter.finalize'), [
        'selections' => $selections,
    ])->assertRedirectToRoute('election.role-demo.voter.complete');

    $release = session('role_demo.release');
    expect($release)->toBeArray()
        ->and($release['release_code'])->toMatch('/^[0-9]{4,6}$/');

    $this->get(route('election.role-demo.voter.complete'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/VoterComplete')
            ->where('release.release_code', $release['release_code'])
            ->where('resetAction', route('election.role-demo.voter.reset'))
            ->where('demoBallotPreviewEnabled', true)
            ->where('ballotPreviewAction', route('election.role-demo.voter.complete.ballot-preview'))
        );

    $this->get(route('election.role-demo.voter.complete.ballot-preview'))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('content-disposition', 'inline; filename="role-demo-voter-ballot-preview.pdf"');

    expect(app(ElectionStorage::class)->readJson("print-releases/{$release['release_id']}.json")['status'])->toBe('pending')
        ->and(app(ElectionStorage::class)->files('counting/sealed'))->toBeEmpty()
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type'))
        ->toContain('role_demo.voter_ballot_preview_generated');

    $this->post(route('election.role-demo.print.accept'), [
        'code' => $release['release_code'],
    ])->assertRedirectToRoute('election.role-demo.officer');

    $this->get(route('election.role-demo.officer'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentTally.accepted_ballots', 1)
            ->where('printFeedback.status', 'accepted')
        );

    $this->get(route('election.role-demo.watcher'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/RoleDemoWatcher')
            ->where('precinct.accepted_ballots', 1)
            ->where('downloads.tally', route('election.role-demo.tally-sheet'))
            ->where('downloads.return', route('election.role-demo.election-return'))
        );

    $this->get(route('election.role-demo.print.last-ballot'))
        ->assertSuccessful()
        ->assertHeader('content-disposition', 'inline; filename="role-demo-last-printed-ballot.pdf"');

    $this->get(route('election.role-demo.tally-sheet'))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->get(route('election.role-demo.election-return', ['profile' => 'thermal-80']))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($precinct->fresh()->status)->toBe('open')
        ->and(app(ElectionStorage::class)->path('runtime/tally-sheet.pdf'))->toBeReadableFile()
        ->and(app(ElectionStorage::class)->path("returns/{$configuration['precinct_id']}-return.pdf"))->toBeReadableFile();
});

test('role demo voter can generate a self service control number before claiming the ballot', function (): void {
    $this->get(route('election.role-demo.voter'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/VoterWelcome')
            ->where('claimAction', route('election.role-demo.voter.claim'))
            ->where('demoControlNumberAction', route('election.role-demo.voter.control-number'))
            ->where('publicSimulation', true)
        );

    $response = $this->postJson(route('election.role-demo.voter.control-number'));

    $response
        ->assertSuccessful()
        ->assertJsonStructure(['code', 'expires_at']);

    $code = $response->json('code');
    expect($code)->toMatch('/^[0-9]{4}$/')
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type'))
        ->toContain('role_demo.self_service_control_number_issued');

    $this->post(route('election.role-demo.voter.claim'), [
        'code' => $code,
    ])->assertRedirectToRoute('election.role-demo.voter.ballot');

    $this->get(route('election.role-demo.voter.ballot'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/VoterBallot')
            ->where('finalizeAction', route('election.role-demo.voter.finalize'))
            ->where('demoRandomFillEnabled', true)
            ->where('ballotUiProfile', 'comelec_2022_facsimile')
            ->has('ballot.contests', 8)
            ->where('ballot.contests.0.office', 'PRESIDENT')
            ->has('ballot.contests.0.candidates', 10)
        );
});

test('role demo heals an open precinct with a missing ballot package before rendering voter ballot', function (): void {
    $this->get(route('election.role-demo.index'))->assertSuccessful();
    app(ElectionStorage::class)->writeJson('runtime/active-precinct.json', [
        'precinct_id' => '39010402',
        'contests' => [],
    ]);

    $authorization = $this->postJson(route('election.role-demo.voter.control-number'))
        ->assertSuccessful()
        ->json('code');

    $this->post(route('election.role-demo.voter.claim'), [
        'code' => $authorization,
    ])->assertRedirectToRoute('election.role-demo.voter.ballot');

    $this->get(route('election.role-demo.voter.ballot'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/VoterBallot')
            ->has('ballot.contests', 8)
            ->where('ballot.contests.0.office', 'PRESIDENT')
            ->has('ballot.contests.0.candidates', 10)
        );
});

test('role demo replaces a stale open round after configured precincts change', function (): void {
    $staleRound = SimulationRound::query()->create([
        'code' => 'ROUND-STALE1',
        'name' => 'Stale Public Election Simulation',
        'status' => 'open',
        'opened_at' => now()->subHour(),
    ]);
    $staleRound->precincts()->create([
        'code' => 'TONDO-01',
        'clustered_precinct' => '39010001',
        'district' => 'FIRST DIST',
        'label' => 'Tondo Precinct 01',
        'city_municipality' => 'CITY OF MANILA',
        'province' => 'NATIONAL CAPITAL REGION',
        'status' => 'open',
        'officer_name' => 'Volunteer Election Officer 1',
        'officer_code' => 'SIM-1-OLD',
        'officer_pin_hash' => hash('sha256', '123456'),
        'opened_at' => now()->subHour(),
    ]);

    $this->get(route('election.role-demo.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/RoleDemoLobby')
            ->where('precinct.clustered_precinct', '39010402')
            ->where('precinct.status', 'open')
        );

    $freshRound = SimulationRound::query()
        ->where('status', 'open')
        ->with('precincts')
        ->sole();

    expect($staleRound->fresh()->status)->toBe('archived')
        ->and($freshRound->code)->not->toBe($staleRound->code)
        ->and($freshRound->precincts->pluck('clustered_precinct')->unique()->values()->all())->toBe(['39010402']);
});

test('role demo self service control number recycles the oldest unused issued number when capacity is full', function (): void {
    config()->set('election.public_simulation.maximum_active_admissions', 2);

    $first = $this->postJson(route('election.role-demo.voter.control-number'))
        ->assertSuccessful()
        ->json('code');
    $second = $this->postJson(route('election.role-demo.voter.control-number'))
        ->assertSuccessful()
        ->json('code');
    $replacement = $this->postJson(route('election.role-demo.voter.control-number'))
        ->assertSuccessful()
        ->json('code');

    expect($first)->toMatch('/^[0-9]{4}$/')
        ->and($second)->toMatch('/^[0-9]{4}$/')
        ->and($replacement)->toMatch('/^[0-9]{4}$/');

    $this->post(route('election.role-demo.voter.claim'), [
        'code' => $replacement,
    ])->assertRedirectToRoute('election.role-demo.voter.ballot');

    $authorizationStatuses = collect(app(ElectionStorage::class)->files('voter-authorizations'))
        ->map(fn (string $path): array => app(ElectionStorage::class)->readJson('voter-authorizations/'.basename($path)))
        ->countBy('status');

    expect($first)->not->toBe($second)
        ->and($replacement)->not->toBe($first)
        ->and($replacement)->not->toBe($second)
        ->and($authorizationStatuses['expired'] ?? 0)->toBe(1)
        ->and(app(PublicSimulationAdmissionCapacity::class)->summary()['active_admissions'])->toBe(2)
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type'))
        ->toContain('role_demo.self_service_control_number_recycled');
});

test('role demo reset replaces the live precinct with a freshly opened one', function (): void {
    $this->get(route('election.role-demo.index'))->assertSuccessful();
    $firstRound = SimulationRound::query()->sole();

    $this->post(route('election.role-demo.reset'))
        ->assertRedirectToRoute('election.role-demo.index');

    $freshRound = SimulationRound::query()
        ->where('status', 'open')
        ->with('precincts')
        ->sole();

    expect($firstRound->fresh()->status)->toBe('archived')
        ->and(SimulationRound::query()->where('status', 'open')->count())->toBe(1)
        ->and($freshRound->precincts->where('status', 'open'))->toHaveCount(1);
});
