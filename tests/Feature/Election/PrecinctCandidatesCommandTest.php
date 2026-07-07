<?php

use App\Election\Support\ElectionStorage;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
    $this->artisan('election:pop-import', ['path' => resource_path('election/pop/2025NLE_POP.xlsx')])->assertSuccessful();
    $this->artisan('election:clc-import')->assertSuccessful();
});

test('precinct candidates command combines pop precinct and clc national candidates', function (): void {
    $this->artisan('election:precinct-candidates', [
        'clustered_precinct' => '7010001',
        '--write-report' => true,
    ])
        ->expectsOutput('Candidates for clustered precinct 7010001')
        ->expectsOutput('Location: CITY OF ISABELA, BASILAN')
        ->expectsOutputToContain('SENATOR - PHILIPPINES')
        ->expectsOutputToContain('ABALOS, BENHUR (PFP)')
        ->expectsOutputToContain('PARTY LIST - PHILIPPINES')
        ->expectsOutputToContain('4PS')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $report = app(ElectionStorage::class)->readJson('precinct-candidates/7010001-candidates.json');

    expect($report['clustered_precinct'])->toBe('7010001')
        ->and($report['precinct']['city_municipality'])->toBe('CITY OF ISABELA')
        ->and($report['contest_count'])->toBeGreaterThanOrEqual(2)
        ->and($report['candidate_count'])->toBeGreaterThan(200)
        ->and($report['clc_registry_hash'])->toBeString()
        ->and(app(ElectionStorage::class)->path('precinct-candidates/7010001-candidates.txt'))->toBeReadableFile();
});

test('precinct candidates command requires district when local contests are ambiguous', function (): void {
    $this->artisan('election:precinct-candidates', ['clustered_precinct' => '76010001'])
        ->expectsOutputToContain('District is required for this precinct')
        ->assertFailed();
});

test('precinct candidates command resolves local district contests when district is supplied', function (): void {
    $this->artisan('election:precinct-candidates', [
        'clustered_precinct' => '76010001',
        '--district' => 'FIRST DIST',
    ])
        ->expectsOutput('Candidates for clustered precinct 76010001')
        ->expectsOutputToContain('MAYOR - NCR - CITY OF LAS PIÑAS')
        ->expectsOutputToContain('COUNCILOR - NCR - CITY OF LAS PIÑAS - FIRST DIST')
        ->assertSuccessful();
});
