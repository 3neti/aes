<?php

namespace App\Election\Diagnostics;

use App\Election\Core\ActivityJournal;
use App\Election\Support\ElectionStorage;

final class DiagnosticsService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ActivityJournal $journal,
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
            'printer' => config('election.devices.printer.adapter', 'simulated'),
            'scanner' => config('election.devices.scanner.adapter', 'simulated'),
            'device_certification' => $this->storage->readJson('certification/device-certification-report.json'),
        ];
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
}
