<?php

namespace App\Election\Diagnostics;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;

final class EvidenceReferenceBaselineService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function write(): array
    {
        $runPath = $this->storage->activeRunPath();
        $runPathTail = basename($runPath);
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $precinct = (string) ($configuration['precinct_id'] ?? 'unknown');

        $artifactReferences = $this->collectReferences($runPath, $precinct);
        $missingRequired = collect($artifactReferences)->where('required', true)->where('found', false)->values()->all();

        $report = [
            'schema_version' => 'evidence-reference-baseline-1',
            'baseline_profile' => 'legal-baseline-v1',
            'generated_at' => $this->clock->now()->toIso8601String(),
            'run_id' => $runPathTail,
            'precinct_id' => $precinct,
            'evidence_root' => basename(dirname($runPath)),
            'run_path' => $runPath,
            'required_reference_count' => collect($artifactReferences)->where('required', true)->count(),
            'artifact_reference_count' => count($artifactReferences),
            'missing_required_reference_count' => count($missingRequired),
            'artifact_references' => collect($artifactReferences)
                ->map(fn (array $reference): array => [
                    'reference_type' => $reference['reference_type'],
                    'relative_path' => $reference['relative_path'],
                    'bytes' => $reference['bytes'],
                    'sha256' => $reference['sha256'],
                ])
                ->all(),
        ];

        if ($missingRequired !== []) {
            $report['missing_required_references'] = collect($missingRequired)
                ->map(fn (array $reference): string => $reference['reference_type'])
                ->values()
                ->all();
        } else {
            $report['missing_required_references'] = [];
        }

        $runSummaryPath = $runPath.'/run-summary.json';
        if ($this->files->exists($runSummaryPath)) {
            $report['run_summary_hash'] = (string) hash_file('sha256', $runSummaryPath);
        }

        $artifactIndexPath = $runPath.'/artifact-index.json';
        if ($this->files->exists($artifactIndexPath)) {
            $report['artifact_index_hash'] = (string) hash_file('sha256', $artifactIndexPath);
        }

        $report['baseline_hash'] = $this->json->hash($report);
        $report['artifact_path'] = $this->storage->writeJson('diagnostics/evidence-reference-baseline.json', $report);

        $this->journal->record('evidence_reference_baseline.generated', [
            'run_id' => $runPathTail,
            'precinct_id' => $precinct,
            'artifact_reference_count' => $report['artifact_reference_count'],
            'missing_required_reference_count' => $report['missing_required_reference_count'],
            'baseline_hash' => $report['baseline_hash'],
        ]);

        return $report;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectReferences(string $runPath, string $precinct): array
    {
        $paths = [
            ['reference_type' => 'run_summary', 'relative_path' => 'run-summary.json', 'required' => true],
            ['reference_type' => 'artifact_index', 'relative_path' => 'artifact-index.json', 'required' => true],
            ['reference_type' => 'active_precinct_config', 'relative_path' => '01-precinct-preparation/active-precinct.json', 'required' => true],
            ['reference_type' => 'active_package', 'relative_path' => '01-precinct-preparation/active-package.json', 'required' => true],
            ['reference_type' => 'device_certification_report', 'relative_path' => '02-device-certification/device-certification-report.json', 'required' => true],
            ['reference_type' => 'attestation_manifest', 'relative_path' => '03-polls-opening/attestations', 'required' => false],
            ['reference_type' => 'signature_manifest', 'relative_path' => '03-polls-opening/signatures', 'required' => false],
            ['reference_type' => 'tally_sheet_txt', 'relative_path' => '06-counting-and-tally/tally-sheet.txt', 'required' => true],
            ['reference_type' => 'tally_sheet_pdf', 'relative_path' => '06-counting-and-tally/tally-sheet.pdf', 'required' => true],
            ['reference_type' => 'election_return_json', 'relative_path' => "07-election-return/{$precinct}-return.json", 'required' => true],
            ['reference_type' => 'election_return_pdf', 'relative_path' => "07-election-return/{$precinct}-return.pdf", 'required' => true],
            ['reference_type' => 'election_return_text', 'relative_path' => "07-election-return/{$precinct}-return.txt", 'required' => false],
            ['reference_type' => 'transmission_report', 'relative_path' => '09-exports-and-verification/transmission/transmission-report.json', 'required' => false],
            ['reference_type' => 'custody_record', 'relative_path' => '09-exports-and-verification/custody/custody-record.json', 'required' => false],
            ['reference_type' => 'evidence_manifest', 'relative_path' => '09-exports-and-verification/evidence-manifest.json', 'required' => false],
            ['reference_type' => 'evidence_bundle_archive', 'relative_path' => '09-exports-and-verification/evidence-bundle-archive.json', 'required' => false],
            ['reference_type' => 'scenario_report', 'relative_path' => '00-start-here', 'required' => false],
            ['reference_type' => 'jurisdiction_journal', 'relative_path' => '10-journal', 'required' => false],
        ];

        return collect($paths)
            ->map(fn (array $reference): array => $this->expandReference($runPath, $reference))
            ->flatten(1)
            ->values()
            ->sortBy('relative_path')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $reference
     * @return array<int, array<string, mixed>>
     */
    private function expandReference(string $runPath, array $reference): array
    {
        $path = $runPath.'/'.$reference['relative_path'];

        if (is_dir($path)) {
            return collect($this->files->allFiles($path))
                ->map(fn ($file) => [
                    'reference_type' => (string) $reference['reference_type'],
                    'relative_path' => $this->normalizePath($runPath, $file->getPathname()),
                    'bytes' => (int) $file->getSize(),
                    'sha256' => (string) hash_file('sha256', $file->getPathname()),
                    'required' => (bool) $reference['required'],
                    'found' => true,
                ])
                ->values()
                ->all();
        }

        return [array_merge($reference, [
            'relative_path' => (string) $reference['relative_path'],
            'bytes' => $this->files->exists($path) ? (int) $this->files->size($path) : 0,
            'sha256' => $this->files->exists($path) ? (string) hash_file('sha256', $path) : null,
            'found' => $this->files->exists($path),
        ])];
    }

    private function normalizePath(string $runPath, string $absolutePath): string
    {
        return (string) str_replace($runPath.'/', '', $absolutePath);
    }
}
