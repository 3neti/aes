<?php

namespace App\Election\Transmission;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\CeremonyActions;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Support\SimplePdf;
use Illuminate\Validation\ValidationException;

final class DeliveryReceiptService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly LifecycleState $lifecycle,
        private readonly DeliveryPackageService $package,
        private readonly TransmissionService $transmission,
        private readonly ManualHandoffService $handoff,
        private readonly CeremonyActions $ceremonies,
        private readonly SimplePdf $pdf,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function prepare(array $input): array
    {
        $stage = (string) ($input['stage'] ?? '');
        $this->assertReceiptStage($stage);

        $officerVerification = $this->handoff->officerVerificationSummary();
        $recipientVerification = $this->handoff->recipientVerificationSummary();

        if (! ($recipientVerification['verified'] ?? false)) {
            throw ValidationException::withMessages([
                'recipient' => 'Delivery Receipt requires a recipient verification.',
            ]);
        }

        $transmission = $this->transmission->latestReport();
        if ($transmission === []) {
            throw ValidationException::withMessages([
                'transmission' => 'Transmission report missing. Generate transmission report before creating receipt.',
            ]);
        }

        $package = $this->package->latest();
        if ($package === []) {
            throw ValidationException::withMessages([
                'delivery_package' => 'Delivery package missing. Prepare package before creating receipt.',
            ]);
        }

        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $precinct = (string) ($configuration['precinct_id'] ?? '0421-A');

        $record = [
            'schema_version' => 'delivery-receipt-1',
            'delivery_receipt_id' => 'delivery-receipt-'.$this->clock->now()->format('YmdHis').'-'.substr((string) ($transmission['transmission_hash'] ?? 'no-hash'), 0, 8),
            'precinct_id' => $precinct,
            'recorded_at' => $this->clock->now()->toIso8601String(),
            'receipt_stage' => $stage,
            'delivery_driver' => $recipientVerification['delivery_method'] ?? 'manual',
            'package_id' => $package['package_id'] ?? null,
            'package_hash' => $package['delivery_package_hash'] ?? null,
            'transmission_id' => $transmission['transmission_id'] ?? null,
            'transmission_hash' => $transmission['transmission_hash'] ?? null,
            'officer_verification_id' => $officerVerification['verification_id'] ?? null,
            'recipient' => $recipientVerification['recipient'] ?? null,
            'recipient_role' => $recipientVerification['recipient_role'] ?? null,
            'recipient_handoff_at' => $recipientVerification['recipient_handoff_at'] ?? null,
            'acknowledged' => (bool) ($recipientVerification['acknowledged'] ?? false),
            'status' => 'accepted',
            'delivery_note' => trim((string) ($input['delivery_note'] ?? 'Manual handoff completed.')),
            'custody_recorded' => false,
        ];

        $record['delivery_receipt_hash'] = $this->json->hash($this->recordForHash($record));
        $record['artifact_path'] = $this->storage->writeJson('transmission/delivery-receipt.json', $record);
        $this->storage->writeText('transmission/delivery-receipt.txt', $this->renderText($record));
        $this->storage->writeText('transmission/delivery-receipt.pdf', $this->pdf->render('Delivery Receipt', $this->renderPdfLines($record)));

        $this->ceremonies->completeTransmission();

        $journalPayload = [
            'delivery_receipt_id' => $record['delivery_receipt_id'],
            'precinct_id' => $precinct,
            'package_id' => $package['package_id'] ?? null,
            'transmission_id' => $transmission['transmission_id'] ?? null,
            'recipient' => $record['recipient'],
            'delivery_driver' => $record['delivery_driver'],
            'delivery_receipt_hash' => $record['delivery_receipt_hash'],
        ];

        $journalPayload['recipient_acknowledged'] = $record['acknowledged'];
        $this->journal->record('transmission.delivery_receipt_prepared', $journalPayload);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public function latest(): array
    {
        return $this->storage->readJson('transmission/delivery-receipt.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $path = $this->storage->path('transmission/delivery-receipt.json');

        if (! file_exists($path)) {
            return [
                'exists' => false,
                'generate_url' => route('election.transmission.receipt'),
            ];
        }

        $record = $this->latest();

        return [
            'exists' => true,
            'artifact' => basename($path),
            'artifact_path' => str_replace(storage_path('app/election/').'/', '', $path),
            'delivery_receipt_id' => $record['delivery_receipt_id'] ?? null,
            'delivery_receipt_hash' => $record['delivery_receipt_hash'] ?? null,
            'status' => $record['status'] ?? null,
            'recipient' => $record['recipient'] ?? null,
            'recipient_role' => $record['recipient_role'] ?? null,
            'delivery_driver' => $record['delivery_driver'] ?? null,
            'delivery_stage' => $record['receipt_stage'] ?? null,
            'acknowledged' => (bool) ($record['acknowledged'] ?? false),
            'recorded_at' => $record['recorded_at'] ?? null,
            'custody_recorded' => (bool) ($record['custody_recorded'] ?? false),
        ];
    }

    private function assertReceiptStage(string $stage): void
    {
        if ($stage !== $this->lifecycle->current()) {
            throw ValidationException::withMessages([
                'stage' => 'The ceremony stage has changed. Reload and continue from the current stage.',
            ]);
        }

        if ($stage !== Lifecycle::Transmission) {
            throw ValidationException::withMessages([
                'stage' => 'Delivery Receipt can only be generated during transmission.',
            ]);
        }
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
            'pdf_path' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function renderText(array $record): string
    {
        $text = "DELIVERY RECEIPT\n";
        $text .= "Receipt: {$record['delivery_receipt_id']}\n";
        $text .= "Precinct: {$record['precinct_id']}\n";
        $text .= "Recipient: {$record['recipient']} ({$record['recipient_role']})\n";
        $text .= "Delivery Driver: {$record['delivery_driver']}\n";
        $text .= 'Transfer Acknowledged: '.($record['acknowledged'] ? 'yes' : 'no')."\n";
        $text .= "Transmission ID: {$record['transmission_id']}\n";
        $text .= "Transmission Hash: {$record['transmission_hash']}\n";
        $text .= "Package ID: {$record['package_id']}\n";
        $text .= "Receipt Hash: {$record['delivery_receipt_hash']}\n";

        return $text;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, string>
     */
    private function renderPdfLines(array $record): array
    {
        return [
            'Delivery Receipt',
            'Receipt ID: '.$record['delivery_receipt_id'],
            'Precinct: '.$record['precinct_id'],
            'Recipient: '.$record['recipient'].' ('.$record['recipient_role'].')',
            'Status: '.$record['status'],
            'Delivery Method: '.$record['delivery_driver'],
            'Acknowledged: '.($record['acknowledged'] ? 'yes' : 'no'),
            'Recorded At: '.$record['recorded_at'],
        ];
    }
}
