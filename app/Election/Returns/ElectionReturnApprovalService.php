<?php

namespace App\Election\Returns;

use App\Election\Attestation\OfficerRegistry;
use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ElectionReturnApprovalService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly OfficerRegistry $officers,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function approve(string $chairCode, string $chairPin, string $clerkCode, string $clerkPin): array
    {
        $chair = $this->officers->verify($chairCode, $chairPin);
        $clerk = $this->officers->verify($clerkCode, $clerkPin);

        if ($chair === null || $chair['role'] !== 'Election Board Chairperson') {
            throw ValidationException::withMessages(['chairperson_pin' => 'The Chairperson code or PIN is invalid.']);
        }

        if ($clerk === null || $clerk['role'] !== 'Poll Clerk' || $clerk['code'] === $chair['code']) {
            throw ValidationException::withMessages(['poll_clerk_pin' => 'A distinct Poll Clerk approval is required.']);
        }

        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $precinct = (string) ($configuration['precinct_id'] ?? '');
        $return = $this->storage->readJson("returns/{$precinct}-return.json");
        $distribution = $this->storage->readJson("returns/{$precinct}-copy-distribution.json");
        $legalEvidence = $this->storage->readJson('returns/election-return-legal-evidence.json');

        if ($return === [] || $distribution === [] || $legalEvidence === []) {
            throw new RuntimeException('Generate, verify, print, and post the Election Return before approval.');
        }

        $report = [
            'schema_version' => 'election-return-approval-1',
            'run_id' => $this->storage->currentRun()['run_id'] ?? null,
            'precinct_id' => $precinct,
            'approved_at' => $this->clock->now()->toIso8601String(),
            'return_hash' => $return['return_hash'] ?? null,
            'distribution_hash' => $distribution['distribution_hash'] ?? null,
            'legal_evidence_hash' => $legalEvidence['evidence_hash'] ?? null,
            'counts_match' => (bool) ($legalEvidence['counts_match'] ?? false),
            'posting_status' => $distribution['posting']['status'] ?? null,
            'approvers' => [
                $this->officerEvidence($chair),
                $this->officerEvidence($clerk),
            ],
        ];
        $report['passed'] = $report['counts_match']
            && $report['posting_status'] === 'completed'
            && is_string($report['return_hash']);
        $report['approval_hash'] = $this->json->hash($report);
        $report['artifact_path'] = $this->storage->path('returns/election-return-approval.json');
        $this->storage->writeJson('returns/election-return-approval.json', $report);
        $this->journal->record('return.approved', [
            'return_hash' => $report['return_hash'],
            'approval_hash' => $report['approval_hash'],
            'passed' => $report['passed'],
        ]);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return $this->storage->readJson('returns/election-return-approval.json');
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
}
