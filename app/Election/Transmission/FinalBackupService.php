<?php

namespace App\Election\Transmission;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Diagnostics\DiagnosticsService;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Support\SimplePdf;
use Illuminate\Validation\ValidationException;

final class FinalBackupService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly LifecycleState $lifecycle,
        private readonly TransmissionService $transmission,
        private readonly DeliveryPackageService $package,
        private readonly DiagnosticsService $diagnostics,
        private readonly SimplePdf $pdf,
    ) {}

    public function perform(array $input): array
    {
        $stage = (string) ($input['stage'] ?? '');

        if ($stage !== $this->lifecycle->current()) {
            throw ValidationException::withMessages([
                'stage' => 'The ceremony stage has changed. Reload and continue from the current stage.',
            ]);
        }

        if ($stage !== Lifecycle::FinalBackup) {
            throw ValidationException::withMessages([
                'stage' => 'Final backup can only be recorded after delivery receipt confirms handoff.',
            ]);
        }

        $transmission = $this->transmission->latestReport();
        if ($transmission === []) {
            throw ValidationException::withMessages([
                'transmission' => 'Transmission report missing. Generate transmission before final backup.',
            ]);
        }

        $package = $this->package->latest();
        if ($package === []) {
            throw ValidationException::withMessages([
                'delivery_package' => 'Delivery package missing. Prepare package before final backup.',
            ]);
        }

        $receipt = $this->storage->readJson('transmission/delivery-receipt.json');
        if ($receipt === []) {
            throw ValidationException::withMessages([
                'delivery_receipt' => 'Delivery receipt missing. Generate delivery receipt before final backup.',
            ]);
        }

        $manifest = $this->diagnostics->writeEvidenceManifest();
        $configuration = $this->storage->readJson('runtime/active-precinct.json');

        $record = [
            'schema_version' => 'final-backup-1',
            'backup_id' => 'final-backup-'.$this->clock->now()->format('YmdHis').'-'.substr((string) ($transmission['transmission_hash'] ?? 'no-hash'), 0, 8),
            'precinct_id' => (string) ($configuration['precinct_id'] ?? '0421-A'),
            'recorded_at' => $this->clock->now()->toIso8601String(),
            'backup_stage' => $stage,
            'backup_type' => $input['backup_type'] ?? 'local-storage',
            'backup_media' => $input['backup_media'] ?? 'local-storage',
            'backup_note' => trim((string) ($input['backup_note'] ?? 'Final backup completed on appliance.')),
            'delivery_package_id' => $package['package_id'] ?? null,
            'delivery_package_hash' => $package['delivery_package_hash'] ?? null,
            'transmission_id' => $transmission['transmission_id'] ?? null,
            'transmission_hash' => $transmission['transmission_hash'] ?? null,
            'delivery_receipt_id' => $receipt['delivery_receipt_id'] ?? null,
            'delivery_receipt_hash' => $receipt['delivery_receipt_hash'] ?? null,
            'evidence_manifest_hash' => $manifest['manifest_hash'] ?? null,
            'evidence_manifest_path' => $manifest['artifact_path'] ?? null,
            'backup_completed' => true,
        ];

        $record['final_backup_hash'] = $this->json->hash($this->recordForHash($record));
        $record['artifact_path'] = $this->storage->writeJson('transmission/final-backup-report.json', $record);
        $this->storage->writeText('transmission/final-backup-report.txt', $this->renderText($record));
        $this->storage->writeText('transmission/final-backup-report.pdf', $this->pdf->render('Final Backup Report', $this->renderPdfLines($record)));

        $this->journal->record('transmission.final_backup', [
            'backup_id' => $record['backup_id'],
            'precinct_id' => $record['precinct_id'],
            'transmission_id' => $record['transmission_id'],
            'delivery_package_id' => $record['delivery_package_id'],
            'final_backup_hash' => $record['final_backup_hash'],
            'backup_media' => $record['backup_media'],
            'backup_type' => $record['backup_type'],
        ]);

        return $record;
    }

    public function latest(): array
    {
        return $this->storage->readJson('transmission/final-backup-report.json');
    }

    public function summary(): array
    {
        $path = $this->storage->path('transmission/final-backup-report.json');
        if (! file_exists($path)) {
            return [
                'exists' => false,
                'generate_url' => route('election.transmission.final-backup'),
            ];
        }

        $record = $this->latest();

        return [
            'exists' => true,
            'artifact' => basename($path),
            'artifact_path' => str_replace(storage_path('app/election/').'/', '', $path),
            'backup_id' => $record['backup_id'] ?? null,
            'backup_hash' => $record['final_backup_hash'] ?? null,
            'backup_media' => $record['backup_media'] ?? null,
            'backup_type' => $record['backup_type'] ?? null,
            'backup_stage' => $record['backup_stage'] ?? null,
            'recorded_at' => $record['recorded_at'] ?? null,
            'transmission_id' => $record['transmission_id'] ?? null,
            'evidence_manifest_path' => $record['evidence_manifest_path'] ?? null,
            'backup_completed' => (bool) ($record['backup_completed'] ?? false),
        ];
    }

    private function renderText(array $record): string
    {
        return "FINAL BACKUP REPORT\n".
            "Backup: {$record['backup_id']}\n".
            "Precinct: {$record['precinct_id']}\n".
            "Type: {$record['backup_type']}\n".
            "Media: {$record['backup_media']}\n".
            "Delivery Package ID: {$record['delivery_package_id']}\n".
            "Delivery Receipt ID: {$record['delivery_receipt_id']}\n".
            "Transmission ID: {$record['transmission_id']}\n".
            "Final Backup Hash: {$record['final_backup_hash']}\n".
            "Evidence Manifest: {$record['evidence_manifest_path']}\n";
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function renderPdfLines(array $record): array
    {
        return [
            'Final Backup',
            'Backup ID: '.$record['backup_id'],
            'Precinct: '.$record['precinct_id'],
            'Type: '.$record['backup_type'],
            'Media: '.$record['backup_media'],
            'Delivery Package: '.($record['delivery_package_id'] ?? ''),
            'Transmission ID: '.($record['transmission_id'] ?? ''),
            'Backup Hash: '.($record['final_backup_hash'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function recordForHash(array $record): array
    {
        return [
            ...$record,
            'artifact_path' => null,
            'text_path' => null,
            'pdf_path' => null,
        ];
    }
}
