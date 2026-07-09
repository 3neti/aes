<?php

namespace App\Election\Certification;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Minutes\OfficialMinutesBaselineService;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;

final class DiscrepancyReportService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly OfficialMinutesBaselineService $officialMinutes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $runPath = $this->storage->activeRunPath();
        $runId = basename($runPath);
        $certificationReport = $this->storage->readJson('certification/friday-certification-report.json');
        $manualVerification = $this->storage->readJson('certification/manual-verification-report.json');
        $precinctId = $this->firstStringOrNull($manualVerification['precinct_id'] ?? $certificationReport['precinct_id'] ?? null)
            ?? 'unknown-precinct';

        $checks = [
            $this->manualVerificationPresenceCheck($manualVerification),
            $this->certificationPresenceCheck($certificationReport),
            $this->verificationAlignmentCheck($manualVerification, $certificationReport),
        ];

        $manualPassed = (bool) ($manualVerification['passed'] ?? false);
        $discrepancyDetected = ! $manualPassed;

        $minutes = $this->officialMinutes->write();

        $report = [
            'schema_version' => 'fts-discrepancy-report-1',
            'run_id' => $runId,
            'precinct_id' => $precinctId,
            'generated_at' => $this->clock->now()->toIso8601String(),
            'status' => $discrepancyDetected ? 'discrepancy' : 'clear',
            'discrepancy_detected' => $discrepancyDetected,
            'certification_report_path' => $this->storage->path('certification/friday-certification-report.json'),
            'manual_verification_report_path' => $this->storage->path('certification/manual-verification-report.json'),
            'official_minutes_path' => $minutes['artifact_path'] ?? null,
            'official_minutes_profile' => $minutes['baseline_profile'] ?? null,
            'official_minutes_hash' => $minutes['official_minute_hash'] ?? null,
            'manual_verification_report_hash' => $manualVerification['report_hash'] ?? null,
            'certification_report_hash' => $certificationReport['report_hash'] ?? null,
            'checks' => $checks,
            'passed' => ! $discrepancyDetected,
            'notes' => $this->resolutionNotes($discrepancyDetected),
        ];

        if ($discrepancyDetected) {
            $report['remediation'] = [
                'action' => 're-appreciation-and-escalation',
                'requirements' => [
                    'complete re-appreciation by election personnel',
                    'record minutes of discrepancy handling',
                    'run discrepancy analysis again after resolution',
                ],
            ];
        }

        $report['report_hash'] = $this->json->hash($this->reportForHash($report));
        $report['artifact_path'] = $this->storage->writeJson('certification/fts-discrepancy-report.json', $report);

        $this->journal->record('certification.discrepancy_evaluated', [
            'run_id' => $runId,
            'precinct_id' => $precinctId,
            'discrepancy_detected' => $discrepancyDetected,
            'report_hash' => $report['report_hash'],
            'official_minutes_hash' => $minutes['official_minute_hash'] ?? null,
        ]);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function manualVerificationPresenceCheck(array $manualVerification): array
    {
        return [
            'name' => 'manual_verification_present',
            'passed' => $manualVerification !== [],
            'details' => [
                'manual_verification_present' => $manualVerification !== [],
                'manual_verification_hash' => $manualVerification['report_hash'] ?? null,
            ],
            'message' => $manualVerification === []
                ? 'Manual verification report is missing. Run manual verification first.'
                : 'Manual verification report is available.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function certificationPresenceCheck(array $certificationReport): array
    {
        return [
            'name' => 'certification_report_present',
            'passed' => $certificationReport !== [],
            'details' => [
                'certification_report_present' => $certificationReport !== [],
                'certification_hash' => $certificationReport['report_hash'] ?? null,
            ],
            'message' => $certificationReport === []
                ? 'Certification report is missing. Run certification test ballots first.'
                : 'Certification report is available.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function verificationAlignmentCheck(array $manualVerification, array $certificationReport): array
    {
        $manualAccepted = (int) ($manualVerification['manual_accepted_ballots'] ?? 0);
        $manualRejected = (int) ($manualVerification['manual_rejected_ballots'] ?? 0);
        $machineAccepted = (int) ($manualVerification['machine_accepted_ballots'] ?? ($certificationReport['accepted_ballots'] ?? 0));
        $machineRejected = (int) ($manualVerification['machine_rejected_ballots'] ?? ($certificationReport['rejected_ballots'] ?? 0));

        $passed = $manualVerification !== [] && $certificationReport !== [] && $manualAccepted === $machineAccepted && $manualRejected === $machineRejected;

        return [
            'name' => 'manual_vs_machine_alignment',
            'passed' => $passed,
            'details' => [
                'manual_accepted_ballots' => $manualAccepted,
                'manual_rejected_ballots' => $manualRejected,
                'machine_accepted_ballots' => $machineAccepted,
                'machine_rejected_ballots' => $machineRejected,
            ],
            'message' => $passed
                ? 'Manual verification aligns with machine certification counts.'
                : 'Manual verification totals do not align with machine certification.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function resolutionNotes(bool $discrepancyDetected): array
    {
        if (! $discrepancyDetected) {
            return ['note' => 'No discrepancy detected. Proceed to zero-out and sealing ceremony.'];
        }

        return [
            'note' => 'Discrepancy detected. Do not certify election return until discrepancy resolution is completed.',
            'next_action' => 'Create official discrepancy record and route to authorized resolution flow.',
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function reportForHash(array $report): array
    {
        return [
            ...$report,
            'artifact_path' => null,
            'artifact_file_size' => null,
        ];
    }

    private function firstStringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
