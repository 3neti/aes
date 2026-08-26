<?php

namespace App\Election\Tabulation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionOperationLock;
use App\Election\Support\ElectionStorage;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

final class DeviceTabulationLedger
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly ElectionOperationLock $lock,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function recordDepositedBallot(array $payload): array
    {
        return $this->lock->execute('device-tabulation-ledger', function () use ($payload): array {
            if ($this->storage->readJson('counting/vvdat-ledger-freeze.json') !== []) {
                throw new RuntimeException('The VVDAT ledger is frozen and cannot accept another deposited ballot.');
            }

            $payloadHash = (string) ($payload['payload_hash'] ?? '');
            $files = $this->storage->files('device-tabulation-ledger');

            if (collect($files)->contains(fn (string $path): bool => str_contains(basename($path), $payloadHash))) {
                throw new RuntimeException('This ballot already has a device tabulation record.');
            }

            $record = [
                'schema_version' => 'vvdat-ledger-record-1',
                'sequence' => count($files) + 1,
                'ballot_id' => $payload['ballot_id'],
                'payload_hash' => $payloadHash,
                'paper_ballot_serial' => $payload['paper_ballot_serial'] ?? null,
                'encrypted_selections' => Crypt::encryptString($this->json->encode($payload['selections'])),
                'recorded_at' => $this->clock->now()->toIso8601String(),
                'status' => 'sealed-for-tabulation',
            ];
            $record['record_hash'] = $this->json->hash($record);
            $record['artifact_path'] = $this->storage->writeJson(
                sprintf('device-tabulation-ledger/%06d-%s.json', $record['sequence'], $record['payload_hash']),
                $record,
            );

            $this->journal->record('vvdat.ballot_recorded', [
                'sequence' => $record['sequence'],
                'ballot_id' => $record['ballot_id'],
                'payload_hash' => $record['payload_hash'],
                'paper_ballot_serial' => $record['paper_ballot_serial'],
                'record_hash' => $record['record_hash'],
            ]);

            return $record;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function records(): array
    {
        return collect($this->storage->files('device-tabulation-ledger'))
            ->map(fn (string $path): array => $this->readRecord($path))
            ->sortBy('sequence')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recordsForTally(): array
    {
        return collect($this->records())
            ->map(function (array $record): array {
                $selections = json_decode(
                    Crypt::decryptString((string) $record['encrypted_selections']),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );

                return [
                    ...$record,
                    'selections' => $selections,
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPayloadHash(string $payloadHash): ?array
    {
        $record = collect($this->records())
            ->firstWhere('payload_hash', $payloadHash);

        return is_array($record) ? $record : null;
    }

    /**
     * @return array{recorded_ballots: int, record_hashes: array<int, string>}
     */
    public function summary(): array
    {
        $records = $this->records();

        return [
            'recorded_ballots' => count($records),
            'record_hashes' => collect($records)
                ->pluck('record_hash')
                ->filter(fn (mixed $hash): bool => is_string($hash))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readRecord(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read device tabulation record [{$path}].");
        }

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }
}
