<?php

use App\Election\PublicSimulation\PublicSimulationAdmissionCapacity;
use App\Election\PublicSimulation\PublicSimulationScope;
use App\Election\PublicSimulation\PublicVvdatAuditExportVerifier;
use App\Election\Support\ElectionStorage;
use App\Election\Tabulation\DeviceTabulationLedger;
use App\Election\Voting\AnonymousVoterAuthorization;
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

    expect(fn () => app(DeviceTabulationLedger::class)->recordDepositedBallot([]))
        ->toThrow(RuntimeException::class, 'VVDAT ledger is frozen');

    config()->set('election.public_simulation.vvdat_audit_export.minimum_records', 2);
    $this->get(route('election.public-simulation.watcher.show', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auditExportAvailable', false)
        );
    $this->get(route('election.public-simulation.watcher.vvdat-audit-export', [$round, $precinct]))
        ->assertNotFound();

    config()->set('election.public_simulation.vvdat_audit_export.minimum_records', 1);

    $this->get(route('election.public-simulation.watcher.vvdat-audit-export', [$round, $precinct]))
        ->assertSuccessful();

    $export = $storage->readJson('returns/vvdat-audit-export.json');
    expect($export['record_count'])->toBe(1)
        ->and($export['records'][0])->toHaveKeys(['record_hash', 'selections'])
        ->and($export['records'][0])->not->toHaveKeys(['ballot_id', 'paper_ballot_serial', 'recorded_at']);

    expect(app(PublicVvdatAuditExportVerifier::class)->verify(json_encode($export, JSON_THROW_ON_ERROR))['passed'])->toBeTrue();

    $this->artisan('election:public-simulation:verify-vvdat-export', ['path' => $export['artifact_path']])
        ->expectsOutput('VVDAT audit export verified for 1 records.')
        ->assertSuccessful();
});

test('a precinct admission capacity is reserved atomically inside the scoped election lock', function (): void {
    config()->set('election.public_simulation.maximum_active_admissions', 1);
    $round = SimulationRound::factory()->create();
    $precinct = SimulationPrecinct::factory()->for($round, 'round')->create();

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $storage = app(ElectionStorage::class);
    $storage->reset();
    $first = app(PublicSimulationAdmissionCapacity::class)->issue(app(AnonymousVoterAuthorization::class));

    expect($first['code'])->toMatch('/^[0-9]{4}$/')
        ->and(fn () => app(PublicSimulationAdmissionCapacity::class)->issue(app(AnonymousVoterAuthorization::class)))
        ->toThrow(RuntimeException::class, 'active voter-admission limit');
});

test('a public simulation round archives only after every precinct is published', function (): void {
    $round = SimulationRound::factory()->create();
    $unfinished = SimulationPrecinct::factory()->for($round, 'round')->create(['status' => 'open']);

    $this->artisan('election:public-simulation:archive', ['round' => $round->code])
        ->expectsOutputToContain('Every precinct must publish')
        ->assertFailed();

    $unfinished->forceFill(['status' => 'published'])->save();
    SimulationPrecinct::factory()->for($round, 'round')->create(['status' => 'published']);

    $this->artisan('election:public-simulation:archive', ['round' => $round->code])
        ->expectsOutputToContain("Public simulation round {$round->code} archived")
        ->assertSuccessful();

    expect($round->fresh()->status)->toBe('archived')
        ->and($round->fresh()->archived_at)->not->toBeNull();
});

test('a controlled reset archives a published public round before creating a fresh lobby', function (): void {
    $round = SimulationRound::factory()->create();
    SimulationPrecinct::factory()->count(3)->for($round, 'round')->create(['status' => 'published']);

    $this->artisan('election:public-simulation:reset', ['round' => $round->code])
        ->expectsOutputToContain("Archived {$round->code}; created fresh public simulation round")
        ->assertSuccessful();

    $fresh = SimulationRound::query()->where('status', 'open')->sole();

    expect($round->fresh()->status)->toBe('archived')
        ->and($round->fresh()->archived_at)->not->toBeNull()
        ->and($fresh->code)->not->toBe($round->code)
        ->and($fresh->precincts()->count())->toBe(3);
});

test('the facilitator god mode remains disabled until explicitly enabled', function (): void {
    $round = SimulationRound::factory()->create();
    SimulationPrecinct::factory()->for($round, 'round')->create();

    $this->get(route('election.public-simulation.god-mode', $round))->assertNotFound();

    config()->set('election.public_simulation.god_mode.enabled', true);

    $this->get(route('election.public-simulation.god-mode', $round))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/PublicSimulationGodMode')
            ->where('privacyNotice', 'This facilitator screen intentionally excludes voter selections, control numbers, print releases, paper serials, QR payloads, and participant identity.')
        );
});
