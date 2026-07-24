<?php

namespace App\Election\Voting;

use App\Election\Core\ActivityJournal;
use App\Election\Counting\CountingService;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function deposit(string $releaseId): array
    {
        $payload = $this->releases->payloadForDeposit($releaseId);
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
        ];
        $this->storage->writeJson($path, $record);
        $this->paperBallots->recordDeposited($payload['payload_hash']);
        $this->releases->markDeposited($releaseId);
        $this->journal->record('ballot.deposited_sealed', [
            'ballot_id' => $payload['ballot_id'],
            'payload_hash' => $payload['payload_hash'],
            'paper_ballot_serial' => $payload['paper_ballot_serial'] ?? null,
        ]);

        return collect($record)->except('encrypted_payload')->all();
    }

    /**
     * @return array{opened: int, rejected: int}
     */
    public function openForCounting(CountingService $counting): array
    {
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
