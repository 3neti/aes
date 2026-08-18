<?php

use App\Election\Preparation\BallotPdfPartyReference;
use App\Election\Preparation\ClcCandidateImporter;
use App\Election\Support\ElectionStorage;
use App\Election\Support\GhostscriptPdfTextExtractor;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
});

test('clc candidate import writes deterministic registry artifacts from manila district workbook', function (): void {
    $this->artisan('election:clc-import')
        ->expectsOutput('Candidate source imported.')
        ->expectsOutput('Sources: 1')
        ->expectsOutputToContain('Candidates: ')
        ->expectsOutputToContain('Needs review: ')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $manifest = $storage->readJson('registries/clc-2025-nle/manifest.json');
    $candidates = clcCandidates();

    expect($manifest['source_count'])->toBe(1)
        ->and($manifest['profile'])->toBe('manila-districts-ballot-workbook')
        ->and($manifest['candidate_count'])->toBe(200)
        ->and($manifest['needs_review_count'])->toBe(0)
        ->and($manifest['party_reference']['matched_candidates'])->toBeGreaterThan(100)
        ->and($manifest['candidates_path'])->toBeReadableFile()
        ->and($manifest['contests_path'])->toBeReadableFile()
        ->and($manifest['source_files_path'])->toBeReadableFile()
        ->and($manifest['needs_review_path'])->toBeReadableFile()
        ->and(clcFindCandidate($candidates, 'Manila_Districts_1and2.xlsx', 'MARCOS, BONGBONG'))->not->toBeNull()
        ->and(clcFindCandidate($candidates, 'Manila_Districts_1and2.xlsx', 'VALERIANO, ROLAN CRV'))->not->toBeNull()
        ->and(clcFindCandidate($candidates, 'Manila_Districts_1and2.xlsx', 'KAMALAYAN'))->not->toBeNull();

    $candidate = clcFindCandidate($candidates, 'Manila_Districts_1and2.xlsx', 'MARCOS, BONGBONG');

    expect($candidate['political_party'])->toBe('PFP')
        ->and($candidate['candidate_image']['status'])->toBe('placeholder')
        ->and($candidate['candidate_image']['alt_text'])->toBe('Candidate photo placeholder for MARCOS, BONGBONG');

    $firstHash = $manifest['registry_hash'];
    app(ClcCandidateImporter::class)->import();

    expect($storage->readJson('registries/clc-2025-nle/manifest.json')['registry_hash'])->toBe($firstHash);
});

test('ballot pdf party reference resolves wrapped party labels from the manila facsimile', function (): void {
    $reference = app(BallotPdfPartyReference::class);
    $parties = $reference->parties(resource_path('election/ballots/MANILA-2ND_DISTRICT.pdf'));

    expect($parties)->toHaveKey($reference->key('ABELLA, ERNIE'), 'IND')
        ->and($parties)->toHaveKey($reference->key('GONZALES, NORBERTO'), 'PDSP')
        ->and($parties)->toHaveKey($reference->key('MARCOS, BONGBONG'), 'PFP')
        ->and($parties)->toHaveKey($reference->key('VALERIANO, ROLAN CRV'), 'NUP')
        ->and($parties)->toHaveKey($reference->key('LACUNA, HONEY'), 'ASENSO')
        ->and($parties)->toHaveKey($reference->key('SAHIDULLA, LADY ANNE'), 'PDDS');
});

test('clc candidate import reports missing ghostscript clearly', function (): void {
    config()->set('election.pdf.ghostscript_binary', 'missing-gs-binary-for-test');

    $this->artisan('election:clc-import', ['source' => resource_path('election/clc')])
        ->expectsOutputToContain('Unable to extract PDF text with Ghostscript')
        ->assertFailed();
});

test('pdf extractor accepts an explicitly configured executable path', function (): void {
    $binary = storage_path('framework/testing/fake-ghostscript');
    $pdf = storage_path('framework/testing/fake-source.pdf');
    file_put_contents($binary, "#!/bin/sh\nprintf 'Candidate list fixture'\n");
    chmod($binary, 0755);
    file_put_contents($pdf, 'fixture');
    config()->set('election.pdf.ghostscript_binary', $binary);

    $pages = app(GhostscriptPdfTextExtractor::class)->extract($pdf);

    expect($pages)->toHaveCount(1)
        ->and($pages[0]->page)->toBe(1)
        ->and($pages[0]->text)->toBe('Candidate list fixture');
});

/**
 * @return array<int, array<string, mixed>>
 */
function clcCandidates(): array
{
    $path = app(ElectionStorage::class)->readJson('registries/clc-2025-nle/manifest.json')['candidates_path'];

    return collect(explode("\n", trim(file_get_contents($path))))
        ->filter()
        ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR))
        ->values()
        ->all();
}

/**
 * @param  array<int, array<string, mixed>>  $candidates
 * @return array<string, mixed>|null
 */
function clcFindCandidate(array $candidates, string $sourceFile, string $nameOnBallot): ?array
{
    return collect($candidates)
        ->first(fn (array $candidate): bool => $candidate['source_file'] === $sourceFile && $candidate['name_on_ballot'] === $nameOnBallot);
}
