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
use App\Election\Support\SimplePdf;
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
        private readonly SimplePdf $pdf,
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
        $sample = $this->sampleSelection();

        if ($sample === []) {
            throw ValidationException::withMessages([
                'payload' => 'Select and record the random manual audit sample before scanning a paper ballot.',
            ]);
        }

        if (! collect($sample['selected_ballots'] ?? [])->contains('payload_hash', $payloadHash)) {
            throw ValidationException::withMessages([
                'payload' => 'This ballot is not part of the recorded random manual audit sample.',
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
    public function selectSample(): array
    {
        return $this->lock->execute('random-manual-audit', function (): array {
            $this->ensureEnabled();
            $existing = $this->sampleSelection();

            if ($existing !== []) {
                return $existing;
            }

            $records = $this->deviceLedger->records();

            if ($records === []) {
                throw ValidationException::withMessages([
                    'sample' => 'No sealed device tabulation records are available for random manual audit.',
                ]);
            }

            $configuration = $this->storage->readJson('runtime/active-precinct.json');
            $ledgerRecords = collect($records)
                ->map(fn (array $record): array => [
                    'payload_hash' => $record['payload_hash'],
                    'record_hash' => $record['record_hash'],
                ])
                ->sortBy('payload_hash')
                ->values()
                ->all();
            $seed = $this->json->hash([
                'schema_version' => 'random-manual-audit-sample-seed-1',
                'election_id' => $configuration['election_id'] ?? null,
                'precinct_id' => $configuration['precinct_id'] ?? null,
                'mapping_hash' => $configuration['mapping_hash'] ?? null,
                'device_ledger' => $ledgerRecords,
            ]);
            $rate = max(1, (int) config('election.random_manual_audit.sample_percent', 10));
            $sampleSize = min(count($records), max(1, (int) ceil(count($records) * ($rate / 100))));
            $selected = collect($records)
                ->map(fn (array $record): array => [
                    'payload_hash' => $record['payload_hash'],
                    'paper_ballot_serial' => $record['paper_ballot_serial'] ?? null,
                    'record_hash' => $record['record_hash'],
                    'selection_rank' => hash('sha256', $seed.'|'.$record['payload_hash']),
                ])
                ->sortBy('selection_rank')
                ->take($sampleSize)
                ->values()
                ->all();
            $sample = [
                'schema_version' => 'random-manual-audit-sample-1',
                'selection_method' => 'deterministic-sha-256-rank-1',
                'sample_percent' => $rate,
                'seed' => $seed,
                'source_record_count' => count($records),
                'sample_size' => $sampleSize,
                'selected_ballots' => $selected,
                'selected_at' => $this->clock->now()->toIso8601String(),
            ];
            $sample['sample_hash'] = $this->json->hash($sample);
            $sample['artifact_path'] = $this->storage->path('rma/sample-selection.json');
            $this->storage->writeJson('rma/sample-selection.json', $sample);

            $this->journal->record('rma.sample_selected', [
                'sample_hash' => $sample['sample_hash'],
                'sample_size' => $sampleSize,
                'source_record_count' => count($records),
            ]);

            return $sample;
        });
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
    public function recordDiscrepancy(
        string $payloadHash,
        string $reason,
        string $firstOfficerCode,
        string $firstOfficerPin,
        string $secondOfficerCode,
        string $secondOfficerPin,
    ): array {
        return $this->lock->execute('random-manual-audit', function () use ($payloadHash, $reason, $firstOfficerCode, $firstOfficerPin, $secondOfficerCode, $secondOfficerPin): array {
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
                    'second_officer_code' => 'A second, distinct Election Board officer must confirm the paper discrepancy.',
                ]);
            }

            $sequence = count($this->discrepancyRecords()) + 1;
            $record = [
                'schema_version' => 'random-manual-audit-discrepancy-1',
                'sequence' => $sequence,
                'status' => 'paper-discrepancy-recorded',
                'ballot_id' => $proposal['ballot_id'],
                'payload_hash' => $payloadHash,
                'paper_ballot_serial' => $proposal['paper_ballot_serial'],
                'proposal_hash' => $proposal['proposal_hash'],
                'reason' => trim($reason),
                'recorded_at' => $this->clock->now()->toIso8601String(),
                'approvals' => [
                    $this->officerEvidence($firstOfficer),
                    $this->officerEvidence($secondOfficer),
                ],
            ];
            $record['record_hash'] = $this->json->hash($record);
            $record['artifact_path'] = $this->storage->path(
                sprintf('rma/discrepancies/%06d-%s.json', $sequence, $payloadHash),
            );
            $this->storage->writeJson(
                sprintf('rma/discrepancies/%06d-%s.json', $sequence, $payloadHash),
                $record,
            );

            $proposal = [
                ...$proposal,
                'status' => 'paper-discrepancy-recorded',
                'discrepancy_record_path' => $record['artifact_path'],
                'discrepancy_record_hash' => $record['record_hash'],
            ];
            $this->storage->writeJson("rma/proposals/{$payloadHash}.json", $proposal);
            $this->journal->record('rma.paper_discrepancy_recorded', [
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
    public function generateReconciliationReport(): array
    {
        return $this->lock->execute('random-manual-audit', function (): array {
            $this->ensureEnabled();
            $sample = $this->sampleSelection();

            if ($sample === []) {
                throw ValidationException::withMessages([
                    'sample' => 'Select and record the random manual audit sample before generating its reconciliation report.',
                ]);
            }

            $deviceRecords = collect($this->deviceLedger->recordsForTally())->keyBy('payload_hash');
            $approved = collect($this->acceptedRecords())->keyBy('payload_hash');
            $discrepancies = collect($this->discrepancyRecords())->keyBy('payload_hash');
            $entries = collect($sample['selected_ballots'])
                ->map(function (array $selected) use ($deviceRecords, $approved, $discrepancies): array {
                    $payloadHash = (string) $selected['payload_hash'];
                    $deviceRecord = $deviceRecords->get($payloadHash);
                    $approvedRecord = $approved->get($payloadHash);
                    $discrepancy = $discrepancies->get($payloadHash);
                    $status = 'pending-paper-comparison';
                    $selectionsMatch = null;

                    if (! is_array($deviceRecord)) {
                        $status = 'device-record-missing';
                    } elseif (is_array($approvedRecord)) {
                        $selectionsMatch = $this->json->hash($deviceRecord['selections']) === $this->json->hash($approvedRecord['selections']);
                        $status = $selectionsMatch ? 'verified' : 'device-record-selection-mismatch';
                    } elseif (is_array($discrepancy)) {
                        $status = 'paper-discrepancy-recorded';
                    }

                    return [
                        'payload_hash' => $payloadHash,
                        'paper_ballot_serial' => $selected['paper_ballot_serial'] ?? null,
                        'device_record_hash' => is_array($deviceRecord) ? $deviceRecord['record_hash'] : null,
                        'audit_record_hash' => is_array($approvedRecord) ? $approvedRecord['record_hash'] : null,
                        'discrepancy_record_hash' => is_array($discrepancy) ? $discrepancy['record_hash'] : null,
                        'status' => $status,
                        'selections_match' => $selectionsMatch,
                    ];
                })
                ->values()
                ->all();
            $statusCounts = collect($entries)
                ->countBy('status')
                ->all();
            $report = [
                'schema_version' => 'random-manual-audit-reconciliation-1',
                'sample_hash' => $sample['sample_hash'],
                'sample_size' => $sample['sample_size'],
                'source_record_count' => $sample['source_record_count'],
                'verified_ballots' => $statusCounts['verified'] ?? 0,
                'discrepancy_ballots' => $statusCounts['paper-discrepancy-recorded'] ?? 0,
                'pending_ballots' => $statusCounts['pending-paper-comparison'] ?? 0,
                'device_record_issues' => ($statusCounts['device-record-missing'] ?? 0) + ($statusCounts['device-record-selection-mismatch'] ?? 0),
                'complete' => count($entries) === (int) $sample['sample_size']
                    && (($statusCounts['pending-paper-comparison'] ?? 0) === 0),
                'passed' => count($entries) === (int) $sample['sample_size']
                    && (($statusCounts['verified'] ?? 0) === (int) $sample['sample_size']),
                'entries' => $entries,
                'generated_at' => $this->clock->now()->toIso8601String(),
            ];
            $report['report_hash'] = $this->json->hash($report);
            $report['artifact_path'] = $this->storage->path('rma/reconciliation-report.json');
            $this->storage->writeJson('rma/reconciliation-report.json', $report);

            $this->journal->record('rma.reconciliation_report_generated', [
                'sample_hash' => $report['sample_hash'],
                'report_hash' => $report['report_hash'],
                'verified_ballots' => $report['verified_ballots'],
                'discrepancy_ballots' => $report['discrepancy_ballots'],
                'pending_ballots' => $report['pending_ballots'],
                'passed' => $report['passed'],
            ]);

            return $report;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function buildEvidencePack(): array
    {
        return $this->lock->execute('random-manual-audit', function (): array {
            $this->ensureEnabled();
            $sample = $this->sampleSelection();
            $reconciliation = $this->storage->readJson('rma/reconciliation-report.json');

            if ($sample === [] || $reconciliation === []) {
                throw ValidationException::withMessages([
                    'evidence_pack' => 'Generate the random manual audit reconciliation report before building its evidence pack.',
                ]);
            }

            $approved = $this->acceptedRecords();
            $discrepancies = $this->discrepancyRecords();
            $pack = [
                'schema_version' => 'random-manual-audit-evidence-pack-1',
                'generated_at' => $this->clock->now()->toIso8601String(),
                'sample_selection' => $sample,
                'reconciliation_report' => $reconciliation,
                'approved_paper_comparisons' => $approved,
                'paper_discrepancies' => $discrepancies,
                'artifact_paths' => [
                    'json' => 'rma/evidence-pack.json',
                    'text' => 'rma/evidence-pack.txt',
                    'pdf' => 'rma/evidence-pack.pdf',
                ],
                'artifact_count' => 2 + count($approved) + count($discrepancies),
            ];
            $pack['evidence_pack_hash'] = $this->json->hash($pack);
            $this->storage->writeJson('rma/evidence-pack.json', $pack);
            $this->storage->writeText('rma/evidence-pack.txt', $this->renderEvidencePackText($pack));
            $this->storage->writeText('rma/evidence-pack.pdf', $this->pdf->render(
                'Random Manual Audit Evidence Pack',
                $this->renderEvidencePackLines($pack),
            ));

            $this->journal->record('rma.evidence_pack_built', [
                'evidence_pack_hash' => $pack['evidence_pack_hash'],
                'sample_hash' => $sample['sample_hash'],
                'reconciliation_report_hash' => $reconciliation['report_hash'],
                'artifact_count' => $pack['artifact_count'],
                'passed' => $reconciliation['passed'] ?? false,
            ]);

            return $pack;
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
            'sample_selection' => $this->sampleSelection(),
            'proposed_ballots' => count($this->pendingProposals()),
            'approved_ballots' => count($records),
            'discrepancy_ballots' => count($this->discrepancyRecords()),
            'pending_proposal' => $this->pendingProposals()[0] ?? null,
            'reconciliation_report' => $this->storage->readJson('rma/reconciliation-report.json'),
            'evidence_pack' => $this->storage->readJson('rma/evidence-pack.json'),
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
     * @return array<string, mixed>
     */
    private function sampleSelection(): array
    {
        return $this->storage->readJson('rma/sample-selection.json');
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
     * @return array<int, array<string, mixed>>
     */
    private function discrepancyRecords(): array
    {
        return collect($this->storage->files('rma/discrepancies'))
            ->map(fn (string $path): array => $this->readRecord($path))
            ->sortBy('sequence')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $pack
     * @return array<int, string>
     */
    private function renderEvidencePackLines(array $pack): array
    {
        $reconciliation = $pack['reconciliation_report'];
        $sample = $pack['sample_selection'];
        $lines = [
            'RANDOM MANUAL AUDIT EVIDENCE PACK',
            '',
            'Evidence Pack Hash: '.$pack['evidence_pack_hash'],
            'Generated At: '.$pack['generated_at'],
            'Sample Hash: '.($sample['sample_hash'] ?? 'unknown'),
            'Sample Size: '.($sample['sample_size'] ?? 0).' of '.($sample['source_record_count'] ?? 0),
            'Reconciliation Hash: '.($reconciliation['report_hash'] ?? 'unknown'),
            'Reconciliation Passed: '.(($reconciliation['passed'] ?? false) ? 'YES' : 'NO'),
            'Verified Ballots: '.($reconciliation['verified_ballots'] ?? 0),
            'Paper Discrepancies: '.($reconciliation['discrepancy_ballots'] ?? 0),
            'Pending Ballots: '.($reconciliation['pending_ballots'] ?? 0),
            'Device Record Issues: '.($reconciliation['device_record_issues'] ?? 0),
            '',
            'SAMPLE ENTRIES:',
        ];

        foreach (($reconciliation['entries'] ?? []) as $entry) {
            $lines[] = sprintf(
                'Paper ballot %s: %s (%s)',
                $entry['paper_ballot_serial'] ?? 'serial unavailable',
                $entry['status'] ?? 'unknown',
                substr((string) ($entry['payload_hash'] ?? ''), 0, 16),
            );
        }

        $lines[] = '';
        $lines[] = 'The JSON evidence pack embeds the frozen sample, reconciliation report, approved paper comparisons, and paper discrepancy records.';

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $pack
     */
    private function renderEvidencePackText(array $pack): string
    {
        return implode(PHP_EOL, $this->renderEvidencePackLines($pack)).PHP_EOL;
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
