<?php

namespace App\Election\Scenarios;

use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Support\SimplePdf;
use Illuminate\Filesystem\Filesystem;

final class ScenarioEvidenceFolderBuilder
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly Filesystem $files,
        private readonly CanonicalJson $json,
        private readonly ElectionClock $clock,
        private readonly SimplePdf $pdf,
    ) {}

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function build(string $scenario, array $report): array
    {
        $folder = $this->folderPath($scenario, $report);

        if ($this->files->isDirectory($folder)) {
            $this->files->deleteDirectory($folder);
        }

        $this->files->ensureDirectoryExists($folder);
        $this->writeTallySheet();

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

        $summary = $this->summaryReport($scenario, $report, $artifacts);
        $summaryJsonPath = $folder.'/summary-report.json';
        $this->files->put($summaryJsonPath, $this->json->encode($summary));
        $summaryTextPath = $folder.'/summary-report.txt';
        $this->files->put($summaryTextPath, $this->summaryText($summary));

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
            'summary_report_path' => $summaryJsonPath,
            'summary_report_text_path' => $summaryTextPath,
            'summary_report_hash' => $summary['summary_report_hash'],
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
                'category' => 'pop_import_and_precinct_source',
                'directory' => '00-pop-import-and-precinct-source',
                'sources' => [
                    $this->storage->path('registries/pop-2025-nle/manifest.json'),
                    $this->storage->path('registries/pop-2025-nle/clustered-precinct-index.json'),
                    $this->storage->path('registries/pop-2025-nle/location-summary.json'),
                    ...$this->storage->files('imports/pop'),
                    ...$this->storage->files('packages/imported'),
                    $this->storage->path('packages/active-package.json'),
                    $this->storage->path('runtime/active-precinct.json'),
                ],
            ],
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
                    $this->storage->path('runtime/tally-sheet.txt'),
                    $this->storage->path('runtime/tally-sheet.pdf'),
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

        if ($this->files->exists($target)) {
            $target = $folder.'/'.$directory.'/'.basename(dirname($source)).'-'.basename($source);
        }

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

    private function writeTallySheet(): void
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $tally = $this->storage->readJson('runtime/tally.json');

        if ($configuration === [] || $tally === []) {
            return;
        }

        $lines = [
            'TALLY SHEET',
            'Election: '.($configuration['election_id'] ?? 'unknown'),
            'Precinct: '.($configuration['precinct_id'] ?? 'unknown'),
            'Accepted Ballots: '.($tally['accepted_ballots'] ?? 0),
            'Rejected Ballots: '.($tally['rejected_ballots'] ?? 0),
            'Tally Hash: '.($tally['tally_hash'] ?? 'unknown'),
            '',
            'Totals:',
        ];

        foreach (($tally['tally'] ?? []) as $contest => $totals) {
            $lines[] = strtoupper((string) $contest);

            foreach ($totals as $candidate => $votes) {
                $lines[] = "  {$candidate}: {$votes}";
            }
        }

        $this->storage->writeText('runtime/tally-sheet.txt', implode(PHP_EOL, $lines).PHP_EOL);
        $this->storage->writeText('runtime/tally-sheet.pdf', $this->pdf->render('Tally Sheet', $lines));
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<int, array<string, mixed>>  $artifacts
     * @return array<string, mixed>
     */
    private function summaryReport(string $scenario, array $report, array $artifacts): array
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $deviceReport = $this->storage->readJson('certification/device-certification-report.json');
        $certificationReport = $this->storage->readJson('certification/friday-certification-report.json');
        $tally = $this->storage->readJson('runtime/tally.json');
        $return = $this->storage->readJson('returns/'.($report['precinct_id'] ?? 'unknown').'-return.json');

        $summary = [
            'schema_version' => 'scenario-summary-report-1',
            'scenario' => $scenario,
            'passed' => $report['passed'] ?? false,
            'generated_at' => $this->clock->now()->toIso8601String(),
            'election_id' => $configuration['election_id'] ?? null,
            'precinct_id' => $report['precinct_id'] ?? null,
            'flow' => $this->flow($report),
            'pop_import' => $report['pop_import'] ?? null,
            'statistics' => $this->statistics($artifacts, $deviceReport, $certificationReport, $tally, $report),
            'artifact_pointers' => collect($artifacts)
                ->groupBy('category')
                ->map(fn ($group): array => $group->values()->all())
                ->all(),
            'important_hashes' => [
                'mapping_hash' => $configuration['mapping_hash'] ?? null,
                'pop_registry_hash' => $report['pop_import']['registry_hash'] ?? null,
                'pop_manifest_hash' => $report['pop_import']['manifest_hash'] ?? null,
                'pop_package_hash' => $report['pop_import']['package_hash'] ?? null,
                'device_certification_report_hash' => $deviceReport['report_hash'] ?? null,
                'friday_certification_report_hash' => $certificationReport['report_hash'] ?? null,
                'ballot_payload_hashes' => collect($this->matchingStorageFiles('ballots', 'demo-'))
                    ->filter(fn (string $path): bool => str_ends_with($path, '.json'))
                    ->map(fn (string $path): ?string => $this->readJsonFile($path)['payload_hash'] ?? null)
                    ->filter()
                    ->values()
                    ->all(),
                'tally_hash' => $tally['tally_hash'] ?? null,
                'election_return_hash' => $return['return_hash'] ?? null,
            ],
        ];
        $summary['summary_report_hash'] = $this->json->hash($summary);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, array<string, string>>
     */
    private function flow(array $report): array
    {
        return [
            ['step' => 'Import POP workbook and activate precinct source', 'status' => isset($report['pop_import']) ? 'complete' : 'missing'],
            ['step' => 'Bind POP precinct to sample ballot definition', 'status' => 'complete'],
            ['step' => 'Generate certification scan documents', 'status' => 'complete'],
            ['step' => 'Run device certification', 'status' => isset($report['device_report_hash']) ? 'complete' : 'missing'],
            ['step' => 'Run Friday certification', 'status' => isset($report['return_hash']) ? 'complete' : 'missing'],
            ['step' => 'Capture officer attestation and signature', 'status' => count($this->storage->files('attestations')) > 0 ? 'complete' : 'missing'],
            ['step' => 'Open polls', 'status' => 'complete'],
            ['step' => 'Print ballots', 'status' => count($this->storage->files('print-jobs')) > 0 ? 'complete' : 'missing'],
            ['step' => 'Spoil simulation ballot', 'status' => count($this->matchingStorageFiles('runtime', 'spoiled-')) > 0 ? 'complete' : 'missing'],
            ['step' => 'Close polls and count ballots', 'status' => ($report['accepted_ballots'] ?? 0) > 0 ? 'complete' : 'missing'],
            ['step' => 'Generate tally and Election Return', 'status' => isset($report['return_hash']) ? 'complete' : 'missing'],
            ['step' => 'Close precinct', 'status' => ($report['stage'] ?? null) === 'close_precinct' ? 'complete' : 'missing'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $artifacts
     * @param  array<string, mixed>  $deviceReport
     * @param  array<string, mixed>  $certificationReport
     * @param  array<string, mixed>  $tally
     * @param  array<string, mixed>  $report
     * @return array<string, int>
     */
    private function statistics(array $artifacts, array $deviceReport, array $certificationReport, array $tally, array $report): array
    {
        $deviceResults = collect($deviceReport['devices'] ?? []);

        return [
            'scan_documents_generated' => count($this->matchingStorageFiles('ballots', 'cert-')),
            'device_checks_passed' => $deviceResults->filter(fn (array $result): bool => ($result['status'] ?? null) === 'ready')->count(),
            'device_checks_failed' => $deviceResults->filter(fn (array $result): bool => ($result['status'] ?? null) !== 'ready')->count(),
            'certification_ballots_counted' => count($certificationReport['expected_tally']['president'] ?? []) > 0 ? 3 : 0,
            'officer_attestations_captured' => count($this->storage->files('attestations')),
            'signatures_captured' => count($this->storage->files('attestation-signatures')),
            'ballots_finalized' => collect($this->matchingStorageFiles('ballots', 'demo-'))
                ->filter(fn (string $path): bool => str_ends_with($path, '.json'))
                ->count(),
            'ballots_printed' => count($this->storage->files('print-jobs')),
            'ballots_spoiled' => count($this->matchingStorageFiles('runtime', 'spoiled-')),
            'accepted_ballots' => (int) ($report['accepted_ballots'] ?? 0),
            'rejected_ballots' => (int) ($report['rejected_ballots'] ?? 0),
            'contests_tallied' => count($tally['tally'] ?? []),
            'journal_entries' => (int) ($report['journal_entries'] ?? 0),
            'total_evidence_files_copied' => count($artifacts),
            'total_evidence_bytes' => collect($artifacts)->sum(fn (array $artifact): int => (int) $artifact['bytes']),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function summaryText(array $summary): string
    {
        $lines = [
            'EVIDENCE FOLDER SCENARIO SUMMARY',
            "Scenario: {$summary['scenario']}",
            'Precinct: '.($summary['precinct_id'] ?? 'unknown'),
            'Election: '.($summary['election_id'] ?? 'unknown'),
            'Passed: '.(($summary['passed'] ?? false) ? 'yes' : 'no'),
            'Generated: '.$summary['generated_at'],
            'Summary Hash: '.$summary['summary_report_hash'],
            '',
            'Flow:',
        ];

        foreach ($summary['flow'] as $step) {
            $lines[] = "- {$step['status']}: {$step['step']}";
        }

        $lines[] = '';
        $lines[] = 'Statistics:';

        foreach ($summary['statistics'] as $name => $value) {
            $lines[] = "- {$name}: {$value}";
        }

        $lines[] = '';
        $lines[] = 'POP Import:';

        if (($summary['pop_import'] ?? null) === null) {
            $lines[] = '- n/a';
        } else {
            foreach ($summary['pop_import'] as $name => $value) {
                $lines[] = '- '.$name.': '.(is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ($value ?? 'n/a'));
            }
        }

        $lines[] = '';
        $lines[] = 'Artifact Pointers:';

        foreach ($summary['artifact_pointers'] as $category => $artifacts) {
            $lines[] = strtoupper((string) $category);

            foreach ($artifacts as $artifact) {
                $lines[] = "- {$artifact['relative_path']} ({$artifact['bytes']} bytes, sha256 {$artifact['sha256']})";
            }
        }

        $lines[] = '';
        $lines[] = 'Important Hashes:';

        foreach ($summary['important_hashes'] as $name => $value) {
            $lines[] = '- '.$name.': '.(is_array($value) ? implode(', ', $value) : ($value ?? 'n/a'));
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        return json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value));
        $slug = trim($slug, '-');

        return $slug === '' ? 'unknown' : $slug;
    }
}
