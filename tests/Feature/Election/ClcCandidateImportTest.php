<?php

use App\Election\Preparation\ClcCandidateImporter;
use App\Election\Support\ElectionStorage;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
});

test('clc candidate import writes deterministic registry artifacts from repository pdf fixtures', function (): void {
    $this->artisan('election:clc-import')
        ->expectsOutput('CLC candidate PDFs imported.')
        ->expectsOutput('Sources: 21')
        ->expectsOutputToContain('Candidates: ')
        ->expectsOutputToContain('Needs review: ')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $manifest = $storage->readJson('registries/clc-2025-nle/manifest.json');
    $candidates = clcCandidates();

    expect($manifest['source_count'])->toBe(21)
        ->and($manifest['candidate_count'])->toBeGreaterThan(1200)
        ->and($manifest['needs_review_count'])->toBeGreaterThanOrEqual(0)
        ->and($manifest['candidates_path'])->toBeReadableFile()
        ->and($manifest['contests_path'])->toBeReadableFile()
        ->and($manifest['source_files_path'])->toBeReadableFile()
        ->and($manifest['needs_review_path'])->toBeReadableFile()
        ->and(clcFindCandidate($candidates, 'CLC2025_Senator.pdf', 'ABALOS, BENHUR (PFP)'))->not->toBeNull()
        ->and(clcFindCandidate($candidates, 'CLC2025_Partylist.pdf', '4PS'))->not->toBeNull()
        ->and(clcFindCandidate($candidates, 'MANILA.pdf', 'DOMAGOSO, ISKO MORENO'))->not->toBeNull()
        ->and(clcFindCandidate($candidates, 'CITY_OF_MAKATI.pdf', 'BINAY, NANCY (UNA)'))->not->toBeNull();

    $candidate = clcFindCandidate($candidates, 'CLC2025_Senator.pdf', 'ABALOS, BENHUR (PFP)');

    expect($candidate['candidate_image']['status'])->toBe('placeholder')
        ->and($candidate['candidate_image']['alt_text'])->toBe('Candidate photo placeholder for ABALOS, BENHUR (PFP)');

    $firstHash = $manifest['registry_hash'];
    app(ClcCandidateImporter::class)->import();

    expect($storage->readJson('registries/clc-2025-nle/manifest.json')['registry_hash'])->toBe($firstHash);
});

test('clc candidate import reports missing ghostscript clearly', function (): void {
    config()->set('election.pdf.ghostscript_binary', 'missing-gs-binary-for-test');

    $this->artisan('election:clc-import')
        ->expectsOutputToContain('Unable to extract PDF text with Ghostscript')
        ->assertFailed();
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
