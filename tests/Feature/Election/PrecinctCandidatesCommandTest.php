<?php

use App\Election\Support\ElectionStorage;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
    $this->artisan('election:pop-import', ['path' => resource_path('election/pop/2025NLE_POP.xlsx')])->assertSuccessful();
    $this->artisan('election:clc-import')->assertSuccessful();
});

test('precinct candidates command combines pop precinct and clc manila candidates', function (): void {
    $this->artisan('election:precinct-candidates', [
        'clustered_precinct' => '39010001',
        '--district' => 'FIRST DIST',
        '--write-report' => true,
    ])
        ->expectsOutput('Candidates for clustered precinct 39010001')
        ->expectsOutput('Location: TONDO, NCR - MANILA')
        ->expectsOutputToContain('SENATOR - PHILIPPINES')
        ->expectsOutputToContain('ABALOS, BENHUR (PFP)')
        ->expectsOutputToContain('PARTY LIST - PHILIPPINES')
        ->expectsOutputToContain('4PS')
        ->expectsOutputToContain('MAYOR - NCR - CITY OF MANILA')
        ->expectsOutputToContain('DOMAGOSO, ISKO MORENO')
        ->expectsOutputToContain('COUNCILOR - NCR - CITY OF MANILA - FIRST DIST')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $report = app(ElectionStorage::class)->readJson('precinct-candidates/39010001-candidates.json');

    expect($report['clustered_precinct'])->toBe('39010001')
        ->and($report['precinct']['city_municipality'])->toBe('TONDO')
        ->and($report['contest_count'])->toBeGreaterThanOrEqual(6)
        ->and($report['candidate_count'])->toBeGreaterThan(350)
        ->and($report['clc_registry_hash'])->toBeString()
        ->and(app(ElectionStorage::class)->path('precinct-candidates/39010001-candidates.txt'))->toBeReadableFile();
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
