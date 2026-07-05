<?php

namespace App\Election\Diagnostics;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;

final class DiagnosticsService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ActivityJournal $journal,
        private readonly CanonicalJson $json,
        private readonly ElectionClock $clock,
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
     * @return array<string, array{directory: string, files: array<int, array<string, mixed>>}>
     */
    private function manifestCategories(): array
    {
        $directories = [
            'registries' => 'registries',
            'packages' => 'packages',
            'runtime' => 'runtime',
            'journals' => 'journals',
            'ballots' => 'ballots',
            'print_jobs' => 'print-jobs',
            'accepted_counting_records' => 'counting/accepted',
            'rejected_counting_records' => 'counting/rejected',
            'returns' => 'returns',
            'certification' => 'certification',
            'attestations' => 'attestations',
            'attestation_signatures' => 'attestation-signatures',
            'scenarios' => 'scenarios',
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
        return collect($this->storage->files($directory))
            ->map(fn (string $path): array => [
                'file' => basename($path),
                'relative_path' => $directory.'/'.basename($path),
                'bytes' => filesize($path),
                'sha256' => hash_file('sha256', $path),
            ])
            ->values()
            ->all();
    }
}
