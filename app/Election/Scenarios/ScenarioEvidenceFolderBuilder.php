<?php

namespace App\Election\Scenarios;

use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;

final class ScenarioEvidenceFolderBuilder
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly Filesystem $files,
        private readonly CanonicalJson $json,
        private readonly ElectionClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function build(string $scenario, array $report): array
    {
        $folder = $this->folderPath($scenario, $report);
        $this->files->ensureDirectoryExists($folder);

        $artifacts = [];

        foreach ($this->artifactGroups() as $group) {
            $this->files->ensureDirectoryExists($folder.'/'.$group['directory']);

            foreach ($group['sources'] as $source) {
                if (! $this->files->exists($source)) {
                    continue;
                }

                $artifacts[] = $this->copyArtifact($folder, $group['directory'], $group['category'], $source);
            }
        }

        $readmePath = $folder.'/README.txt';
        $this->files->put($readmePath, $this->readme($scenario, $report));
        $artifacts[] = $this->artifactRecord('overview', $readmePath, null, 'README.txt');

        $index = [
            'schema_version' => 'scenario-artifact-index-1',
            'scenario' => $scenario,
            'precinct_id' => $report['precinct_id'] ?? null,
            'generated_at' => $this->clock->now()->toIso8601String(),
            'runtime_root' => $this->storage->root(),
            'evidence_folder_path' => $folder,
            'artifacts' => $artifacts,
        ];
        $index['artifact_count'] = count($artifacts);
        $index['total_bytes'] = collect($artifacts)->sum(fn (array $artifact): int => (int) $artifact['bytes']);
        $index['index_hash'] = $this->json->hash($index);

        $indexPath = $folder.'/artifact-index.json';
        $this->files->put($indexPath, $this->json->encode($index));

        return [
            'evidence_folder_path' => $folder,
            'artifact_index_path' => $indexPath,
            'artifact_count' => $index['artifact_count'],
            'artifact_total_bytes' => $index['total_bytes'],
            'artifact_index_hash' => $index['index_hash'],
        ];
    }

    /**
     * @return array<int, array{category: string, directory: string, sources: array<int, string>}>
     */
    private function artifactGroups(): array
    {
        return [
            [
                'category' => 'device_initiation_scan_documents',
                'directory' => '01-device-initiation-scan-documents',
                'sources' => [
                    ...$this->matchingStorageFiles('ballots', 'cert-'),
                ],
            ],
            [
                'category' => 'device_and_certification_reports',
                'directory' => '02-device-and-certification-reports',
                'sources' => [
                    $this->storage->path('runtime/active-precinct.json'),
                    $this->storage->path('packages/active-package.json'),
                    ...$this->storage->files('certification'),
                ],
            ],
            [
                'category' => 'officer_attestations',
                'directory' => '03-officer-attestations',
                'sources' => [
                    ...$this->storage->files('attestations'),
                    ...$this->storage->files('attestation-signatures'),
                ],
            ],
            [
                'category' => 'ballots',
                'directory' => '04-ballots',
                'sources' => [
                    ...$this->matchingStorageFiles('ballots', 'demo-'),
                    ...$this->storage->files('print-jobs'),
                    ...$this->matchingStorageFiles('runtime', 'spoiled-'),
                ],
            ],
            [
                'category' => 'counting_and_tally',
                'directory' => '05-counting-and-tally',
                'sources' => [
                    ...$this->storage->files('counting/accepted'),
                    ...$this->storage->files('counting/rejected'),
                    $this->storage->path('runtime/tally.json'),
                ],
            ],
            [
                'category' => 'election_return',
                'directory' => '06-election-return',
                'sources' => $this->storage->files('returns'),
            ],
            [
                'category' => 'journal',
                'directory' => '07-journal',
                'sources' => [
                    ...$this->storage->files('journals'),
                    ...$this->storage->files('scenarios'),
                ],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function matchingStorageFiles(string $relativeDirectory, string $prefix): array
    {
        return collect($this->storage->files($relativeDirectory))
            ->filter(fn (string $path): bool => str_starts_with(basename($path), $prefix))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function copyArtifact(string $folder, string $directory, string $category, string $source): array
    {
        $target = $folder.'/'.$directory.'/'.basename($source);
        $this->files->copy($source, $target);

        return $this->artifactRecord($category, $target, $source, $directory.'/'.basename($target));
    }

    /**
     * @return array<string, mixed>
     */
    private function artifactRecord(string $category, string $path, ?string $sourcePath, string $relativePath): array
    {
        return [
            'category' => $category,
            'relative_path' => $relativePath,
            'source_path' => $sourcePath,
            'bytes' => filesize($path),
            'sha256' => hash_file('sha256', $path),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function folderPath(string $scenario, array $report): string
    {
        $precinct = $this->slug((string) ($report['precinct_id'] ?? 'unknown-precinct'));
        $scenario = $this->slug($scenario);
        $hash = substr($this->json->hash($report), 0, 12);

        return $this->storage->scenarioArtifactPath(
            $this->clock->now()->format('Y-m-d-His')."-{$precinct}-{$scenario}-{$hash}",
        );
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function readme(string $scenario, array $report): string
    {
        return implode(PHP_EOL, [
            'Alternative Election System Scenario Evidence Folder',
            "Scenario: {$scenario}",
            'Precinct: '.($report['precinct_id'] ?? 'unknown'),
            'Generated: '.$this->clock->now()->toIso8601String(),
            '',
            'Open the numbered folders in order to inspect scan documents, certification reports, officer signatures, ballots, counting records, tally artifacts, election return artifacts, and the journal.',
            'Use artifact-index.json to verify file sizes and SHA-256 hashes.',
            '',
        ]);
    }

    private function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value));
        $slug = trim($slug, '-');

        return $slug === '' ? 'unknown' : $slug;
    }
}
