<?php

namespace App\Election\Diagnostics;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;

final class DiagnosticsService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ActivityJournal $journal,
        private readonly CanonicalJson $json,
        private readonly ElectionClock $clock,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return [
            'storage_root' => $this->storage->root(),
            'configuration' => $this->storage->readJson('runtime/active-precinct.json'),
            'package' => $this->storage->readJson('packages/active-package.json'),
            'journal_entries' => count($this->journal->entries()),
            'accepted_ballots' => count($this->storage->files('counting/accepted')),
            'rejected_ballots' => count($this->storage->files('counting/rejected')),
            'attestations' => count($this->storage->files('attestations')),
            'attestation_artifacts' => $this->attestationArtifacts(),
            'evidence_manifest' => $this->manifestSummary(),
            'evidence_reference_baseline' => $this->referenceBaselineSummary(),
            'official_minutes_baseline' => $this->officialMinutesSummary(),
            'removable_media_export' => $this->removableMediaExportSummary(),
            'removable_media_readiness' => $this->removableMediaReadinessSummary(),
            'evidence_bundle_archive' => $this->evidenceBundleArchiveSummary(),
            'evidence_bundle_archive_verification' => $this->evidenceBundleArchiveVerificationSummary(),
            'evidence_export_verification' => $this->evidenceExportVerificationSummary(),
            'printer' => config('election.devices.printer.adapter', 'simulated'),
            'scanner' => config('election.devices.scanner.adapter', 'simulated'),
            'device_certification' => $this->storage->readJson('certification/device-certification-report.json'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function writeEvidenceManifest(): array
    {
        $manifest = [
            'schema_version' => 'precinct-evidence-manifest-1',
            'generated_at' => $this->clock->now()->toIso8601String(),
            'configuration' => $this->storage->readJson('runtime/active-precinct.json'),
            'package' => $this->storage->readJson('packages/active-package.json'),
            'categories' => $this->manifestCategories(),
        ];
        $manifest['manifest_hash'] = $this->json->hash($manifest);
        $manifest['artifact_path'] = $this->storage->writeJson('diagnostics/evidence-manifest.json', $manifest);

        return $manifest;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function attestationArtifacts(): array
    {
        return collect($this->storage->files('attestations'))
            ->map(function (string $path): array {
                $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                $signaturePath = (string) ($record['signature_artifact_path'] ?? '');

                return [
                    'attestation_id' => $record['attestation_id'] ?? basename($path, '.json'),
                    'attested_at' => $record['attested_at'] ?? null,
                    'ceremony' => $record['ceremony'] ?? null,
                    'stage' => $record['stage'] ?? null,
                    'officer_name' => $record['officer_name'] ?? null,
                    'officer_role' => $record['officer_role'] ?? null,
                    'attestation_hash' => $record['attestation_hash'] ?? null,
                    'attestation_artifact' => basename($path),
                    'attestation_url' => route('election.diagnostics.attestations.show', basename($path)),
                    'attestation_download_url' => route('election.diagnostics.attestations.download', basename($path)),
                    'signature_artifact_hash' => $record['signature_artifact_hash'] ?? null,
                    'signature_artifact' => $signaturePath === '' ? null : basename($signaturePath),
                    'signature_url' => $signaturePath === '' ? null : route('election.diagnostics.signatures.show', basename($signaturePath)),
                    'signature_download_url' => $signaturePath === '' ? null : route('election.diagnostics.signatures.download', basename($signaturePath)),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestSummary(): array
    {
        $path = $this->storage->path('diagnostics/evidence-manifest.json');

        if (! file_exists($path)) {
            return [
                'exists' => false,
                'generate_url' => route('election.diagnostics.evidence-manifest.generate'),
                'download_url' => route('election.diagnostics.evidence-manifest.download'),
            ];
        }

        $manifest = $this->storage->readJson('diagnostics/evidence-manifest.json');

        return [
            'exists' => true,
            'artifact' => basename($path),
            'manifest_hash' => $manifest['manifest_hash'] ?? null,
            'generated_at' => $manifest['generated_at'] ?? null,
            'categories' => collect($manifest['categories'] ?? [])
                ->map(fn (array $category): int => count($category['files'] ?? []))
                ->all(),
            'generate_url' => route('election.diagnostics.evidence-manifest.generate'),
            'download_url' => route('election.diagnostics.evidence-manifest.download'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceBaselineSummary(): array
    {
        $path = $this->storage->path('diagnostics/evidence-reference-baseline.json');

        if (! $this->files->exists($path)) {
            return [
                'exists' => false,
                'generate_url' => route('election.diagnostics.evidence-reference-baseline.generate'),
                'download_url' => route('election.diagnostics.evidence-reference-baseline.download'),
            ];
        }

        $baseline = $this->storage->readJson('diagnostics/evidence-reference-baseline.json');

        return [
            'exists' => true,
            'artifact' => basename($path),
            'run_id' => $baseline['run_id'] ?? null,
            'precinct_id' => $baseline['precinct_id'] ?? null,
            'generated_at' => $baseline['generated_at'] ?? null,
            'baseline_hash' => $baseline['baseline_hash'] ?? null,
            'artifact_reference_count' => $baseline['artifact_reference_count'] ?? 0,
            'required_reference_count' => $baseline['required_reference_count'] ?? 0,
            'missing_required_reference_count' => $baseline['missing_required_reference_count'] ?? 0,
            'generate_url' => route('election.diagnostics.evidence-reference-baseline.generate'),
            'download_url' => route('election.diagnostics.evidence-reference-baseline.download'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function officialMinutesSummary(): array
    {
        $path = $this->storage->path('diagnostics/official-minutes-baseline.json');

        if (! $this->files->exists($path)) {
            return [
                'exists' => false,
                'generate_url' => route('election.diagnostics.official-minutes-baseline.generate'),
                'download_url' => route('election.diagnostics.official-minutes-baseline.download'),
            ];
        }

        $baseline = $this->storage->readJson('diagnostics/official-minutes-baseline.json');

        return [
            'exists' => true,
            'artifact' => basename($path),
            'run_id' => $baseline['run_id'] ?? null,
            'precinct_id' => $baseline['precinct_id'] ?? null,
            'generated_at' => $baseline['generated_at'] ?? null,
            'official_minute_hash' => $baseline['official_minute_hash'] ?? null,
            'minute_count' => $baseline['minute_count'] ?? 0,
            'source_journal_event_count' => $baseline['source_journal_event_count'] ?? 0,
            'source_attestation_count' => $baseline['source_attestation_count'] ?? 0,
            'generate_url' => route('election.diagnostics.official-minutes-baseline.generate'),
            'download_url' => route('election.diagnostics.official-minutes-baseline.download'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function removableMediaExportSummary(): array
    {
        $root = $this->removableMediaRoot();
        $this->files->ensureDirectoryExists($root);

        $exportReports = collect($this->files->directories($root))
            ->map(fn (string $directory): string => $directory.'/export-report.json')
            ->filter(fn (string $path): bool => $this->files->exists($path))
            ->sort()
            ->values();

        if ($exportReports->isEmpty()) {
            return [
                'exists' => false,
                'target_root' => $root,
                'export_url' => route('election.diagnostics.removable-media.export'),
            ];
        }

        $latestPath = $exportReports->last();
        $report = json_decode($this->files->get($latestPath), true, flags: JSON_THROW_ON_ERROR);

        return [
            'exists' => true,
            'target_root' => $root,
            'export_url' => route('election.diagnostics.removable-media.export'),
            'export_id' => $report['export_id'] ?? basename(dirname($latestPath)),
            'exported_at' => $report['exported_at'] ?? null,
            'target_path' => $report['target_path'] ?? dirname($latestPath),
            'manifest_hash' => $report['manifest_hash'] ?? null,
            'export_hash' => $report['export_hash'] ?? null,
            'artifact_count' => $report['artifact_count'] ?? 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function removableMediaReadinessSummary(): array
    {
        $path = $this->storage->path('diagnostics/removable-media-readiness.json');

        if (! $this->files->exists($path)) {
            return [
                'exists' => false,
                'check_url' => route('election.diagnostics.removable-media.readiness'),
                'target_path' => $this->removableMediaRoot(),
            ];
        }

        $report = $this->storage->readJson('diagnostics/removable-media-readiness.json');

        return [
            'exists' => true,
            'check_url' => route('election.diagnostics.removable-media.readiness'),
            'artifact' => basename($path),
            'checked_at' => $report['checked_at'] ?? null,
            'configured' => $report['configured'] ?? false,
            'ready' => $report['ready'] ?? false,
            'status' => $report['status'] ?? (($report['ready'] ?? false) ? 'ready' : 'not_ready'),
            'status_label' => $report['status_label'] ?? (($report['ready'] ?? false) ? 'Ready' : 'Not Ready'),
            'target_path' => $report['target_path'] ?? $this->removableMediaRoot(),
            'readiness_hash' => $report['readiness_hash'] ?? null,
            'checks' => $report['checks'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function evidenceBundleArchiveSummary(): array
    {
        $path = $this->storage->path('diagnostics/evidence-bundle-archive.json');

        if (! $this->files->exists($path)) {
            return [
                'exists' => false,
                'build_url' => route('election.diagnostics.evidence-bundle-archive.build'),
                'download_url' => route('election.diagnostics.evidence-bundle-archive.download'),
            ];
        }

        $report = $this->storage->readJson('diagnostics/evidence-bundle-archive.json');

        return [
            'exists' => true,
            'build_url' => route('election.diagnostics.evidence-bundle-archive.build'),
            'download_url' => route('election.diagnostics.evidence-bundle-archive.download'),
            'archive_id' => $report['archive_id'] ?? null,
            'archive_artifact' => $report['archive_artifact'] ?? null,
            'archive_bytes' => $report['archive_bytes'] ?? 0,
            'archive_sha256' => $report['archive_sha256'] ?? null,
            'built_at' => $report['built_at'] ?? null,
            'entry_count' => $report['entry_count'] ?? 0,
            'manifest_hash' => $report['manifest_hash'] ?? null,
            'archive_report_hash' => $report['archive_report_hash'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function evidenceBundleArchiveVerificationSummary(): array
    {
        $path = $this->storage->path('diagnostics/evidence-bundle-archive-verification.json');

        if (! $this->files->exists($path)) {
            return [
                'exists' => false,
                'verify_url' => route('election.diagnostics.evidence-bundle-archive.verify'),
                'upload_verify_url' => route('election.diagnostics.evidence-bundle-archive.upload-verify'),
            ];
        }

        $report = $this->storage->readJson('diagnostics/evidence-bundle-archive-verification.json');

        return [
            'exists' => true,
            'verify_url' => route('election.diagnostics.evidence-bundle-archive.verify'),
            'upload_verify_url' => route('election.diagnostics.evidence-bundle-archive.upload-verify'),
            'archive_id' => $report['archive_id'] ?? null,
            'archive_path' => $report['archive_path'] ?? null,
            'archive_source' => $report['archive_source'] ?? 'local-download',
            'archive_sha256' => $report['archive_sha256'] ?? null,
            'checked_files' => $report['checked_files'] ?? 0,
            'mismatch_count' => count($report['mismatches'] ?? []),
            'mismatches' => $report['mismatches'] ?? [],
            'passed' => $report['passed'] ?? false,
            'uploaded_archive_artifact' => $report['uploaded_archive_artifact'] ?? null,
            'uploaded_archive_original_name' => $report['uploaded_archive_original_name'] ?? null,
            'uploaded_archive_sha256' => $report['uploaded_archive_sha256'] ?? null,
            'uploaded_at' => $report['uploaded_at'] ?? null,
            'verification_hash' => $report['verification_hash'] ?? null,
            'verified_at' => $report['verified_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function evidenceExportVerificationSummary(): array
    {
        $path = $this->storage->path('diagnostics/evidence-export-verification.json');

        if (! $this->files->exists($path)) {
            return [
                'exists' => false,
                'verify_url' => route('election.diagnostics.removable-media.verify'),
            ];
        }

        $report = $this->storage->readJson('diagnostics/evidence-export-verification.json');

        return [
            'exists' => true,
            'verify_url' => route('election.diagnostics.removable-media.verify'),
            'artifact' => basename($path),
            'verified_at' => $report['verified_at'] ?? null,
            'export_id' => $report['export_id'] ?? null,
            'export_path' => $report['export_path'] ?? null,
            'passed' => $report['passed'] ?? false,
            'checked_files' => $report['checked_files'] ?? 0,
            'verification_hash' => $report['verification_hash'] ?? null,
            'mismatch_count' => count($report['mismatches'] ?? []),
            'mismatches' => $report['mismatches'] ?? [],
        ];
    }

    private function removableMediaRoot(): string
    {
        $configuredPath = trim((string) config('election.removable_media.path', ''));

        if ($configuredPath !== '') {
            return rtrim($configuredPath, '/');
        }

        return $this->storage->path('removable-media');
    }

    /**
     * @return array<string, array{directory: string, files: array<int, array<string, mixed>>}>
     */
    private function manifestCategories(): array
    {
        $directories = [
            'start_here' => '00-start-here',
            'precinct_preparation' => '01-precinct-preparation',
            'device_certification' => '02-device-certification',
            'polls_opening' => '03-polls-opening',
            'voting_and_printing' => '04-voting-and-printing',
            'polls_closing' => '05-polls-closing',
            'counting_and_tally' => '06-counting-and-tally',
            'election_return' => '07-election-return',
            'precinct_closing' => '08-precinct-closing',
            'journal' => '10-journal',
        ];

        return collect($directories)
            ->mapWithKeys(fn (string $directory, string $key): array => [
                $key => [
                    'directory' => $directory,
                    'files' => $this->manifestFiles($directory),
                ],
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function manifestFiles(string $directory): array
    {
        $root = $this->storage->runPath($directory);

        if (! $this->files->isDirectory($root)) {
            return [];
        }

        return collect($this->files->allFiles($root))
            ->map(function ($file) use ($directory, $root): array {
                $nested = trim(str_replace($root, '', dirname($file->getPathname())), '/');
                $artifactDirectory = $nested === '' ? $directory : $directory.'/'.$nested;

                return EvidenceArtifact::fromPath($artifactDirectory, $file->getPathname())->toManifestEntry();
            })
            ->values()
            ->all();
    }
}
