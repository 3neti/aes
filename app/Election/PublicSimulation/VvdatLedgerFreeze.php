<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Tabulation\DeviceTabulationLedger;
use RuntimeException;

final class VvdatLedgerFreeze
{
    public function __construct(
        private readonly DeviceTabulationLedger $ledger,
        private readonly ElectionStorage $storage,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly ElectionClock $clock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function freeze(): array
    {
        $existing = $this->storage->readJson('counting/vvdat-ledger-freeze.json');

        if ($existing !== []) {
            $this->assertValid($existing);

            return $existing;
        }

        $records = $this->ledger->records();
        $freeze = [
            'schema_version' => 'public-simulation-vvdat-ledger-freeze-1',
            'frozen_at' => $this->clock->now()->toIso8601String(),
            'record_count' => count($records),
            'record_hashes' => collect($records)->pluck('record_hash')->values()->all(),
        ];
        $freeze['ledger_root'] = $this->json->hash([
            'record_count' => $freeze['record_count'],
            'record_hashes' => $freeze['record_hashes'],
        ]);
        $freeze['freeze_hash'] = $this->json->hash($freeze);
        $freeze['artifact_path'] = $this->storage->writeJson('counting/vvdat-ledger-freeze.json', $freeze);

        $this->journal->record('vvdat.ledger_frozen', [
            'record_count' => $freeze['record_count'],
            'ledger_root' => $freeze['ledger_root'],
            'freeze_hash' => $freeze['freeze_hash'],
        ]);

        return $freeze;
    }

    /**
     * @return array{passed: bool, record_count: int, ledger_root: string|null, errors: array<int, string>}
     */
    public function validation(): array
    {
        $freeze = $this->storage->readJson('counting/vvdat-ledger-freeze.json');

        if ($freeze === []) {
            return [
                'passed' => false,
                'record_count' => 0,
                'ledger_root' => null,
                'errors' => ['The VVDAT ledger has not been frozen.'],
            ];
        }

        try {
            $this->assertValid($freeze);
        } catch (RuntimeException $exception) {
            return [
                'passed' => false,
                'record_count' => (int) ($freeze['record_count'] ?? 0),
                'ledger_root' => $freeze['ledger_root'] ?? null,
                'errors' => [$exception->getMessage()],
            ];
        }

        return [
            'passed' => true,
            'record_count' => (int) $freeze['record_count'],
            'ledger_root' => (string) $freeze['ledger_root'],
            'errors' => [],
        ];
    }

    public function assertCanMutate(): void
    {
        if ($this->storage->readJson('counting/vvdat-ledger-freeze.json') !== []) {
            throw new RuntimeException('The VVDAT ledger is frozen and cannot accept another deposited ballot.');
        }
    }

    /** @param array<string, mixed> $freeze */
    private function assertValid(array $freeze): void
    {
        $records = $this->ledger->records();
        $hashes = collect($records)->pluck('record_hash')->values()->all();
        $root = $this->json->hash([
            'record_count' => count($records),
            'record_hashes' => $hashes,
        ]);

        if (
            (int) ($freeze['record_count'] ?? -1) !== count($records)
            || ! hash_equals((string) ($freeze['ledger_root'] ?? ''), $root)
            || ! hash_equals(
                (string) ($freeze['freeze_hash'] ?? ''),
                $this->json->hash(collect($freeze)->except(['artifact_path', 'freeze_hash'])->all()),
            )
        ) {
            throw new RuntimeException('The frozen VVDAT ledger does not match the current sealed records.');
        }
    }
}
