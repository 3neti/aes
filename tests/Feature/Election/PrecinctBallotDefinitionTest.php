<?php

use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Preparation\ActivatePrecinctBallotPackage;
use App\Election\Preparation\PrecinctBallotDefinitionBuilder;
use App\Election\Support\ElectionStorage;
use App\Http\Middleware\BindBrowserWalkthroughRun;
use App\Http\Middleware\ProtectReviewEnvironment;
use App\Http\Middleware\RequireReviewRoomRole;

beforeEach(function (): void {
    config()->set('election.review.access.enabled', false);
    $this->withoutMiddleware([
        BindBrowserWalkthroughRun::class,
        ProtectReviewEnvironment::class,
        RequireReviewRoomRole::class,
    ]);

    app(ElectionStorage::class)->reset();
    $this->artisan('election:pop-import', ['path' => resource_path('election/pop/2025NLE_POP.xlsx')])->assertSuccessful();
    $this->artisan('election:clc-import')->assertSuccessful();
});

test('tondo precinct ballot definition uses actual national and manila local candidates', function (): void {
    $definition = app(PrecinctBallotDefinitionBuilder::class)->build('39010001', 'FIRST DIST');
    $report = $definition['report'];
    $contests = collect($definition['registries']['contests'])->keyBy('office');

    expect($report['contest_count'])->toBe(6)
        ->and($report['candidate_count'])->toBe(268)
        ->and($contests->keys()->all())->toBe([
            'SENATOR',
            'PARTY LIST',
            'MEMBER, HOUSE OF REPRESENTATIVES',
            'MAYOR',
            'VICE-MAYOR',
            'COUNCILOR',
        ])
        ->and($contests->has('PRESIDENT'))->toBeFalse()
        ->and($contests['SENATOR']['max_selections'])->toBe(12)
        ->and($contests['PARTY LIST']['max_selections'])->toBe(1)
        ->and($contests['MEMBER, HOUSE OF REPRESENTATIVES']['max_selections'])->toBe(1)
        ->and($contests['MAYOR']['max_selections'])->toBe(1)
        ->and($contests['VICE-MAYOR']['max_selections'])->toBe(1)
        ->and($contests['COUNCILOR']['max_selections'])->toBe(6)
        ->and(count($contests['SENATOR']['candidate_ids']))->toBe(64)
        ->and(count($contests['PARTY LIST']['candidate_ids']))->toBe(156)
        ->and(count($contests['MEMBER, HOUSE OF REPRESENTATIVES']['candidate_ids']))->toBe(4)
        ->and(count($contests['MAYOR']['candidate_ids']))->toBe(11)
        ->and(count($contests['VICE-MAYOR']['candidate_ids']))->toBe(7)
        ->and(count($contests['COUNCILOR']['candidate_ids']))->toBe(26);

    $candidates = collect($definition['registries']['candidates']);

    expect($candidates->firstWhere('name', 'ABALOS, BENHUR (PFP)')['candidate_image']['status'])->toBe('placeholder')
        ->and($candidates->firstWhere('name', '4PS'))->not->toBeNull()
        ->and($candidates->firstWhere('name', 'DOMAGOSO, ISKO MORENO'))->not->toBeNull();
});

test('tondo precinct ballot definition can expose the full imported senator list', function (): void {
    config()->set('election.pop.candidate_limits.SENATOR', 0);

    $definition = app(PrecinctBallotDefinitionBuilder::class)->build('39010001', 'FIRST DIST');
    $contests = collect($definition['registries']['contests'])->keyBy('office');

    expect($definition['report']['candidate_count'])->toBe(387)
        ->and(count($contests['SENATOR']['candidate_ids']))->toBe(183);
});

test('tondo precinct ballot package activates deterministic configuration', function (): void {
    $activation = app(ActivatePrecinctBallotPackage::class)->handle('39010001', 'FIRST DIST');
    $configuration = app(ElectionStorage::class)->readJson('runtime/active-precinct.json');

    expect($activation['configuration']['precinct_id'])->toBe('39010001')
        ->and($configuration['mapping_hash'])->toBe($activation['configuration']['mapping_hash'])
        ->and($configuration['contests'])->toHaveCount(6)
        ->and(collect($configuration['contests'])->pluck('office')->contains('PRESIDENT'))->toBeFalse()
        ->and(collect($configuration['contests'])->sum(fn (array $contest): int => count($contest['candidates'])))->toBe(268)
        ->and(collect($configuration['contests'])
            ->flatMap(fn (array $contest): array => $contest['candidates'])
            ->pluck('political_party')
            ->filter()
            ->contains(fn (string $party): bool => str_contains($party, 'pertinent documents attached thereto')))->toBeFalse()
        ->and($activation['report']['artifact_path'])->toBeReadableFile()
        ->and(app(LifecycleState::class)->current())->toBe(Lifecycle::Certification);
});

test('voting route finalizes dynamic tondo contest selections', function (): void {
    app(ActivatePrecinctBallotPackage::class)->handle('39010001', 'FIRST DIST');
    app(LifecycleState::class)->set(Lifecycle::Voting);
    $configuration = app(ElectionStorage::class)->readJson('runtime/active-precinct.json');
    $selections = collect($configuration['contests'])
        ->mapWithKeys(fn (array $contest): array => [
            $contest['id'] => collect($contest['candidates'])
                ->take((int) $contest['max_selections'])
                ->pluck('id')
                ->all(),
        ])
        ->all();

    $response = $this->post(route('election.voting.finalize'), ['selections' => $selections]);

    $response->assertRedirect();

    expect($response->headers->get('Location'))->toContain('/election/printing/');

    $payloadPath = collect(app(ElectionStorage::class)->files('ballots'))
        ->first(fn (string $path): bool => str_ends_with($path, '.json'));

    expect($payloadPath)->toBeReadableFile();

    $payload = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($payload['selections']))->toEqualCanonicalizing(array_keys($selections))
        ->and(array_key_exists('president', $payload['selections']))->toBeFalse();
});
