<?php

use App\Election\Interoperability\Eml\EmlArtifactService;
use App\Election\Interoperability\Eml\EmlConfigurationStager;
use App\Election\Support\ElectionStorage;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
});

function stagingConfiguration(): array
{
    return [
        'election_id' => 'WAES-STAGING-2026',
        'election_name' => 'WAES Staging Election',
        'precinct_id' => '39010402',
        'ballot_style_id' => 'BS-39010402',
        'mapping_hash' => str_repeat('f', 64),
        'contests' => [[
            'id' => 'mayor',
            'title' => 'Mayor',
            'max_selections' => 1,
            'candidates' => [
                ['id' => 'mayor-001', 'name' => 'Candidate One'],
                ['id' => 'mayor-002', 'name' => 'Candidate Two'],
            ],
        ]],
    ];
}

test('validated EML configuration is staged without activating it', function (): void {
    $storage = app(ElectionStorage::class);
    $storage->writeJson('runtime/active-precinct.json', ['election_id' => 'ACTIVE-ELECTION']);
    $references = app(EmlArtifactService::class)->configurationArtifacts(stagingConfiguration());
    $artifacts = collect($references)->mapWithKeys(fn (array $reference, string $type): array => [
        $type => $storage->readText($reference['path']),
    ])->all();
    $staged = app(EmlConfigurationStager::class)->stage($artifacts);

    expect($staged)
        ->status->toBe('staged_not_active')
        ->election_id->toBe('WAES-STAGING-2026')
        ->precinct_id->toBe('39010402')
        ->ballot_style_id->toBe('BS-39010402')
        ->and($staged['contests'][0]['title'])->toBe('Mayor')
        ->and($staged['contests'][0]['candidates'][0]['name'])->toBe('Candidate One')
        ->and($storage->readJson('runtime/active-precinct.json')['election_id'])->toBe('ACTIVE-ELECTION')
        ->and($storage->path("interoperability/eml/staging/{$staged['staging_id']}.json"))->toBeReadableFile();
});

test('configuration staging rejects artifacts from different elections', function (): void {
    $storage = app(ElectionStorage::class);
    $references = app(EmlArtifactService::class)->configurationArtifacts(stagingConfiguration());
    $artifacts = collect($references)->mapWithKeys(fn (array $reference, string $type): array => [
        $type => $storage->readText($reference['path']),
    ])->all();
    $artifacts['230'] = str_replace('WAES-STAGING-2026', 'OTHER-ELECTION', $artifacts['230']);

    app(EmlConfigurationStager::class)->stage($artifacts);
})->throws(RuntimeException::class, 'do not identify the same election');

test('offline verifier validates an artifact and detached signature', function (): void {
    $reference = app(EmlArtifactService::class)->configurationArtifacts(stagingConfiguration())['110'];
    $path = app(ElectionStorage::class)->path($reference['path']);

    $this->artisan('election:eml-verify', ['artifact' => $path])
        ->expectsOutputToContain('"valid": true')
        ->assertSuccessful();
});
