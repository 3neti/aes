<?php

use App\Election\PublicSimulation\PublicSimulationScope;
use App\Election\Support\ElectionStorage;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('election.review.access.enabled', false);
    config()->set('election.public_simulation.enabled', true);
    config()->set('election.public_simulation.participation_required', false);
    $this->withoutVite();
});

test('the demo room runs a precinct through officer, voter, print station, watcher, and handoff roles', function (): void {
    $this->get(route('election.demo-room.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/DemoRoomLobby')
            ->has('round.precincts', 3)
        );

    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $credentials = [
        'officer_code' => $precinct->officer_code,
        'officer_pin' => '123456',
    ];

    $this->get(route('election.demo-room.show', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/DemoRoomPrecinct')
            ->has('roles', 5)
            ->where('officerDefaults.officer_code', $precinct->officer_code)
            ->where('officerDefaults.officer_pin', '123456')
        );

    $this->get(route('election.demo-room.officer', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/DemoRoomOfficer')
            ->where('actions.open', route('election.demo-room.open', [$round, $precinct]))
        );

    $this->post(route('election.demo-room.open', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.demo-room.officer', [$round, $precinct]));
    expect($precinct->fresh()->status)->toBe('open');

    $this->post(route('election.demo-room.admit', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.demo-room.officer', [$round, $precinct]));
    $authorization = session('public_simulation.control_number');
    expect($authorization)->toBeArray()
        ->and($authorization['code'])->toMatch('/^[0-9]{4}$/');

    $this->post(route('election.public-simulation.voter.claim', [$round, $precinct]), [
        'code' => $authorization['code'],
    ])->assertRedirect(route('election.public-simulation.voter.ballot', [$round, $precinct]));

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

    $this->post(route('election.public-simulation.voter.finalize', [$round, $precinct]), [
        'selections' => $selections,
    ])->assertRedirect(route('election.public-simulation.voter.complete', [$round, $precinct]));
    $release = session("public_simulation.{$precinct->id}.release");
    expect($release)->toBeArray()
        ->and($release['release_code'])->toMatch('/^[0-9]{4,6}$/');

    $this->get(route('election.demo-room.print.station', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/DemoRoomPrintStation')
            ->where('enabled', false)
            ->where('officerDefaults.officer_code', $precinct->officer_code)
        );

    $this->post(route('election.demo-room.print.enable', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.demo-room.print.station', [$round, $precinct]));

    $this->post(route('election.demo-room.print.redeem', [$round, $precinct]), [
        'code' => $release['release_code'],
    ])->assertRedirect(route('election.demo-room.print.station', [$round, $precinct]));
    $this->post(route('election.demo-room.print.print', [$round, $precinct]))
        ->assertRedirect(route('election.demo-room.print.station', [$round, $precinct]));
    $this->post(route('election.demo-room.print.deposit', [$round, $precinct]))
        ->assertRedirect(route('election.demo-room.print.station', [$round, $precinct]));

    $this->post(route('election.demo-room.close', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.demo-room.officer', [$round, $precinct]));
    expect($precinct->fresh()->status)->toBe('results_ready');

    $this->post(route('election.demo-room.publish', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.demo-room.officer', [$round, $precinct]));
    expect($precinct->fresh()->status)->toBe('published');

    $this->get(route('election.demo-room.print.station', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('enabled', true)
            ->where('isVoting', false)
            ->where('isPublished', true)
            ->where('actions.tally', route('election.public-simulation.watcher.tally', [$round, $precinct]))
            ->where('actions.return', route('election.public-simulation.watcher.return', [$round, $precinct]))
        );

    $this->get(route('election.demo-room.handoff', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/DemoRoomHandoff')
            ->where('downloads.watcher', route('election.public-simulation.watcher.show', [$round, $precinct]))
        );

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $storage = app(ElectionStorage::class);
    $return = $storage->readJson("returns/{$configuration['precinct_id']}-return.json");

    expect($return['accepted_ballots'])->toBe(1)
        ->and($storage->path('runtime/tally-sheet.pdf'))->toBeReadableFile()
        ->and($storage->path("returns/{$configuration['precinct_id']}-return.pdf"))->toBeReadableFile();
});
