<?php

namespace App\Election\Custody;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Support\SimplePdf;
use App\Election\Transmission\TransmissionService;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class CustodyService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly LifecycleState $lifecycle,
        private readonly TransmissionService $transmission,
        private readonly SimplePdf $pdf,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function record(): array
    {
        if ($this->lifecycle->current() !== Lifecycle::FinalBackup) {
            throw ValidationException::withMessages([
                'stage' => 'Custody can only be recorded after final backup completes.',
            ]);
        }

        $transmission = $this->transmission->latestReport();

        if ($transmission === []) {
            throw new RuntimeException('Cannot record custody before transmission exists.');
        }

        $finalBackup = $this->storage->readJson('transmission/final-backup-report.json');
        if ($finalBackup === []) {
            throw ValidationException::withMessages([
                'stage' => 'Cannot record custody before final backup report is complete.',
            ]);
        }

        $deliveryPackage = $this->storage->readJson('transmission/delivery-package.json');
        if ($deliveryPackage === []) {
            throw ValidationException::withMessages([
                'delivery_package' => 'Delivery package is required before custody turnover.',
            ]);
        }

        $deliveryReceipt = $this->storage->readJson('transmission/delivery-receipt.json');
        if ($deliveryReceipt === []) {
            throw ValidationException::withMessages([
                'delivery_receipt' => 'Delivery receipt is required before custody turnover.',
            ]);
        }

        $recipientVerification = $this->storage->readJson('transmission/manual-handoff-recipient-verification.json');
        if ($recipientVerification === []) {
            throw ValidationException::withMessages([
                'recipient' => 'Recipient verification is required before custody turnover.',
            ]);
        }

        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $precinct = (string) ($configuration['precinct_id'] ?? '0421-A');

        $recordedAt = $this->clock->now()->toIso8601String();

        $record = [
            'schema_version' => 'custody-record-1',
            'custody_id' => 'custody-'.$this->clock->now()->format('YmdHis').'-'.substr($transmission['transmission_hash'], 0, 8),
            'precinct_id' => $precinct,
            'election_id' => $configuration['election_id'] ?? ($transmission['election_id'] ?? null),
            'mapping_hash' => $configuration['mapping_hash'] ?? ($transmission['mapping_hash'] ?? null),
            'recorded_at' => $recordedAt,
            'status' => 'sealed',
            'artifacts' => [
                [
                    'type' => 'election_return',
                    'path' => $this->returnsArtifactPath(),
                    'required' => true,
                ],
                [
                    'type' => 'transmission_report',
                    'path' => $this->transmissionArtifactPath(),
                    'required' => true,
                ],
                [
                    'type' => 'delivery_package',
                    'path' => 'transmission/delivery-package.json',
                    'required' => true,
                ],
                [
                    'type' => 'delivery_receipt',
                    'path' => 'transmission/delivery-receipt.json',
                    'required' => true,
                ],
                [
                    'type' => 'final_backup',
                    'path' => 'transmission/final-backup-report.json',
                    'required' => true,
                ],
            ],
            'seals' => collect($this->storage->readJson('runtime/precinct-setup.json')['inventory']['seal_numbers'] ?? [$this->nextSealNumber($precinct)])
                ->map(fn (string $seal): array => [
                    'seal_number' => $seal,
                    'applied_by' => 'Simulation Officer',
                    'applied_at' => $this->clock->now()->toIso8601String(),
                ])->values()->all(),
            'recipients' => [
                [
                    'type' => 'election-board',
                    'name' => (string) ($recipientVerification['recipient'] ?? 'Election Board'),
                    'role' => (string) ($recipientVerification['recipient_role'] ?? 'Election Board'),
                    'handoff_time' => (string) ($recipientVerification['recipient_handoff_at'] ?? $recordedAt),
                    'delivery_method' => (string) ($recipientVerification['delivery_method'] ?? 'manual'),
                ],
            ],
            'transmission_id' => $transmission['transmission_id'] ?? null,
            'transmission_status' => $transmission['passed'] ? 'transmission-recorded' : 'transmission-deficient',
            'delivery_receipt_id' => $deliveryReceipt['delivery_receipt_id'] ?? null,
            'final_backup_id' => $finalBackup['backup_id'] ?? null,
            'delivery_package_id' => $deliveryPackage['package_id'] ?? null,
        ];

        $record['custody_hash'] = $this->json->hash($record);

        $record['artifact_path'] = $this->storage->writeJson('custody/custody-record.json', $record);
        $this->storage->writeText('custody/custody-record.txt', $this->renderText($record));
        $this->storage->writeText('custody/custody-record.pdf', $this->pdf->render('Custody Record', $this->renderPdfLines($record)));

        $turnover = $this->writeTurnoverReport($record, $finalBackup, $deliveryPackage, $deliveryReceipt);

        $this->journal->record('custody.recorded', [
            'custody_id' => $record['custody_id'],
            'precinct_id' => $precinct,
            'custody_hash' => $record['custody_hash'],
            'status' => $record['status'],
            'custody_turnover_id' => $turnover['custody_turnover_id'],
            'custody_turnover_hash' => $turnover['custody_turnover_hash'],
        ]);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public function latestTurnoverReport(): array
    {
        return $this->storage->readJson('custody/custody-turnover-report.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function turnoverSummary(): array
    {
        $path = $this->storage->path('custody/custody-turnover-report.json');

        if (! file_exists($path)) {
            return [
                'exists' => false,
                'generate_url' => route('election.transmission.custody'),
            ];
        }

        $report = $this->latestTurnoverReport();

        return [
            'exists' => true,
            'artifact' => basename($path),
            'artifact_path' => str_replace(storage_path('app/election/').'/', '', $path),
            'custody_turnover_id' => $report['custody_turnover_id'] ?? null,
            'custody_turnover_hash' => $report['custody_turnover_hash'] ?? null,
            'custody_id' => $report['custody_id'] ?? null,
            'turnover_stage' => $report['turnover_stage'] ?? null,
            'recipient' => $report['recipient'] ?? null,
            'recipient_role' => $report['recipient_role'] ?? null,
            'acknowledged' => (bool) ($report['recipient_acknowledged'] ?? false),
            'recorded_at' => $report['recorded_at'] ?? null,
            'artifact_count' => $report['artifact_count'] ?? 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function latestRecord(): array
    {
        return $this->storage->readJson('custody/custody-record.json');
    }

    private function nextSealNumber(string $precinct): string
    {
        return sprintf('SEAL-%s-%s', $precinct, $this->clock->now()->format('His'));
    }

    private function returnsArtifactPath(): string
    {
        $precinct = (string) ($this->storage->readJson('runtime/active-precinct.json')['precinct_id'] ?? '0421-A');

        return 'returns/'.$precinct.'-return.json';
    }

    private function transmissionArtifactPath(): string
    {
        return 'transmission/transmission-report.json';
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $finalBackup
     * @param  array<string, mixed>  $package
     * @param  array<string, mixed>  $receipt
     * @return array<string, mixed>
     */
    private function writeTurnoverReport(array $record, array $finalBackup, array $package, array $receipt): array
    {
        $precinct = (string) ($record['precinct_id'] ?? '0421-A');
        $recipientVerification = $this->storage->readJson('transmission/manual-handoff-recipient-verification.json');
        $turnover = [
            'schema_version' => 'custody-turnover-report-1',
            'custody_turnover_id' => 'custody-turnover-'.$this->clock->now()->format('YmdHis').'-'.substr($record['custody_hash'], 0, 8),
            'turnover_stage' => $this->lifecycle->current(),
            'precinct_id' => $precinct,
            'recorded_at' => $this->clock->now()->toIso8601String(),
            'custody_id' => $record['custody_id'] ?? null,
            'custody_hash' => $record['custody_hash'] ?? null,
            'recipient' => (string) ($record['recipients'][0]['name'] ?? 'Election Board'),
            'recipient_role' => (string) ($record['recipients'][0]['role'] ?? 'Election Board'),
            'delivery_method' => (string) ($record['recipients'][0]['delivery_method'] ?? 'manual'),
            'recipient_acknowledged' => (bool) ($recipientVerification['acknowledged'] ?? false),
            'transfer_time' => (string) ($record['recipients'][0]['handoff_time'] ?? $record['recorded_at']),
            'delivery_package_id' => $package['package_id'] ?? null,
            'delivery_receipt_id' => $receipt['delivery_receipt_id'] ?? null,
            'delivery_receipt_hash' => $receipt['delivery_receipt_hash'] ?? null,
            'transmission_id' => $record['transmission_id'] ?? null,
            'transmission_hash' => $this->storage->readJson('transmission/transmission-report.json')['transmission_hash'] ?? null,
            'final_backup_id' => $finalBackup['backup_id'] ?? null,
            'final_backup_hash' => $finalBackup['final_backup_hash'] ?? null,
            'artifact_count' => 5,
            'artifacts' => [
                [
                    'type' => 'custody_record',
                    'path' => 'custody/custody-record.json',
                ],
                [
                    'type' => 'delivery_receipt',
                    'path' => 'transmission/delivery-receipt.json',
                ],
                [
                    'type' => 'delivery_package',
                    'path' => 'transmission/delivery-package.json',
                ],
                [
                    'type' => 'transmission_report',
                    'path' => 'transmission/transmission-report.json',
                ],
                [
                    'type' => 'final_backup_report',
                    'path' => 'transmission/final-backup-report.json',
                ],
            ],
        ];

        $turnover['custody_turnover_hash'] = $this->json->hash($this->recordForHash($turnover));
        $turnover['artifact_path'] = $this->storage->writeJson('custody/custody-turnover-report.json', $turnover);
        $turnover['text_path'] = $this->storage->writeText('custody/custody-turnover-report.txt', $this->renderTurnoverText($turnover));
        $turnover['pdf_path'] = $this->storage->writeText('custody/custody-turnover-report.pdf', $this->pdf->render(
            'Custody Turnover Report',
            $this->renderTurnoverPdfLines($turnover),
        ));

        return $turnover;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function renderText(array $record): string
    {
        $text = "CUSTODY RECORD\n";
        $text .= "Custody: {$record['custody_id']}\n";
        $text .= "Precinct: {$record['precinct_id']}\n";
        $text .= "Status: {$record['status']}\n";
        $text .= "Custody Hash: {$record['custody_hash']}\n\n";

        foreach ($record['seals'] as $seal) {
            $text .= sprintf(
                "Seal: %s\nApplied by: %s\nApplied at: %s\n\n",
                $seal['seal_number'],
                $seal['applied_by'],
                $seal['applied_at'],
            );
        }

        foreach ($record['artifacts'] as $artifact) {
            $text .= "Included artifact: {$artifact['type']} ({$artifact['path']})\n";
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, string>
     */
    private function renderPdfLines(array $record): array
    {
        $lines = [
            'Custody ID: '.$record['custody_id'],
            'Precinct: '.$record['precinct_id'],
            'Status: '.$record['status'],
            'Transmission ID: '.($record['transmission_id'] ?? 'none'),
            'Custody Hash: '.$record['custody_hash'],
            '',
            'Seals:',
        ];

        foreach ($record['seals'] as $seal) {
            $lines[] = '- '.$seal['seal_number'].' ('.$seal['applied_by'].')';
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderTurnoverText(array $report): string
    {
        return implode(PHP_EOL, [
            'CUSTODY TURNOVER REPORT',
            'Turnover ID: '.$report['custody_turnover_id'],
            'Precinct: '.$report['precinct_id'],
            'Custody Record ID: '.$report['custody_id'],
            'Delivery Receipt ID: '.$report['delivery_receipt_id'],
            'Delivery Package ID: '.$report['delivery_package_id'],
            'Recipient: '.($report['recipient'] ?? 'Election Board'),
            'Recipient Role: '.($report['recipient_role'] ?? 'Election Board'),
            'Delivery Method: '.($report['delivery_method'] ?? 'manual'),
            'Recipient Acknowledged: '.($report['recipient_acknowledged'] ? 'yes' : 'no'),
            'Final Backup ID: '.($report['final_backup_id'] ?? ''),
            'Artifacts Included: '.(string) $report['artifact_count'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, string>
     */
    private function renderTurnoverPdfLines(array $report): array
    {
        return [
            'Custody Turnover',
            'Custody Turnover ID: '.$report['custody_turnover_id'],
            'Precinct: '.$report['precinct_id'],
            'Custody Record: '.($report['custody_id'] ?? ''),
            'Recipient: '.($report['recipient'] ?? ''),
            'Acknowledged: '.($report['recipient_acknowledged'] ? 'yes' : 'no'),
            'Final Backup: '.($report['final_backup_id'] ?? ''),
            'Recorded At: '.($report['recorded_at'] ?? ''),
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
