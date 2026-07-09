<?php

namespace App\Election\Certification;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;

final class SealingService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $runPath = $this->storage->activeRunPath();
        $runId = basename($runPath);
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $precinct = (string) ($configuration['precinct_id'] ?? 'unknown-precinct');

        $certificationReport = $this->storage->readJson('certification/friday-certification-report.json');
        $manualVerificationReport = $this->storage->readJson('certification/manual-verification-report.json');
        $discrepancyReport = $this->storage->readJson('certification/fts-discrepancy-report.json');
        $zeroOutReport = $this->storage->readJson('certification/zero-out-report.json');
        $initializationReport = $this->storage->readJson('diagnostics/initialization-report.json');

        $checks = [
            $this->checkCertificationReport($certificationReport),
            $this->checkManualVerification($manualVerificationReport),
            $this->checkDiscrepancyResolution($discrepancyReport),
            $this->checkZeroOut($zeroOutReport),
            $this->checkInitializationReport($initializationReport),
        ];

        $passed = collect($checks)->every(fn (array $check): bool => $check['passed'] === true);

        $report = [
            'schema_version' => 'sealing-report-1',
            'report_profile' => 'fts-sealing-v1',
            'run_id' => $runId,
            'precinct_id' => $precinct,
            'generated_at' => $this->clock->now()->toIso8601String(),
            'status' => $passed ? 'sealed' : 'not_sealed',
            'checks' => $checks,
            'passed' => $passed,
            'artifacts' => [
                'certification_report_path' => $this->storage->path('certification/friday-certification-report.json'),
                'manual_verification_report_path' => $this->storage->path('certification/manual-verification-report.json'),
                'discrepancy_report_path' => $this->storage->path('certification/fts-discrepancy-report.json'),
                'zero_out_report_path' => $this->storage->path('certification/zero-out-report.json'),
                'initialization_report_path' => $this->storage->path('diagnostics/initialization-report.json'),
            ],
            'certification_report_hash' => $certificationReport['report_hash'] ?? null,
            'manual_verification_report_hash' => $manualVerificationReport['report_hash'] ?? null,
            'discrepancy_report_hash' => $discrepancyReport['report_hash'] ?? null,
            'zero_out_report_hash' => $zeroOutReport['report_hash'] ?? null,
            'initialization_report_hash' => $initializationReport['report_hash'] ?? null,
        ];

        $report['report_hash'] = $this->json->hash($this->reportForHash($report));
        $report['artifact_path'] = $this->storage->writeJson('certification/sealing-report.json', $report);

        $this->journal->record('certification.sealed', [
            'run_id' => $runId,
            'precinct_id' => $precinct,
            'status' => $report['status'],
            'report_hash' => $report['report_hash'],
            'passed' => $passed,
        ]);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $certificationReport
     * @return array<string, mixed>
     */
    private function checkCertificationReport(array $certificationReport): array
    {
        return [
            'name' => 'certification_report_present',
            'passed' => $certificationReport !== [],
            'details' => [
                'certification_present' => $certificationReport !== [],
                'certification_passed' => $certificationReport['passed'] ?? null,
                'report_hash' => $certificationReport['report_hash'] ?? null,
            ],
            'message' => $certificationReport === []
                ? 'No certification report exists. Run certification ballots first.'
                : ($certificationReport['passed'] ?? false
                    ? 'Certification report present and passed.'
                    : 'Certification report exists but did not pass.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $manualVerificationReport
     * @return array<string, mixed>
     */
    private function checkManualVerification(array $manualVerificationReport): array
    {
        return [
            'name' => 'manual_verification_present',
            'passed' => $manualVerificationReport !== [] && (bool) ($manualVerificationReport['passed'] ?? false),
            'details' => [
                'manual_verification_present' => $manualVerificationReport !== [],
                'manual_verification_passed' => $manualVerificationReport['passed'] ?? null,
                'report_hash' => $manualVerificationReport['report_hash'] ?? null,
            ],
            'message' => $manualVerificationReport === []
                ? 'No manual verification report exists. Run manual verification first.'
                : ((bool) ($manualVerificationReport['passed'] ?? false)
                    ? 'Manual verification report exists and passed.'
                    : 'Manual verification report exists but failed.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $discrepancyReport
     * @return array<string, mixed>
     */
    private function checkDiscrepancyResolution(array $discrepancyReport): array
    {
        return [
            'name' => 'discrepancy_resolved',
            'passed' => $discrepancyReport !== [] && ! (bool) ($discrepancyReport['discrepancy_detected'] ?? true),
            'details' => [
                'discrepancy_report_present' => $discrepancyReport !== [],
                'discrepancy_detected' => $discrepancyReport['discrepancy_detected'] ?? null,
                'report_hash' => $discrepancyReport['report_hash'] ?? null,
            ],
            'message' => $discrepancyReport === []
                ? 'No discrepancy report exists. Run discrepancy analysis first.'
                : (($discrepancyReport['discrepancy_detected'] ?? false)
                    ? 'Discrepancy remains. Resolve before sealing.'
                    : 'No discrepancy detected. Ready to seal.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $zeroOutReport
     * @return array<string, mixed>
     */
    private function checkZeroOut(array $zeroOutReport): array
    {
        return [
            'name' => 'zero_out_complete',
            'passed' => $zeroOutReport !== [] && (bool) ($zeroOutReport['passed'] ?? false),
            'details' => [
                'zero_out_present' => $zeroOutReport !== [],
                'accepted_zeroed' => (int) ($zeroOutReport['counts_after']['accepted_ballots'] ?? 1),
                'rejected_zeroed' => (int) ($zeroOutReport['counts_after']['rejected_ballots'] ?? 1),
                'spoiled_zeroed' => (int) ($zeroOutReport['counts_after']['spoiled_ballots'] ?? 1),
                'report_hash' => $zeroOutReport['report_hash'] ?? null,
            ],
            'message' => $zeroOutReport === []
                ? 'No zero-out report exists. Run zero-out before sealing.'
                : ((bool) ($zeroOutReport['passed'] ?? false)
                    ? 'Zero-out completed and counted ballots are cleared.'
                    : 'Zero-out did not complete successfully.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $initializationReport
     * @return array<string, mixed>
     */
    private function checkInitializationReport(array $initializationReport): array
    {
        return [
            'name' => 'initialization_report_present',
            'passed' => $initializationReport !== [] && (bool) ($initializationReport['passed'] ?? false),
            'details' => [
                'initialization_report_present' => $initializationReport !== [],
                'initialization_passed' => $initializationReport['passed'] ?? null,
                'report_hash' => $initializationReport['report_hash'] ?? null,
            ],
            'message' => $initializationReport === []
                ? 'No initialization report exists. Run initialization report before certification seal.'
                : ((bool) ($initializationReport['passed'] ?? false)
                    ? 'Initialization report is present and passed.'
                    : 'Initialization report exists but did not pass.'),
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
}
