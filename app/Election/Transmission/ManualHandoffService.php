<?php

namespace App\Election\Transmission;

use App\Election\Attestation\OfficerRegistry;
use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Validation\ValidationException;

final class ManualHandoffService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly LifecycleState $lifecycle,
        private readonly OfficerRegistry $officers,
        private readonly TransmissionService $transmission,
    ) {}

    /**
     * @param  array{officer_code: string, officer_pin: string, verification_note: string|null}  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function verifyOfficer(array $input): array
    {
        $stage = (string) $input['stage'];
        $this->assertHandoffStage($stage);

        $officer = $this->officers->verify($input['officer_code'], $input['officer_pin']);

        if ($officer === null) {
            throw ValidationException::withMessages([
                'officer_pin' => 'The officer code or PIN is invalid.',
            ]);
        }

        $transmission = $this->transmission->latestReport();
        $precinct = (string) ($this->storage->readJson('runtime/active-precinct.json')['precinct_id'] ?? '0421-A');

        $record = [
            'schema_version' => 'manual-handoff-officer-verification-1',
            'verification_id' => 'officer-verification-'.$this->clock->now()->format('YmdHis'),
            'precinct_id' => $precinct,
            'recorded_at' => $this->clock->now()->toIso8601String(),
            'verification_stage' => $stage,
            'verification_type' => 'officer',
            'officer_code_hash' => hash('sha256', $input['officer_code']),
            'officer_name' => $officer['name'],
            'officer_role' => $officer['role'],
            'delivery_method' => 'manual',
            'transmission_id' => $transmission['transmission_id'] ?? null,
            'transmission_hash' => $transmission['transmission_hash'] ?? null,
            'verification_note' => trim((string) ($input['verification_note'] ?? '')),
            'status' => 'verified',
        ];

        $record['verification_hash'] = $this->json->hash($this->recordForHash($record));
        $record['artifact_path'] = $this->storage->writeJson('transmission/manual-handoff-officer-verification.json', $record);

        $this->journal->record('transmission.officer_verified', [
            'verification_id' => $record['verification_id'],
            'precinct_id' => $precinct,
            'officer_code_hash' => $record['officer_code_hash'],
            'verification_hash' => $record['verification_hash'],
            'verification_stage' => $record['verification_stage'],
            'transmission_id' => $record['transmission_id'],
        ]);

        return $record;
    }

    /**
     * @param  array{recipient: string, recipient_role: string, handoff_date: string, handoff_time: string, delivery_method: string, acknowledged: bool, acknowledgement_note: string|null}  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function verifyRecipient(array $input): array
    {
        $stage = (string) $input['stage'];
        $this->assertHandoffStage($stage);

        $officerVerification = $this->readOfficerVerification();

        if (! $officerVerification) {
            throw ValidationException::withMessages([
                'recipient' => 'Officer verification is required before recipient verification.',
            ]);
        }

        $transmission = $this->transmission->latestReport();
        $precinct = (string) ($this->storage->readJson('runtime/active-precinct.json')['precinct_id'] ?? '0421-A');
        $handoffAt = $input['handoff_date'].' '.$input['handoff_time'];

        $record = [
            'schema_version' => 'manual-handoff-recipient-verification-1',
            'verification_id' => 'recipient-verification-'.$this->clock->now()->format('YmdHis'),
            'precinct_id' => $precinct,
            'recorded_at' => $this->clock->now()->toIso8601String(),
            'verification_stage' => $stage,
            'verification_type' => 'recipient',
            'recipient' => trim($input['recipient']),
            'recipient_role' => trim($input['recipient_role']),
            'recipient_handoff_at' => $handoffAt,
            'delivery_method' => $input['delivery_method'],
            'acknowledged' => (bool) $input['acknowledged'],
            'acknowledgement_note' => trim((string) ($input['acknowledgement_note'] ?? '')),
            'officer_verification_id' => $officerVerification['verification_id'],
            'transmission_id' => $transmission['transmission_id'] ?? null,
            'transmission_hash' => $transmission['transmission_hash'] ?? null,
            'status' => 'accepted',
        ];

        $record['verification_hash'] = $this->json->hash($this->recordForHash($record));
        $record['artifact_path'] = $this->storage->writeJson('transmission/manual-handoff-recipient-verification.json', $record);

        $this->journal->record('transmission.recipient_verified', [
            'verification_id' => $record['verification_id'],
            'precinct_id' => $precinct,
            'recipient' => $record['recipient'],
            'recipient_role' => $record['recipient_role'],
            'verification_hash' => $record['verification_hash'],
            'delivery_method' => $record['delivery_method'],
            'acknowledged' => $record['acknowledged'],
        ]);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public function officerVerificationSummary(): array
    {
        $path = $this->storage->path('transmission/manual-handoff-officer-verification.json');

        if (! file_exists($path)) {
            return [
                'verified' => false,
                'verification_id' => null,
            ];
        }

        $record = $this->storage->readJson('transmission/manual-handoff-officer-verification.json');

        return [
            'verified' => true,
            'verification_id' => $record['verification_id'] ?? null,
            'officer_name' => $record['officer_name'] ?? null,
            'officer_role' => $record['officer_role'] ?? null,
            'status' => $record['status'] ?? null,
            'recorded_at' => $record['recorded_at'] ?? null,
            'verification_hash' => $record['verification_hash'] ?? null,
            'artifact' => basename($path),
            'artifact_path' => str_replace(storage_path('app/election/').'/', '', $path),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recipientVerificationSummary(): array
    {
        $path = $this->storage->path('transmission/manual-handoff-recipient-verification.json');

        if (! file_exists($path)) {
            return [
                'verified' => false,
                'verification_id' => null,
            ];
        }

        $record = $this->storage->readJson('transmission/manual-handoff-recipient-verification.json');

        return [
            'verified' => true,
            'verification_id' => $record['verification_id'] ?? null,
            'recipient' => $record['recipient'] ?? null,
            'recipient_role' => $record['recipient_role'] ?? null,
            'delivery_method' => $record['delivery_method'] ?? null,
            'acknowledged' => (bool) ($record['acknowledged'] ?? false),
            'recipient_handoff_at' => $record['recipient_handoff_at'] ?? null,
            'status' => $record['status'] ?? null,
            'verification_hash' => $record['verification_hash'] ?? null,
            'artifact' => basename($path),
            'artifact_path' => str_replace(storage_path('app/election/').'/', '', $path),
        ];
    }

    private function readOfficerVerification(): ?array
    {
        $path = $this->storage->path('transmission/manual-handoff-officer-verification.json');

        if (! file_exists($path)) {
            return null;
        }

        return $this->storage->readJson('transmission/manual-handoff-officer-verification.json');
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
        ];
    }

    private function assertHandoffStage(string $stage): void
    {
        if ($stage !== $this->lifecycle->current()) {
            throw ValidationException::withMessages([
                'stage' => 'The ceremony stage has changed. Reload and continue from the current stage.',
            ]);
        }

        if (! in_array($stage, [Lifecycle::Transmission, Lifecycle::FinalBackup], true)) {
            throw ValidationException::withMessages([
                'stage' => 'Manual handoff is only allowed during the transmission phase.',
            ]);
        }
    }
}
