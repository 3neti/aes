<?php

namespace App\Election\Voting;

use App\Election\Core\ActivityJournal;
use App\Election\Counting\CountingService;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionOperationLock;
use App\Election\Support\ElectionStorage;
use App\Election\Tabulation\DeviceTabulationLedger;
use App\Election\Tabulation\TabulationProfileResolver;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

final class SealedBallotBox
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly PrivateBallotRelease $releases,
        private readonly PaperBallotLedger $paperBallots,
        private readonly ActivityJournal $journal,
        private readonly ElectionOperationLock $lock,
        private readonly TabulationProfileResolver $tabulation,
        private readonly DeviceTabulationLedger $deviceLedger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function deposit(string $releaseId): array
    {
        $payload = $this->releases->payloadForDeposit($releaseId);

        $record = $this->recordDeposit($payload);
        $this->releases->markDeposited($releaseId);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function depositPrintedPayload(array $payload): array
    {
        $job = $this->storage->readJson("print-jobs/{$payload['ballot_id']}.json");

        if (! in_array($job['status'] ?? null, ['printed', 'submitted'], true)) {
            throw new RuntimeException('The paper ballot must have a successful print job before it can be deposited.');
        }

        return $this->recordDeposit($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function recordDeposit(array $payload): array
    {
        return $this->lock->execute(
            'sealed-ballot-box',
            fn (): array => $this->recordDepositWithinLock($payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function recordDepositWithinLock(array $payload): array
    {
        $path = "counting/sealed/{$payload['payload_hash']}.json";

        if ($this->storage->readJson($path) !== []) {
            throw new RuntimeException('This paper ballot has already been deposited.');
        }

        $record = [
            'schema_version' => 'sealed-ballot-record-1',
            'ballot_id' => $payload['ballot_id'],
            'payload_hash' => $payload['payload_hash'],
            'paper_ballot_serial' => $payload['paper_ballot_serial'] ?? null,
            'encrypted_payload' => Crypt::encryptString($payload['qr_payload']),
            'status' => 'sealed',
            'deposited_at' => $this->clock->now()->toIso8601String(),
            'artifact_path' => $this->storage->path($path),
        ];
        $this->storage->writeJson($path, $record);
        $this->paperBallots->recordDeposited($payload['payload_hash']);
        $deviceRecord = null;

        if (! $this->tabulation->current()->routineScanningEnabled()) {
            $deviceRecord = $this->deviceLedger->recordDepositedBallot($payload);
        }

        $this->journal->record('ballot.deposited_sealed', [
            'ballot_id' => $payload['ballot_id'],
            'payload_hash' => $payload['payload_hash'],
            'paper_ballot_serial' => $payload['paper_ballot_serial'] ?? null,
            'device_tabulation_recorded' => $deviceRecord !== null,
        ]);

        return [
            ...collect($record)->except('encrypted_payload')->all(),
            'device_tabulation_recorded' => $deviceRecord !== null,
            'device_tabulation_sequence' => $deviceRecord['sequence'] ?? null,
        ];
    }

    /**
     * @return array{opened: int, rejected: int}
     */
    public function openForCounting(CountingService $counting): array
    {
        if (! $this->tabulation->current()->routineScanningEnabled()) {
            $this->journal->record('ballot_box.retained_for_paper_audit', [
                'deposited_ballots' => count($this->storage->files('counting/sealed')),
                'device_tabulation_records' => $this->deviceLedger->summary()['recorded_ballots'],
            ]);

            return ['opened' => 0, 'rejected' => 0];
        }

        $opened = 0;
        $rejected = 0;

        foreach ($this->storage->files('counting/sealed') as $path) {
            $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            if (($record['status'] ?? null) !== 'sealed') {
                continue;
            }

            $counted = $counting->accept(
                Crypt::decryptString($record['encrypted_payload']),
                recordDeposit: false,
            );
            $record['status'] = $counted['status'] === 'accepted' ? 'opened' : 'rejected';
            $record['opened_at'] = $this->clock->now()->toIso8601String();
            $this->storage->writeJson("counting/sealed/{$record['payload_hash']}.json", $record);

            $counted['status'] === 'accepted' ? $opened++ : $rejected++;
        }

        $this->journal->record('ballot_box.opened', [
            'opened_ballots' => $opened,
            'rejected_ballots' => $rejected,
        ]);

        return ['opened' => $opened, 'rejected' => $rejected];
    }

    /**
     * @return array{deposited_ballots: int}
     */
    public function operationalSummary(): array
    {
        return [
            'deposited_ballots' => count($this->storage->files('counting/sealed')),
        ];
    }
}
