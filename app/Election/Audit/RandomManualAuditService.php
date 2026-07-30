<?php

namespace App\Election\Audit;

use App\Election\Attestation\OfficerRegistry;
use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionOperationLock;
use App\Election\Support\ElectionStorage;
use App\Election\Tabulation\DeviceTabulationLedger;
use App\Election\Tabulation\TabulationProfileResolver;
use App\Election\Voting\BallotPayloadService;
use App\Election\Voting\BallotSelectionValidator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class RandomManualAuditService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly BallotPayloadService $payloads,
        private readonly BallotSelectionValidator $selections,
        private readonly OfficerRegistry $officers,
        private readonly DeviceTabulationLedger $deviceLedger,
        private readonly TabulationProfileResolver $tabulation,
        private readonly ElectionOperationLock $lock,
        private readonly LifecycleState $lifecycle,
    ) {}

    /**
     * @param  array{payload: string, adapter: string, raw_payload_hash: string}  $scan
     * @return array<string, mixed>
     */
    public function propose(array $scan): array
    {
        $this->ensureEnabled();
        $payload = $this->payloads->decode($scan['payload']);
        $this->validatePayload($payload);
        $payloadHash = (string) $payload['payload_hash'];

        if ($this->deviceLedger->findByPayloadHash($payloadHash) === null) {
            throw ValidationException::withMessages([
                'payload' => 'The scanned QR code is not present in this precinct device tabulation record.',
            ]);
        }

        $existing = $this->proposal($payloadHash);

        if (($existing['status'] ?? null) === 'pending-dual-approval') {
            return $existing;
        }

        if ($existing !== []) {
            throw ValidationException::withMessages([
                'payload' => 'This ballot already has a completed random manual audit record.',
            ]);
        }

        $proposal = [
            'schema_version' => 'random-manual-audit-proposal-1',
            'status' => 'pending-dual-approval',
            'ballot_id' => $payload['ballot_id'],
            'payload_hash' => $payloadHash,
            'paper_ballot_serial' => $payload['paper_ballot_serial'] ?? null,
            'selections' => $payload['selections'],
            'scanner_adapter' => $scan['adapter'],
            'raw_input_hash' => $scan['raw_payload_hash'],
            'proposed_at' => $this->clock->now()->toIso8601String(),
        ];
        $proposal['proposal_hash'] = $this->json->hash($proposal);
        $proposal['artifact_path'] = $this->storage->path("rma/proposals/{$payloadHash}.json");
        $this->storage->writeJson("rma/proposals/{$payloadHash}.json", $proposal);

        $this->journal->record('rma.ballot_proposed', [
            'ballot_id' => $proposal['ballot_id'],
            'payload_hash' => $payloadHash,
            'proposal_hash' => $proposal['proposal_hash'],
            'scanner_adapter' => $proposal['scanner_adapter'],
        ]);

        return $proposal;
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(
        string $payloadHash,
        string $firstOfficerCode,
        string $firstOfficerPin,
        string $secondOfficerCode,
        string $secondOfficerPin,
    ): array {
        return $this->lock->execute('random-manual-audit', function () use ($payloadHash, $firstOfficerCode, $firstOfficerPin, $secondOfficerCode, $secondOfficerPin): array {
            $this->ensureEnabled();
            $proposal = $this->proposal($payloadHash);

            if (($proposal['status'] ?? null) !== 'pending-dual-approval') {
                throw ValidationException::withMessages([
                    'payload_hash' => 'There is no pending random manual audit proposal for this ballot.',
                ]);
            }

            $firstOfficer = $this->verifiedOfficer($firstOfficerCode, $firstOfficerPin, 'first_officer_pin');
            $secondOfficer = $this->verifiedOfficer($secondOfficerCode, $secondOfficerPin, 'second_officer_pin');

            if ($firstOfficer['code'] === $secondOfficer['code']) {
                throw ValidationException::withMessages([
                    'second_officer_code' => 'A second, distinct Election Board officer must approve the paper comparison.',
                ]);
            }

            $sequence = count($this->acceptedRecords()) + 1;
            $record = [
                'schema_version' => 'random-manual-audit-record-1',
                'sequence' => $sequence,
                'status' => 'approved-paper-comparison',
                'ballot_id' => $proposal['ballot_id'],
                'payload_hash' => $payloadHash,
                'paper_ballot_serial' => $proposal['paper_ballot_serial'],
                'selections' => $proposal['selections'],
                'proposal_hash' => $proposal['proposal_hash'],
                'paper_comparison_confirmed' => true,
                'approved_at' => $this->clock->now()->toIso8601String(),
                'approvals' => [
                    $this->officerEvidence($firstOfficer),
                    $this->officerEvidence($secondOfficer),
                ],
            ];
            $record['record_hash'] = $this->json->hash($record);
            $record['artifact_path'] = $this->storage->path(
                sprintf('rma/accepted/%06d-%s.json', $sequence, $payloadHash),
            );
            $this->storage->writeJson(
                sprintf('rma/accepted/%06d-%s.json', $sequence, $payloadHash),
                $record,
            );

            $proposal = [
                ...$proposal,
                'status' => 'approved',
                'approved_record_path' => $record['artifact_path'],
                'approved_record_hash' => $record['record_hash'],
            ];
            $this->storage->writeJson("rma/proposals/{$payloadHash}.json", $proposal);

            $this->journal->record('rma.ballot_approved', [
                'sequence' => $sequence,
                'ballot_id' => $record['ballot_id'],
                'payload_hash' => $payloadHash,
                'record_hash' => $record['record_hash'],
                'officer_code_hashes' => array_column($record['approvals'], 'code_hash'),
            ]);

            return $record;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $records = $this->acceptedRecords();
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $tally = [];

        foreach (($configuration['contests'] ?? []) as $contest) {
            $tally[$contest['id']] = [];

            foreach ($contest['candidates'] as $candidate) {
                $tally[$contest['id']][$candidate['id']] = 0;
            }
        }

        foreach ($records as $record) {
            foreach ($record['selections'] as $contestId => $candidateIds) {
                foreach ($candidateIds as $candidateId) {
                    $tally[$contestId][$candidateId] = ($tally[$contestId][$candidateId] ?? 0) + 1;
                }
            }
        }

        return [
            'enabled' => ! $this->tabulation->current()->routineScanningEnabled(),
            'proposed_ballots' => count($this->pendingProposals()),
            'approved_ballots' => count($records),
            'pending_proposal' => $this->pendingProposals()[0] ?? null,
            'tally' => $tally,
        ];
    }

    private function ensureEnabled(): void
    {
        if ($this->lifecycle->current() !== Lifecycle::Counting) {
            throw ValidationException::withMessages([
                'lifecycle' => 'Random manual audit is available only during counting.',
            ]);
        }

        if ($this->tabulation->current()->routineScanningEnabled()) {
            throw new RuntimeException('Random manual audit is available only for the device tabulation with paper audit profile.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatePayload(array $payload): void
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');

        if (($payload['mapping_hash'] ?? null) !== ($configuration['mapping_hash'] ?? null)) {
            throw ValidationException::withMessages(['payload' => 'The QR code belongs to a different ballot mapping.']);
        }

        if (($payload['tabulation_profile'] ?? null) !== ($configuration['tabulation_profile'] ?? null)) {
            throw ValidationException::withMessages(['payload' => 'The QR code belongs to a different tabulation profile.']);
        }

        if (($payload['payload_hash'] ?? null) !== $this->json->hash(array_diff_key($payload, [
            'qr_payload' => true,
            'qr_artifact_path' => true,
            'payload_hash' => true,
        ]))) {
            throw ValidationException::withMessages(['payload' => 'The QR payload hash is invalid.']);
        }

        $this->selections->validate($configuration, $payload['selections'] ?? []);
    }

    /**
     * @return array<string, string>
     */
    private function verifiedOfficer(string $code, string $pin, string $field): array
    {
        $officer = $this->officers->verify($code, $pin);

        if ($officer === null) {
            throw ValidationException::withMessages([$field => 'The officer code or PIN is invalid.']);
        }

        return $officer;
    }

    /**
     * @param  array<string, string>  $officer
     * @return array<string, string>
     */
    private function officerEvidence(array $officer): array
    {
        return [
            'code_hash' => hash('sha256', $officer['code']),
            'name' => $officer['name'],
            'role' => $officer['role'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function proposal(string $payloadHash): array
    {
        return $this->storage->readJson("rma/proposals/{$payloadHash}.json");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pendingProposals(): array
    {
        return collect($this->storage->files('rma/proposals'))
            ->map(fn (string $path): array => $this->readRecord($path))
            ->filter(fn (array $record): bool => ($record['status'] ?? null) === 'pending-dual-approval')
            ->sortByDesc('proposed_at')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function acceptedRecords(): array
    {
        return collect($this->storage->files('rma/accepted'))
            ->map(fn (string $path): array => $this->readRecord($path))
            ->sortBy('sequence')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function readRecord(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read random manual audit record [{$path}].");
        }

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }
}
