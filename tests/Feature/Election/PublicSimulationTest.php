<?php

use App\Election\PublicSimulation\PublicSimulationScope;
use App\Election\Support\ElectionStorage;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('election.review.access.enabled', false);
    config()->set('election.public_simulation.enabled', true);
    $this->withoutVite();
});

test('a public precinct keeps its voting, VVDAT, tally, and return evidence isolated', function (): void {
    $this->get(route('election.public-simulation.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/PublicSimulationLobby')
            ->has('round.precincts', 3)
        );

    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $credentials = [
        'officer_code' => $precinct->officer_code,
        'officer_pin' => '123456',
    ];

    $this->post(route('election.public-simulation.open', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.public-simulation.show', [$round, $precinct]));

    $precinct->refresh();
    expect($precinct->status)->toBe('open');

    $this->post(route('election.public-simulation.admit', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.public-simulation.show', [$round, $precinct]));

    $authorization = session('public_simulation.control_number');
    expect($authorization)->toBeArray()->and($authorization['code'])->toMatch('/^[0-9]{4}$/');

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
    expect($release)->toBeArray()->and($release['release_code'])->toBeString();

    $this->post(route('election.public-simulation.print.redeem', [$round, $precinct]), [
        'code' => $release['release_code'],
    ])->assertRedirect(route('election.public-simulation.print.station', [$round, $precinct]));
    $this->post(route('election.public-simulation.print.print', [$round, $precinct]))
        ->assertRedirect(route('election.public-simulation.print.station', [$round, $precinct]));
    $this->post(route('election.public-simulation.print.deposit', [$round, $precinct]))
        ->assertRedirect(route('election.public-simulation.print.station', [$round, $precinct]));

    $this->post(route('election.public-simulation.close', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.public-simulation.show', [$round, $precinct]));

    $precinct->refresh();
    expect($precinct->status)->toBe('results_ready');

    $this->get(route('election.public-simulation.watcher.show', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/PublicSimulationWatcher')
            ->where('published', false)
        );

    $this->post(route('election.public-simulation.publish', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.public-simulation.show', [$round, $precinct]));

    $this->get(route('election.public-simulation.watcher.show', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/PublicSimulationWatcher')
            ->where('published', true)
            ->where('ballot.contests.0.title', 'SENATOR - PHILIPPINES')
        );

    $precinct->refresh();
    expect($precinct->status)->toBe('published');

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $storage = app(ElectionStorage::class);
    $configuration = $storage->readJson('runtime/active-precinct.json');
    $return = $storage->readJson("returns/{$configuration['precinct_id']}-return.json");

    expect($storage->files('device-tabulation-ledger'))->toHaveCount(1)
        ->and($storage->readJson('counting/vvdat-ledger-freeze.json')['record_count'])->toBe(1)
        ->and($storage->readJson('returns/publication-manifest.json')['precinct_code'])->toBe($precinct->code)
        ->and($storage->path('runtime/tally-sheet.pdf'))->toBeReadableFile()
        ->and($return['accepted_ballots'])->toBe(1)
        ->and($return['tally_source'])->toContain('VVDAT');

    $this->get(route('election.public-simulation.watcher.vvdat-audit-export', [$round, $precinct]))
        ->assertSuccessful();

    $export = $storage->readJson('returns/vvdat-audit-export.json');
    expect($export['record_count'])->toBe(1)
        ->and($export['records'][0])->toHaveKeys(['record_hash', 'selections'])
        ->and($export['records'][0])->not->toHaveKeys(['ballot_id', 'paper_ballot_serial', 'recorded_at']);
});
