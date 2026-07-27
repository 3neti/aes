<?php

namespace App\Election\Scenarios;

use App\Election\Attestation\ElectoralBoardBaselineService;
use App\Election\Attestation\OfficerAttestationService;
use App\Election\Audit\AuditReconciliationBaselineService;
use App\Election\Certification\CertificationDeckBuilder;
use App\Election\Certification\CertificationService;
use App\Election\Certification\DiscrepancyReportService;
use App\Election\Certification\InitializationReportService;
use App\Election\Certification\ManualVerificationService;
use App\Election\Certification\SealingService;
use App\Election\Certification\ZeroOutService;
use App\Election\Core\ActivityJournal;
use App\Election\Counting\CountingLegalEvidenceService;
use App\Election\Counting\CountingReconciliationService;
use App\Election\Counting\CountingService;
use App\Election\Custody\CustodyService;
use App\Election\Devices\DeviceCertificationService;
use App\Election\Diagnostics\ApplianceRecoveryService;
use App\Election\Diagnostics\EvidenceBundleArchiveBuilder;
use App\Election\Diagnostics\EvidenceBundleArchiveVerifier;
use App\Election\Diagnostics\EvidenceReferenceBaselineService;
use App\Election\Lifecycle\CeremonyActions;
use App\Election\Lifecycle\ElectionRunType;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Minutes\OfficialMinutesBaselineService;
use App\Election\Preparation\ActivateConfiguredPrecinct;
use App\Election\Preparation\ActivateImportedPrecinctPackage;
use App\Election\Preparation\ActivatePrecinctBallotPackage;
use App\Election\Preparation\ClcCandidateImporter;
use App\Election\Preparation\PopWorkbookImporter;
use App\Election\Preparation\PrecinctSetupService;
use App\Election\Preparation\SupplyVerificationBaselineService;
use App\Election\Printing\BallotPrinter;
use App\Election\Printing\SpoilBallot;
use App\Election\Returns\ElectionReturnApprovalService;
use App\Election\Returns\ElectionReturnCopyDistributionService;
use App\Election\Returns\ElectionReturnService;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Support\ReviewMode;
use App\Election\Transmission\DeliveryPackageService;
use App\Election\Transmission\DeliveryReceiptService;
use App\Election\Transmission\FinalBackupService;
use App\Election\Transmission\ManualHandoffService;
use App\Election\Transmission\TransmissionService;
use App\Election\Voting\BallotPayloadService;
use App\Election\Voting\PaperBallotLedger;
use App\Election\Voting\SpecialPollingIntakeService;
use InvalidArgumentException;
use RuntimeException;

final class ScenarioRunner
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CertificationService $certification,
        private readonly DeviceCertificationService $devices,
        private readonly OfficerAttestationService $attestations,
        private readonly CeremonyActions $ceremonies,
        private readonly BallotPayloadService $payloads,
        private readonly BallotPrinter $printer,
        private readonly SpoilBallot $spoil,
        private readonly CountingService $counting,
        private readonly ElectionReturnService $returns,
        private readonly LifecycleState $lifecycle,
        private readonly ActivityJournal $journal,
        private readonly PopWorkbookImporter $popImporter,
        private readonly ClcCandidateImporter $clcImporter,
        private readonly ActivateImportedPrecinctPackage $activateImportedPrecinct,
        private readonly ActivatePrecinctBallotPackage $activatePrecinctBallot,
        private readonly ActivateConfiguredPrecinct $activateConfiguredPrecinct,
        private readonly CertificationDeckBuilder $deckBuilder,
        private readonly InitializationReportService $initializationReport,
        private readonly CountingLegalEvidenceService $countingLegalEvidence,
        private readonly ManualVerificationService $manualVerification,
        private readonly DiscrepancyReportService $discrepancy,
        private readonly ZeroOutService $zeroOut,
        private readonly SealingService $sealing,
        private readonly ElectionReturnCopyDistributionService $returnCopyDistribution,
        private readonly EvidenceReferenceBaselineService $baseline,
        private readonly OfficialMinutesBaselineService $officialMinutes,
        private readonly AuditReconciliationBaselineService $auditReconciliation,
        private readonly ElectoralBoardBaselineService $electoralBoardBaseline,
        private readonly SupplyVerificationBaselineService $supplyVerificationBaseline,
        private readonly TransmissionService $transmission,
        private readonly DeliveryPackageService $deliveryPackage,
        private readonly ManualHandoffService $manualHandoff,
        private readonly DeliveryReceiptService $deliveryReceipt,
        private readonly FinalBackupService $finalBackup,
        private readonly CustodyService $custody,
        private readonly SpecialPollingIntakeService $specialPollingIntake,
        private readonly PrecinctSetupService $precinctSetup,
        private readonly CountingReconciliationService $countingReconciliation,
        private readonly ElectionReturnApprovalService $returnApproval,
        private readonly PaperBallotLedger $paperBallots,
        private readonly ApplianceRecoveryService $recovery,
        private readonly EvidenceBundleArchiveBuilder $archiveBuilder,
        private readonly EvidenceBundleArchiveVerifier $archiveVerifier,
        private readonly ReviewMode $reviewMode,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(string $name): array
    {
        $this->storage->selectRunType(ElectionRunType::Rehearsal);
        $this->storage->reset(ElectionRunType::Rehearsal);
        $this->clock->freeze('2026-05-08 08:00:00');
        $this->storage->startRun(
            $name,
            $this->scenarioPrecinct($name),
            $this->clock->now()->format('Ymd-His'),
            ElectionRunType::Rehearsal,
            'scenario-runner',
        );

        $report = match ($name) {
            'friday-certification' => $this->fridayCertification(),
            'full-demo' => $this->fullDemo(),
            'evidence-folder-demo' => $this->evidenceFolderDemo(),
            'pop-import-demo' => $this->popImportDemo(),
            'supply-verification-baseline' => $this->supplyVerificationBaselineScenario(),
            'eb-role-baseline' => $this->electoralBoardBaselineScenario(),
            'initialization-report' => $this->initializationReportScenario(),
            'open-polls-initialization-report' => $this->openPollsInitializationScenario(),
            'legal-suite' => $this->legalSuite(),
            'fts-manual-verification-discrepancy' => $this->manualVerificationDiscrepancyScenario(),
            'fts-zero-out' => $this->zeroOutScenario(),
            'voting-legal-edge-cases' => $this->votingLegalEdgeCasesScenario(),
            'special-polling-intake' => $this->specialPollingIntakeScenario(),
            'close-polls-and-counting-legal-evidence' => $this->closePollsAndCountingLegalEvidenceScenario(),
            'election-return-legal-artifact' => $this->electionReturnLegalArtifactScenario(),
            'election-return-copy-distribution' => $this->electionReturnCopyDistributionScenario(),
            'delivery-package' => $this->deliveryPackageScenario(),
            'delivery-receipt' => $this->deliveryReceiptScenario(),
            'manual-handoff' => $this->manualHandoffScenario(),
            'final-backup' => $this->finalBackupScenario(),
            'custody-turnover' => $this->custodyTurnoverScenario(),
            'audit-reconciliation-baseline' => $this->auditReconciliationBaselineScenario(),
            'field-50-ballots' => $this->fieldFiftyBallotsScenario(),
            default => throw new InvalidArgumentException("Unknown scenario [{$name}]."),
        };

        $report['review_mode'] = $this->reviewMode->reportContext();

        if ($name === 'legal-suite') {
            $baseline = $this->baseline->write();
            $minutesBaseline = $this->officialMinutes->write();
            $roleBaseline = $this->electoralBoardBaseline->write();

            $run['evidence_reference_baseline_path'] = $baseline['artifact_path'];
            $run['evidence_reference_baseline_hash'] = $baseline['baseline_hash'] ?? null;
            $report['evidence_reference_baseline'] = [
                'artifact_path' => $baseline['artifact_path'],
                'artifact_reference_count' => $baseline['artifact_reference_count'] ?? 0,
                'missing_required_reference_count' => $baseline['missing_required_reference_count'] ?? 0,
                'baseline_hash' => $baseline['baseline_hash'] ?? null,
            ];

            $run['official_minutes_baseline_path'] = $minutesBaseline['artifact_path'];
            $run['official_minutes_baseline_hash'] = $minutesBaseline['official_minute_hash'] ?? null;
            $report['official_minutes_baseline'] = [
                'artifact_path' => $minutesBaseline['artifact_path'],
                'minute_count' => $minutesBaseline['minute_count'] ?? 0,
                'source_journal_event_count' => $minutesBaseline['source_journal_event_count'] ?? 0,
                'source_attestation_count' => $minutesBaseline['source_attestation_count'] ?? 0,
                'official_minute_hash' => $minutesBaseline['official_minute_hash'] ?? null,
            ];

            $run['electoral_board_baseline_path'] = $roleBaseline['artifact_path'];
            $run['electoral_board_baseline_hash'] = $roleBaseline['baseline_hash'] ?? null;
            $report['electoral_board_baseline'] = [
                'artifact_path' => $roleBaseline['artifact_path'],
                'required_role_count' => $roleBaseline['required_role_count'] ?? 0,
                'required_roles_present' => $roleBaseline['required_roles_present'] ?? 0,
                'missing_required_role_count' => $roleBaseline['missing_required_role_count'] ?? 0,
                'passed' => $roleBaseline['passed'] ?? false,
                'baseline_hash' => $roleBaseline['baseline_hash'] ?? null,
            ];
        }

        $archivePath = $this->storage->writeScenarioReport($name, $report, $this->clock->now()->format('Y-m-d-His'));
        $run = $this->storage->finalizeRun($name, $report);

        $this->storage->writeJson("scenarios/{$name}-report.json", $report);
        $this->clock->unfreeze();

        return [
            ...$report,
            ...$run,
            'archived_report_path' => $archivePath,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fridayCertification(): array
    {
        $activation = $this->activateConfiguredPrecinctBallot();
        $configuration = $activation['configuration'];
        $this->clock->tick();
        $devices = $this->devices->run();
        $certification = $this->certification->run();
        $manualVerification = $this->manualVerification->run([
            'schema_version' => 'manual-return-1',
            'precinct_id' => $configuration['precinct_id'] ?? null,
            'accepted_ballots' => $certification['accepted_ballots'] ?? 0,
            'rejected_ballots' => $certification['rejected_ballots'] ?? 0,
            'tally' => $certification['actual_tally'] ?? [],
        ]);
        $attestation = $this->attest('Certification', Lifecycle::Certification, 'Certification officer review complete.');

        return [
            'scenario' => 'friday-certification',
            'passed' => $devices['passed'] && $certification['passed'] && $manualVerification['passed'],
            'precinct_id' => $configuration['precinct_id'],
            'pop_import' => $activation['pop_import'],
            'ballot_definition' => $activation['ballot_definition'],
            'device_report_hash' => $devices['report_hash'],
            'report_hash' => $certification['report_hash'],
            'manual_verification_report_hash' => $manualVerification['report_hash'],
            'manual_verification_passed' => $manualVerification['passed'],
            'attestation_hash' => $attestation['attestation_hash'],
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fullDemo(): array
    {
        $activation = $this->activateConfiguredPrecinctBallot();
        $configuration = $activation['configuration'];
        $this->clock->tick();
        $devices = $this->devices->run();
        $certification = $this->certification->run();
        $certificationAttestation = $this->attest('Certification', Lifecycle::Certification, 'Certification officer review complete.');
        $this->lifecycle->set(Lifecycle::OpenPrecinct);
        $this->ceremonies->openPolls();
        $this->ceremonies->openPolls();

        $payload = $this->payloads->finalize($this->deckBuilder->selections($configuration), 'demo-ballot-001');
        $printJob = $this->printer->print($payload);

        $spoiledPayload = $this->payloads->finalize($this->deckBuilder->selections($configuration, 1), 'demo-ballot-spoiled');
        $this->printer->print($spoiledPayload);
        $this->spoil->handle($spoiledPayload['payload_hash']);

        $this->ceremonies->closePolls();
        $this->ceremonies->startCounting();
        $accepted = $this->counting->accept($payload['qr_payload']);
        $rejected = $this->counting->accept($spoiledPayload['qr_payload']);
        $tally = $this->counting->tally();
        $this->ceremonies->moveToReturns();
        $return = $this->returns->generate($tally);
        $returnAttestation = $this->attest('Election Return', Lifecycle::ElectionReturn, 'Return officer review complete.');
        $this->ceremonies->moveToTransmission();
        $this->ceremonies->completeTransmission();
        $this->ceremonies->recordCustody();
        $this->ceremonies->closePrecinct();

        return [
            'scenario' => 'full-demo',
            'passed' => $devices['passed'] && $certification['passed'] && $accepted['status'] === 'accepted' && $rejected['status'] === 'rejected',
            'precinct_id' => $configuration['precinct_id'],
            'pop_import' => $activation['pop_import'],
            'ballot_definition' => $activation['ballot_definition'],
            'device_report_hash' => $devices['report_hash'],
            'print_job' => $printJob,
            'accepted_ballots' => $tally['accepted_ballots'],
            'rejected_ballots' => $tally['rejected_ballots'],
            'return_hash' => $return['return_hash'],
            'attestation_hashes' => [
                $certificationAttestation['attestation_hash'],
                $returnAttestation['attestation_hash'],
            ],
            'stage' => $this->lifecycle->current(),
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function legalSuite(): array
    {
        $fullDemo = $this->fullDemo();

        return [
            ...$fullDemo,
            'scenario' => 'legal-suite',
            'suite' => 'legal',
            'harness_stages' => [
                'life_cycle' => 'lifecycle+certification+voting+returns+transmission+custody',
                'scope' => 'legal baseline',
            ],
            'sub_scenarios' => [
                'friday-certification',
                'full-demo',
                'eb-role-baseline',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function supplyVerificationBaselineScenario(): array
    {
        $activation = $this->activateConfiguredPrecinctBallot();
        $this->clock->tick();
        $devices = $this->devices->run();
        $baseline = $this->supplyVerificationBaseline->write();

        return [
            'scenario' => 'supply-verification-baseline',
            'passed' => (bool) ($baseline['passed'] ?? false),
            'run_id' => $baseline['run_id'] ?? null,
            'precinct_id' => $baseline['precinct_id'] ?? $activation['configuration']['precinct_id'] ?? null,
            'required_supply_count' => $baseline['required_supply_count'] ?? 0,
            'required_supplies_present' => $baseline['required_supplies_present'] ?? 0,
            'required_supply_missing_count' => $baseline['required_supply_missing_count'] ?? 0,
            'optional_supply_count' => $baseline['optional_supply_count'] ?? 0,
            'supplies' => $baseline['supplies'] ?? [],
            'ballot_definition' => $activation['ballot_definition'] ?? null,
            'artifact_path' => $baseline['artifact_path'] ?? null,
            'baseline_hash' => $baseline['baseline_hash'] ?? null,
            'device_report_hash' => $devices['report_hash'] ?? null,
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function openPollsInitializationScenario(): array
    {
        $activation = $this->activateConfiguredPrecinctBallot();
        $this->clock->tick();
        $this->devices->run();
        $this->lifecycle->set(Lifecycle::OpenPrecinct);
        $initialization = $this->initializationReport->write('opening/initialization-report.json');

        $this->ceremonies->openPolls();

        return [
            'scenario' => 'open-polls-initialization-report',
            'passed' => (bool) ($initialization['passed'] ?? false),
            'run_id' => $initialization['run_id'] ?? $this->storage->currentRun()['run_id'] ?? null,
            'precinct_id' => $initialization['precinct_id'] ?? $activation['configuration']['precinct_id'] ?? null,
            'checks_passed' => collect($initialization['checks'] ?? [])
                ->filter(fn (array $check): bool => (bool) ($check['passed'] ?? false))
                ->count(),
            'checks_total' => count($initialization['checks'] ?? []),
            'stage_after_open' => $this->lifecycle->current(),
            'artifact_path' => $initialization['artifact_path'] ?? null,
            'report_hash' => $initialization['report_hash'] ?? null,
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function initializationReportScenario(): array
    {
        $activation = $this->activateConfiguredPrecinctBallot();
        $this->clock->tick();
        $devices = $this->devices->run();
        $initialization = $this->initializationReport->write();
        $storage = $this->storage->currentRun();

        return [
            'scenario' => 'initialization-report',
            'passed' => (bool) ($initialization['passed'] ?? false) && (bool) ($devices['passed'] ?? false),
            'run_id' => $initialization['run_id'] ?? $storage['run_id'] ?? null,
            'precinct_id' => $initialization['precinct_id'] ?? $activation['configuration']['precinct_id'] ?? null,
            'report_hash' => $initialization['report_hash'] ?? null,
            'device_report_hash' => $devices['report_hash'] ?? null,
            'artifact_path' => $initialization['artifact_path'] ?? null,
            'generated_at' => $initialization['generated_at'] ?? null,
            'checks_passed' => collect($initialization['checks'] ?? [])
                ->filter(fn (array $check): bool => (bool) ($check['passed'] ?? false))
                ->count(),
            'checks_total' => count($initialization['checks'] ?? []),
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function electoralBoardBaselineScenario(): array
    {
        $baseline = $this->electoralBoardBaseline->write();

        return [
            'scenario' => 'eb-role-baseline',
            'passed' => (bool) ($baseline['passed'] ?? false),
            'run_id' => $baseline['run_id'] ?? null,
            'precinct_id' => $baseline['precinct_id'] ?? null,
            'required_role_count' => $baseline['required_role_count'] ?? 0,
            'required_roles_present' => $baseline['required_roles_present'] ?? 0,
            'missing_required_role_count' => $baseline['missing_required_role_count'] ?? 0,
            'required_roles' => $baseline['required_roles'] ?? [],
            'baseline_hash' => $baseline['baseline_hash'] ?? null,
            'artifact_path' => $baseline['artifact_path'] ?? null,
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function evidenceFolderDemo(): array
    {
        return [
            ...$this->fullDemo(),
            'scenario' => 'evidence-folder-demo',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function popImportDemo(): array
    {
        $sourcePath = $this->popSourcePath();
        $profile = $this->popProfile();
        $clusteredPrecinct = $this->popClusteredPrecinct();
        $manifest = $this->popImporter->import($sourcePath, $profile);
        $package = $this->activateImportedPrecinct->handle($clusteredPrecinct);
        $report = $this->popReport($manifest, $package, $sourcePath, $profile, $clusteredPrecinct);

        return [
            'scenario' => 'pop-import-demo',
            'passed' => $manifest['row_count'] === 93629
                && $manifest['unique_clustered_precinct_count'] === 93629
                && $package['precinct_id'] === $clusteredPrecinct,
            'precinct_id' => $package['precinct_id'],
            'pop_import' => $report,
            'registry_version' => $manifest['registry_version'],
            'row_count' => $manifest['row_count'],
            'unique_clustered_precinct_count' => $manifest['unique_clustered_precinct_count'],
            'total_registered_voters' => $manifest['total_registered_voters'],
            'registry_hash' => $manifest['registry_hash'],
            'manifest_hash' => $manifest['manifest_hash'],
            'manifest_path' => $manifest['artifact_path'],
            'package_hash' => $package['package_hash'],
            'package_path' => $package['artifact_path'],
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manualVerificationDiscrepancyScenario(): array
    {
        $activation = $this->activateConfiguredPrecinctBallot();
        $this->clock->tick();
        $devices = $this->devices->run();
        $certification = $this->certification->run();

        $manualReturn = [
            'schema_version' => 'manual-return-1',
            'precinct_id' => $certification['precinct_id'] ?? null,
            'accepted_ballots' => ((int) ($certification['accepted_ballots'] ?? 0)) + 1,
            'rejected_ballots' => (int) ($certification['rejected_ballots'] ?? 0),
            'tally' => $certification['actual_tally'] ?? [],
        ];

        $manualVerification = $this->manualVerification->run($manualReturn);
        $discrepancy = $this->discrepancy->run();

        return [
            'scenario' => 'fts-manual-verification-discrepancy',
            'passed' => (bool) ($discrepancy['discrepancy_detected'] ?? false)
                && ! (bool) ($discrepancy['passed'] ?? true),
            'run_id' => $discrepancy['run_id'] ?? null,
            'precinct_id' => $discrepancy['precinct_id'] ?? $activation['configuration']['precinct_id'] ?? null,
            'device_report_hash' => $devices['report_hash'] ?? null,
            'certification_report_hash' => $certification['report_hash'] ?? null,
            'manual_verification_report_hash' => $manualVerification['report_hash'] ?? null,
            'discrepancy_report_hash' => $discrepancy['report_hash'] ?? null,
            'discrepancy_detected' => $discrepancy['discrepancy_detected'] ?? false,
            'official_minutes_path' => $discrepancy['official_minutes_path'] ?? null,
            'official_minutes_hash' => $discrepancy['official_minutes_hash'] ?? null,
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function zeroOutScenario(): array
    {
        $activation = $this->activateConfiguredPrecinctBallot();
        $this->clock->tick();
        $devices = $this->devices->run();
        $this->initializationReport->write();
        $certification = $this->certification->run();

        $manualVerification = $this->manualVerification->run([
            'schema_version' => 'manual-return-1',
            'precinct_id' => $certification['precinct_id'] ?? null,
            'accepted_ballots' => $certification['accepted_ballots'] ?? 0,
            'rejected_ballots' => $certification['rejected_ballots'] ?? 0,
            'tally' => $certification['actual_tally'] ?? [],
        ]);
        $discrepancy = $this->discrepancy->run();
        $zeroOut = $this->zeroOut->run();
        $sealing = $this->sealing->run();

        return [
            'scenario' => 'fts-zero-out',
            'passed' => (bool) ($discrepancy['passed'] ?? false) && (bool) ($zeroOut['passed'] ?? false) && (bool) ($sealing['passed'] ?? false),
            'run_id' => $sealing['run_id'] ?? null,
            'precinct_id' => $sealing['precinct_id'] ?? $activation['configuration']['precinct_id'] ?? null,
            'device_report_hash' => $devices['report_hash'] ?? null,
            'certification_report_hash' => $certification['report_hash'] ?? null,
            'manual_verification_report_hash' => $manualVerification['report_hash'] ?? null,
            'discrepancy_report_hash' => $discrepancy['report_hash'] ?? null,
            'zero_out_report_hash' => $zeroOut['report_hash'] ?? null,
            'sealing_report_hash' => $sealing['report_hash'] ?? null,
            'discrepancy_detected' => $discrepancy['discrepancy_detected'] ?? null,
            'zero_out_passed' => $zeroOut['passed'] ?? false,
            'sealing_passed' => $sealing['passed'] ?? false,
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function closePollsAndCountingLegalEvidenceScenario(): array
    {
        $activation = $this->activateConfiguredPrecinctBallot();
        $this->clock->tick();
        $devices = $this->devices->run();

        $this->lifecycle->set(Lifecycle::OpenPrecinct);
        $this->ceremonies->openPolls('Scenario Officer');
        $this->ceremonies->openPolls('Scenario Officer');

        $acceptedPayload = $this->payloads->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'scenario-close-and-count-accepted');

        $rejectedPayload = $this->payloads->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'scenario-close-and-count-rejected');
        $this->spoil->handle($rejectedPayload['payload_hash']);

        $this->ceremonies->closePolls('Scenario Officer');
        $closeEvidence = $this->countingLegalEvidence->writeForClosePolls();
        $this->ceremonies->startCounting();

        $this->counting->accept($acceptedPayload['qr_payload']);
        $this->counting->accept($rejectedPayload['qr_payload']);
        $this->countingReconciliation->recordPhysicalCount(2, 'SIM-OFFICER-001', '123456');
        $this->countingReconciliation->adjudicate(
            1,
            'excluded-paper-ballot',
            'Spoiled paper ballot excluded by the Election Board.',
            'SIM-OFFICER-001',
            '123456',
        );
        $tally = $this->counting->tally();
        $countingEvidence = $this->countingLegalEvidence->writeForCompletion($tally);

        return [
            'scenario' => 'close-polls-and-counting-legal-evidence',
            'passed' => (bool) ($closeEvidence['evidence_hash'] ?? false) && (bool) ($countingEvidence['evidence_hash'] ?? false),
            'run_id' => $countingEvidence['run_id'] ?? null,
            'precinct_id' => $activation['configuration']['precinct_id'] ?? null,
            'device_report_hash' => $devices['report_hash'] ?? null,
            'close_polls_evidence_path' => $closeEvidence['artifact_path'] ?? null,
            'close_polls_evidence_hash' => $closeEvidence['evidence_hash'] ?? null,
            'counting_evidence_path' => $countingEvidence['artifact_path'] ?? null,
            'counting_evidence_hash' => $countingEvidence['evidence_hash'] ?? null,
            'accepted_ballots_counted' => $countingEvidence['accepted_ballots'] ?? 0,
            'rejected_ballots_counted' => $countingEvidence['rejected_ballots'] ?? 0,
            'stage_after_completion' => $this->lifecycle->current(),
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function electionReturnLegalArtifactScenario(): array
    {
        $activation = $this->activateConfiguredPrecinctBallot();
        $this->clock->tick();
        $devices = $this->devices->run();

        $this->lifecycle->set(Lifecycle::OpenPrecinct);
        $this->ceremonies->openPolls('Scenario Officer');
        $this->ceremonies->openPolls('Scenario Officer');

        $acceptedPayload = $this->payloads->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'scenario-return-legal-accepted');

        $rejectedPayload = $this->payloads->finalize([
            'president' => ['pres-sarah'],
            'mayor' => ['mayor-jose'],
            'council' => ['council-ben'],
        ], 'scenario-return-legal-rejected');
        $this->spoil->handle($rejectedPayload['payload_hash']);

        $this->ceremonies->closePolls('Scenario Officer');
        $this->ceremonies->startCounting();
        $this->counting->accept($acceptedPayload['qr_payload']);
        $this->counting->accept($rejectedPayload['qr_payload']);
        $tally = $this->counting->tally();

        $this->ceremonies->moveToReturns();
        $return = $this->returns->generate($tally);
        $legalEvidence = $this->storage->readJson('returns/election-return-legal-evidence.json');

        return [
            'scenario' => 'election-return-legal-artifact',
            'passed' => (bool) ($legalEvidence['evidence_hash'] ?? false) && $return['return_hash'] === ($legalEvidence['return_hash'] ?? null),
            'run_id' => $legalEvidence['run_id'] ?? null,
            'precinct_id' => $activation['configuration']['precinct_id'] ?? $legalEvidence['precinct_id'] ?? null,
            'return_hash' => $return['return_hash'],
            'return_path' => $legalEvidence['return_path'] ?? null,
            'election_return_legal_evidence_path' => $legalEvidence['artifact_path'] ?? null,
            'election_return_legal_evidence_hash' => $legalEvidence['evidence_hash'] ?? null,
            'election_return_legal_artifact_path' => $legalEvidence['artifact_path'] ?? null,
            'election_return_legal_artifact_hash' => $legalEvidence['evidence_hash'] ?? null,
            'counts_match' => $legalEvidence['counts_match'] ?? false,
            'accepted_ballots' => $legalEvidence['accepted_ballots'] ?? 0,
            'rejected_ballots' => $legalEvidence['rejected_ballots'] ?? 0,
            'tally_hash' => $legalEvidence['tally_hash'] ?? null,
            'device_report_hash' => $devices['report_hash'] ?? null,
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function electionReturnCopyDistributionScenario(): array
    {
        $activation = $this->activateConfiguredPrecinctBallot();
        $this->clock->tick();
        $devices = $this->devices->run();

        $this->lifecycle->set(Lifecycle::OpenPrecinct);
        $this->ceremonies->openPolls('Scenario Officer');
        $this->ceremonies->openPolls('Scenario Officer');

        $acceptedPayload = $this->payloads->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'scenario-copy-distribution-accepted');

        $rejectedPayload = $this->payloads->finalize([
            'president' => ['pres-sarah'],
            'mayor' => ['mayor-jose'],
            'council' => ['council-ben'],
        ], 'scenario-copy-distribution-rejected');
        $this->spoil->handle($rejectedPayload['payload_hash']);

        $this->ceremonies->closePolls('Scenario Officer');
        $this->ceremonies->startCounting();
        $this->counting->accept($acceptedPayload['qr_payload']);
        $this->counting->accept($rejectedPayload['qr_payload']);
        $tally = $this->counting->tally();

        $this->ceremonies->moveToReturns();
        $return = $this->returns->generate($tally);
        $copyDistribution = $this->returnCopyDistribution->prepare($return);
        $this->approveElectionReturn();

        return [
            'scenario' => 'election-return-copy-distribution',
            'passed' => (bool) ($copyDistribution['distribution_hash'] ?? false),
            'run_id' => $copyDistribution['run_id'] ?? null,
            'precinct_id' => $activation['configuration']['precinct_id'] ?? $copyDistribution['precinct_id'] ?? null,
            'return_hash' => $return['return_hash'],
            'return_hash_in_distribution' => $copyDistribution['return_hash'] ?? null,
            'copy_distribution_artifact_path' => $copyDistribution['artifact_path'] ?? null,
            'copy_distribution_hash' => $copyDistribution['distribution_hash'] ?? null,
            'copy_count' => $copyDistribution['copy_count'] ?? 0,
            'required_copy_count' => $copyDistribution['required_copy_count'] ?? 0,
            'distribution_posting_status' => $copyDistribution['posting']['status'] ?? null,
            'device_report_hash' => $devices['report_hash'] ?? null,
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryPackageScenario(): array
    {
        $activation = $this->activateConfiguredPrecinctBallot();
        $this->clock->tick();
        $devices = $this->devices->run();
        $certification = $this->certification->run();
        $this->lifecycle->set(Lifecycle::OpenPrecinct);

        $this->ceremonies->openPolls('Scenario Officer');
        $this->ceremonies->openPolls('Scenario Officer');

        $acceptedPayload = $this->payloads->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'scenario-delivery-accepted');

        $rejectedPayload = $this->payloads->finalize([
            'president' => ['pres-sarah'],
            'mayor' => ['mayor-jose'],
            'council' => ['council-ben'],
        ], 'scenario-delivery-rejected');
        $this->spoil->handle($rejectedPayload['payload_hash']);

        $this->ceremonies->closePolls('Scenario Officer');
        $this->ceremonies->startCounting();
        $this->counting->accept($acceptedPayload['qr_payload']);
        $this->counting->accept($rejectedPayload['qr_payload']);
        $tally = $this->counting->tally();

        $this->ceremonies->moveToReturns();
        $return = $this->returns->generate($tally);
        $copyDistribution = $this->returnCopyDistribution->prepare($return);
        $this->approveElectionReturn();
        $this->ceremonies->moveToTransmission();
        $transmission = $this->transmission->run();
        $this->ceremonies->completeTransmission();
        $package = $this->deliveryPackage->prepare($transmission);

        return [
            'scenario' => 'delivery-package',
            'passed' => (bool) ($package['required_artifacts_present'] ?? false),
            'run_id' => $this->storage->currentRun()['run_id'] ?? null,
            'precinct_id' => $activation['configuration']['precinct_id'] ?? $package['precinct_id'] ?? null,
            'certification_report_hash' => $certification['report_hash'] ?? null,
            'device_report_hash' => $devices['report_hash'] ?? null,
            'return_hash' => $return['return_hash'] ?? null,
            'accepted_ballots' => $tally['accepted_ballots'] ?? 0,
            'rejected_ballots' => $tally['rejected_ballots'] ?? 0,
            'distribution_hash' => $copyDistribution['distribution_hash'] ?? null,
            'transmission_id' => $transmission['transmission_id'] ?? null,
            'transmission_hash' => $transmission['transmission_hash'] ?? null,
            'delivery_package_path' => $package['artifact_path'] ?? null,
            'delivery_package_hash' => $package['delivery_package_hash'] ?? null,
            'required_artifacts_present' => $package['required_artifacts_present'] ?? false,
            'artifact_count' => $package['artifact_count'] ?? 0,
            'package_id' => $package['package_id'] ?? null,
            'stage_after_transmission' => $this->lifecycle->current(),
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manualHandoffScenario(): array
    {
        $activation = $this->activateConfiguredPrecinctBallot();
        $this->clock->tick();
        $devices = $this->devices->run();
        $certification = $this->certification->run();
        $this->lifecycle->set(Lifecycle::OpenPrecinct);

        $this->ceremonies->openPolls('Simulation Officer');
        $this->ceremonies->openPolls('Simulation Officer');

        $acceptedPayload = $this->payloads->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'scenario-manual-handoff-accepted');

        $rejectedPayload = $this->payloads->finalize([
            'president' => ['pres-sarah'],
            'mayor' => ['mayor-jose'],
            'council' => ['council-ben'],
        ], 'scenario-manual-handoff-rejected');
        $this->spoil->handle($rejectedPayload['payload_hash']);

        $this->ceremonies->closePolls('Simulation Officer');
        $this->ceremonies->startCounting();
        $this->counting->accept($acceptedPayload['qr_payload']);
        $this->counting->accept($rejectedPayload['qr_payload']);
        $tally = $this->counting->tally();

        $this->ceremonies->moveToReturns();
        $return = $this->returns->generate($tally);
        $this->returnCopyDistribution->prepare($return);
        $this->approveElectionReturn();
        $this->ceremonies->moveToTransmission();
        $transmission = $this->transmission->run();
        $package = $this->deliveryPackage->prepare($transmission);

        $officerVerification = $this->manualHandoff->verifyOfficer([
            'officer_code' => 'SIM-OFFICER-001',
            'officer_pin' => '123456',
            'verification_note' => 'Manual handoff officer verified.',
            'stage' => Lifecycle::Transmission,
        ]);

        $recipientVerification = $this->manualHandoff->verifyRecipient([
            'recipient' => 'Election Board Officer',
            'recipient_role' => 'Election Board',
            'handoff_date' => '2026-05-08',
            'handoff_time' => '12:15',
            'delivery_method' => 'manual',
            'acknowledged' => true,
            'acknowledgement_note' => 'Received package and will secure it.',
            'stage' => Lifecycle::Transmission,
        ]);

        return [
            'scenario' => 'manual-handoff',
            'passed' => true,
            'run_id' => $this->storage->currentRun()['run_id'] ?? null,
            'precinct_id' => $activation['configuration']['precinct_id'] ?? $package['precinct_id'] ?? null,
            'certification_report_hash' => $certification['report_hash'] ?? null,
            'delivery_package_hash' => $package['delivery_package_hash'] ?? null,
            'transmission_hash' => $package['transmission']['transmission_hash'] ?? null,
            'officer_verification_id' => $officerVerification['verification_id'] ?? null,
            'recipient_verification_id' => $recipientVerification['verification_id'] ?? null,
            'officer_verification_hash' => $officerVerification['verification_hash'] ?? null,
            'recipient_verification_hash' => $recipientVerification['verification_hash'] ?? null,
            'officer_verification_path' => $officerVerification['artifact_path'] ?? null,
            'recipient_verification_path' => $recipientVerification['artifact_path'] ?? null,
            'accepted_ballots' => $tally['accepted_ballots'] ?? 0,
            'rejected_ballots' => $tally['rejected_ballots'] ?? 0,
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryReceiptScenario(): array
    {
        $activation = $this->activateConfiguredPrecinctBallot();
        $this->clock->tick();
        $devices = $this->devices->run();
        $certification = $this->certification->run();
        $this->lifecycle->set(Lifecycle::OpenPrecinct);

        $this->ceremonies->openPolls('Scenario Officer');
        $this->ceremonies->openPolls('Scenario Officer');

        $acceptedPayload = $this->payloads->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'scenario-delivery-receipt-accepted');

        $rejectedPayload = $this->payloads->finalize([
            'president' => ['pres-sarah'],
            'mayor' => ['mayor-jose'],
            'council' => ['council-ben'],
        ], 'scenario-delivery-receipt-rejected');
        $this->spoil->handle($rejectedPayload['payload_hash']);

        $this->ceremonies->closePolls('Scenario Officer');
        $this->ceremonies->startCounting();
        $this->counting->accept($acceptedPayload['qr_payload']);
        $this->counting->accept($rejectedPayload['qr_payload']);
        $tally = $this->counting->tally();

        $this->ceremonies->moveToReturns();
        $return = $this->returns->generate($tally);
        $this->returnCopyDistribution->prepare($return);
        $this->approveElectionReturn();

        $this->ceremonies->moveToTransmission();
        $transmission = $this->transmission->run();
        $package = $this->deliveryPackage->prepare($transmission);

        $officerVerification = $this->manualHandoff->verifyOfficer([
            'officer_code' => 'SIM-OFFICER-001',
            'officer_pin' => '123456',
            'verification_note' => 'Manual handoff officer verified for receipt.',
            'stage' => Lifecycle::Transmission,
        ]);

        $recipientVerification = $this->manualHandoff->verifyRecipient([
            'recipient' => 'Election Board Officer',
            'recipient_role' => 'Election Board',
            'handoff_date' => '2026-05-08',
            'handoff_time' => '12:20',
            'delivery_method' => 'manual',
            'acknowledged' => true,
            'acknowledgement_note' => 'Delivery Receipt requested by operator.',
            'stage' => Lifecycle::Transmission,
        ]);

        $deliveryReceipt = $this->deliveryReceipt->prepare([
            'stage' => Lifecycle::Transmission,
            'delivery_note' => 'Delivery Receipt generation for scenario test.',
        ]);

        return [
            'scenario' => 'delivery-receipt',
            'passed' => true,
            'run_id' => $this->storage->currentRun()['run_id'] ?? null,
            'precinct_id' => $activation['configuration']['precinct_id'] ?? $package['precinct_id'] ?? null,
            'certification_report_hash' => $certification['report_hash'] ?? null,
            'delivery_package_hash' => $package['delivery_package_hash'] ?? null,
            'delivery_package_path' => $package['artifact_path'] ?? null,
            'transmission_hash' => $package['transmission']['transmission_hash'] ?? null,
            'delivery_receipt_id' => $deliveryReceipt['delivery_receipt_id'] ?? null,
            'delivery_receipt_hash' => $deliveryReceipt['delivery_receipt_hash'] ?? null,
            'delivery_receipt_path' => $deliveryReceipt['artifact_path'] ?? null,
            'officer_verification_id' => $officerVerification['verification_id'] ?? null,
            'recipient_verification_id' => $recipientVerification['verification_id'] ?? null,
            'officer_verification_path' => $officerVerification['artifact_path'] ?? null,
            'recipient_verification_path' => $recipientVerification['artifact_path'] ?? null,
            'delivery_driver' => $deliveryReceipt['delivery_driver'] ?? null,
            'lifecycle_stage_after_receipt' => $this->lifecycle->current(),
            'accepted_ballots' => $tally['accepted_ballots'] ?? 0,
            'rejected_ballots' => $tally['rejected_ballots'] ?? 0,
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function finalBackupScenario(): array
    {
        $activation = $this->activateConfiguredPrecinctBallot();
        $this->clock->tick();
        $devices = $this->devices->run();
        $certification = $this->certification->run();
        $this->lifecycle->set(Lifecycle::OpenPrecinct);

        $this->ceremonies->openPolls('Scenario Officer');
        $this->ceremonies->openPolls('Scenario Officer');

        $acceptedPayload = $this->payloads->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'scenario-final-backup-accepted');

        $rejectedPayload = $this->payloads->finalize([
            'president' => ['pres-sarah'],
            'mayor' => ['mayor-jose'],
            'council' => ['council-ben'],
        ], 'scenario-final-backup-rejected');
        $this->spoil->handle($rejectedPayload['payload_hash']);

        $this->ceremonies->closePolls('Scenario Officer');
        $this->ceremonies->startCounting();
        $this->counting->accept($acceptedPayload['qr_payload']);
        $this->counting->accept($rejectedPayload['qr_payload']);
        $tally = $this->counting->tally();

        $this->ceremonies->moveToReturns();
        $return = $this->returns->generate($tally);
        $this->returnCopyDistribution->prepare($return);
        $this->approveElectionReturn();

        $this->ceremonies->moveToTransmission();
        $transmission = $this->transmission->run();
        $package = $this->deliveryPackage->prepare($transmission);

        $officerVerification = $this->manualHandoff->verifyOfficer([
            'officer_code' => 'SIM-OFFICER-001',
            'officer_pin' => '123456',
            'verification_note' => 'Manual handoff officer verified for final backup.',
            'stage' => Lifecycle::Transmission,
        ]);

        $recipientVerification = $this->manualHandoff->verifyRecipient([
            'recipient' => 'Election Board Officer',
            'recipient_role' => 'Election Board',
            'handoff_date' => '2026-05-08',
            'handoff_time' => '13:05',
            'delivery_method' => 'manual',
            'acknowledged' => true,
            'acknowledgement_note' => 'Ready for final backup verification.',
            'stage' => Lifecycle::Transmission,
        ]);

        $deliveryReceipt = $this->deliveryReceipt->prepare([
            'stage' => Lifecycle::Transmission,
            'delivery_note' => 'Delivery Receipt for final backup scenario.',
        ]);

        $finalBackup = $this->finalBackup->perform([
            'stage' => Lifecycle::FinalBackup,
            'backup_media' => 'local-storage',
            'backup_type' => 'local-storage',
            'backup_note' => 'Slice 20 deterministic final backup.',
        ]);

        return [
            'scenario' => 'final-backup',
            'passed' => true,
            'run_id' => $this->storage->currentRun()['run_id'] ?? null,
            'precinct_id' => $activation['configuration']['precinct_id'] ?? $package['precinct_id'] ?? null,
            'certification_report_hash' => $certification['report_hash'] ?? null,
            'delivery_package_hash' => $package['delivery_package_hash'] ?? null,
            'delivery_package_path' => $package['artifact_path'] ?? null,
            'delivery_receipt_id' => $deliveryReceipt['delivery_receipt_id'] ?? null,
            'delivery_receipt_hash' => $deliveryReceipt['delivery_receipt_hash'] ?? null,
            'delivery_receipt_path' => $deliveryReceipt['artifact_path'] ?? null,
            'final_backup_id' => $finalBackup['backup_id'] ?? null,
            'final_backup_hash' => $finalBackup['final_backup_hash'] ?? null,
            'final_backup_path' => $finalBackup['artifact_path'] ?? null,
            'final_backup_manifest_path' => $finalBackup['evidence_manifest_path'] ?? null,
            'officer_verification_id' => $officerVerification['verification_id'] ?? null,
            'recipient_verification_id' => $recipientVerification['verification_id'] ?? null,
            'officer_verification_path' => $officerVerification['artifact_path'] ?? null,
            'recipient_verification_path' => $recipientVerification['artifact_path'] ?? null,
            'transmission_id' => $transmission['transmission_id'] ?? null,
            'transmission_hash' => $transmission['transmission_hash'] ?? null,
            'transmission_path' => $transmission['artifact_path'] ?? null,
            'final_backup_stage_after' => $this->lifecycle->current(),
            'accepted_ballots' => $tally['accepted_ballots'] ?? 0,
            'rejected_ballots' => $tally['rejected_ballots'] ?? 0,
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function custodyTurnoverScenario(): array
    {
        $activation = $this->activateConfiguredPrecinctBallot();
        $this->clock->tick();
        $devices = $this->devices->run();
        $certification = $this->certification->run();
        $this->lifecycle->set(Lifecycle::OpenPrecinct);

        $this->ceremonies->openPolls('Scenario Officer');
        $this->ceremonies->openPolls('Scenario Officer');

        $acceptedPayload = $this->payloads->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'scenario-custody-turnover-accepted');

        $rejectedPayload = $this->payloads->finalize([
            'president' => ['pres-sarah'],
            'mayor' => ['mayor-jose'],
            'council' => ['council-ben'],
        ], 'scenario-custody-turnover-rejected');
        $this->spoil->handle($rejectedPayload['payload_hash']);

        $this->ceremonies->closePolls('Scenario Officer');
        $this->ceremonies->startCounting();
        $this->counting->accept($acceptedPayload['qr_payload']);
        $this->counting->accept($rejectedPayload['qr_payload']);
        $tally = $this->counting->tally();

        $this->ceremonies->moveToReturns();
        $return = $this->returns->generate($tally);
        $this->returnCopyDistribution->prepare($return);
        $this->approveElectionReturn();

        $this->ceremonies->moveToTransmission();
        $transmission = $this->transmission->run();
        $package = $this->deliveryPackage->prepare($transmission);

        $officerVerification = $this->manualHandoff->verifyOfficer([
            'officer_code' => 'SIM-OFFICER-001',
            'officer_pin' => '123456',
            'verification_note' => 'Manual handoff officer verified for custody turnover.',
            'stage' => Lifecycle::Transmission,
        ]);

        $recipientVerification = $this->manualHandoff->verifyRecipient([
            'recipient' => 'Election Board Officer',
            'recipient_role' => 'Election Board',
            'handoff_date' => '2026-05-08',
            'handoff_time' => '14:40',
            'delivery_method' => 'manual',
            'acknowledged' => true,
            'acknowledgement_note' => 'Custody turnover test handoff acknowledged.',
            'stage' => Lifecycle::Transmission,
        ]);

        $deliveryReceipt = $this->deliveryReceipt->prepare([
            'stage' => Lifecycle::Transmission,
            'delivery_note' => 'Delivery Receipt for custody turnover scenario.',
        ]);

        $finalBackup = $this->finalBackup->perform([
            'stage' => Lifecycle::FinalBackup,
            'backup_media' => 'local-storage',
            'backup_type' => 'local-storage',
            'backup_note' => 'Custody turnover deterministic backup.',
        ]);

        $custody = $this->custody->record();
        $this->ceremonies->recordCustody();

        $custodyTurnover = $this->custody->latestTurnoverReport();
        $custodyTurnoverPath = (string) ($custodyTurnover['artifact_path'] ?? $this->storage->path('custody/custody-turnover-report.json'));

        return [
            'scenario' => 'custody-turnover',
            'passed' => true,
            'run_id' => $this->storage->currentRun()['run_id'] ?? null,
            'precinct_id' => $activation['configuration']['precinct_id'] ?? $custody['precinct_id'] ?? null,
            'certification_report_hash' => $certification['report_hash'] ?? null,
            'device_report_hash' => $devices['report_hash'] ?? null,
            'return_hash' => $return['return_hash'] ?? null,
            'accepted_ballots' => $tally['accepted_ballots'] ?? 0,
            'rejected_ballots' => $tally['rejected_ballots'] ?? 0,
            'delivery_package_hash' => $package['delivery_package_hash'] ?? null,
            'delivery_package_path' => $package['artifact_path'] ?? null,
            'delivery_receipt_id' => $deliveryReceipt['delivery_receipt_id'] ?? null,
            'delivery_receipt_path' => $deliveryReceipt['artifact_path'] ?? null,
            'final_backup_id' => $finalBackup['backup_id'] ?? null,
            'final_backup_path' => $finalBackup['artifact_path'] ?? null,
            'custody_id' => $custody['custody_id'] ?? null,
            'custody_hash' => $custody['custody_hash'] ?? null,
            'custody_turnover_path' => $custodyTurnoverPath,
            'custody_turnover_id' => $custodyTurnover['custody_turnover_id'] ?? null,
            'custody_turnover_hash' => $custodyTurnover['custody_turnover_hash'] ?? null,
            'turnover_stage' => $custodyTurnover['turnover_stage'] ?? null,
            'turnover_artifact_count' => $custodyTurnover['artifact_count'] ?? 0,
            'officer_verification_id' => $officerVerification['verification_id'] ?? null,
            'recipient_verification_id' => $recipientVerification['verification_id'] ?? null,
            'transmission_id' => $transmission['transmission_id'] ?? null,
            'transmission_hash' => $transmission['transmission_hash'] ?? null,
            'lifecycle_stage_after_turnover' => $this->lifecycle->current(),
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditReconciliationBaselineScenario(): array
    {
        $custodyTurnover = $this->custodyTurnoverScenario();
        $reconciliation = $this->auditReconciliation->write();

        return [
            'scenario' => 'audit-reconciliation-baseline',
            'passed' => (bool) ($reconciliation['reconciliation_complete'] ?? false),
            'run_id' => $reconciliation['run_id'] ?? null,
            'precinct_id' => $reconciliation['precinct_id'] ?? null,
            'run_summary_hash' => $reconciliation['run_summary_hash'] ?? null,
            'run_artifact_index_hash' => $reconciliation['run_artifact_index_hash'] ?? null,
            'journal_sequence' => $reconciliation['journal_sequence'] ?? 0,
            'journal_entries_after_reconciliation' => count($this->journal->entries()),
            'checks' => $reconciliation['checks'] ?? [],
            'checks_total' => $reconciliation['checks_total'] ?? 0,
            'checks_passed' => $reconciliation['checks_passed'] ?? 0,
            'reconciliation_ready' => $reconciliation['reconciliation_ready'] ?? false,
            'reconciliation_complete' => $reconciliation['reconciliation_complete'] ?? false,
            'artifacts_found' => $reconciliation['artifacts_found'] ?? 0,
            'artifacts_expected' => $reconciliation['artifacts_expected'] ?? 0,
            'artifact_catalog_count' => $reconciliation['artifact_catalog_count'] ?? 0,
            'custody_turnover_path' => $custodyTurnover['custody_turnover_path'] ?? null,
            'delivery_package_path' => $custodyTurnover['delivery_package_path'] ?? null,
            'delivery_receipt_path' => $custodyTurnover['delivery_receipt_path'] ?? null,
            'final_backup_path' => $custodyTurnover['final_backup_path'] ?? null,
            'audit_reconciliation_hash' => $reconciliation['audit_reconciliation_hash'] ?? null,
            'artifact_path' => $reconciliation['artifact_path'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldFiftyBallotsScenario(): array
    {
        $activation = $this->activateConfiguredPrecinctBallot();
        $configuration = $activation['configuration'];
        $this->clock->tick();

        $devices = $this->devices->run();
        $initialization = $this->initializationReport->write();
        $certification = $this->certification->run();
        $manualVerification = $this->manualVerification->run([
            'schema_version' => 'manual-return-1',
            'precinct_id' => $configuration['precinct_id'],
            'accepted_ballots' => $certification['accepted_ballots'],
            'rejected_ballots' => $certification['rejected_ballots'],
            'tally' => $certification['actual_tally'],
        ]);
        $discrepancy = $this->discrepancy->run();
        $zeroOut = $this->zeroOut->run();
        $sealing = $this->sealing->run();
        $certificationAttestation = $this->attest(
            'Final Testing and Sealing',
            Lifecycle::Certification,
            'The configured precinct package passed deterministic final testing and sealing.',
        );

        $this->lifecycle->set(Lifecycle::OpenPrecinct);
        $this->ceremonies->openPolls('Simulation Election Board Chairperson');
        $this->ceremonies->openPolls('Simulation Poll Clerk');

        $acceptedPayloads = [];
        $spoiledPayloads = [];
        $restartRecovery = [];

        for ($voter = 1; $voter <= 50; $voter++) {
            $selections = $this->deckBuilder->selections($configuration, ($voter - 1) % 20);

            if (in_array($voter, [7, 31], true)) {
                $spoiledPayload = $this->payloads->finalize(
                    $selections,
                    sprintf('field-voter-%03d-spoiled', $voter),
                );
                $this->printer->print($spoiledPayload);
                $this->spoil->handle(
                    $spoiledPayload['payload_hash'],
                    'Voter requested a replacement after reviewing the printed ballot.',
                );
                $spoiledPayloads[] = $spoiledPayload;
            }

            $payload = $this->payloads->finalize(
                $selections,
                sprintf('field-ballot-%03d', $voter),
            );
            $this->printer->print($payload);
            $acceptedPayloads[] = $payload;

            if ($voter === 25) {
                $restartRecovery = $this->recovery->inspect();
            }

            $this->clock->tick();
        }

        $this->ceremonies->closePolls('Simulation Election Board Chairperson');
        $closePollsEvidence = $this->countingLegalEvidence->writeForClosePolls();
        $this->ceremonies->startCounting();

        foreach ($acceptedPayloads as $payload) {
            $this->counting->accept($payload['qr_payload']);
        }

        $duplicateScan = $this->counting->accept($acceptedPayloads[11]['qr_payload']);
        $physicalControl = $this->countingReconciliation->recordPhysicalCount(
            50,
            'SIM-OFFICER-001',
            '123456',
        );
        $duplicateAdjudication = $this->countingReconciliation->adjudicate(
            (int) $duplicateScan['sequence'],
            'duplicate-scan',
            'The Election Board confirmed that this was a repeated scan of an already accepted paper ballot.',
            'SIM-OFFICER-001',
            '123456',
        );
        $reconciliation = $this->countingReconciliation->summary();
        $tally = $this->counting->tally();
        $countingEvidence = $this->countingLegalEvidence->writeForCompletion($tally);

        $this->ceremonies->moveToReturns();
        $return = $this->returns->generate($tally);
        $copyDistribution = $this->returnCopyDistribution->prepare($return);
        $returnApproval = $this->returnApproval->approve(
            'SIM-OFFICER-001',
            '123456',
            'SIM-OFFICER-002',
            '123456',
        );
        $returnAttestation = $this->attest(
            'Election Return',
            Lifecycle::ElectionReturn,
            'The Election Board reviewed, approved, and posted the Election Return.',
        );

        $this->ceremonies->moveToTransmission();
        $transmission = $this->transmission->run();
        $deliveryPackage = $this->deliveryPackage->prepare($transmission);
        $officerVerification = $this->manualHandoff->verifyOfficer([
            'officer_code' => 'SIM-OFFICER-001',
            'officer_pin' => '123456',
            'verification_note' => 'Field rehearsal handoff officer verified.',
            'stage' => Lifecycle::Transmission,
        ]);
        $recipientVerification = $this->manualHandoff->verifyRecipient([
            'recipient' => 'City Board of Canvassers Receiving Officer',
            'recipient_role' => 'Authorized Receiving Officer',
            'handoff_date' => '2026-05-08',
            'handoff_time' => '19:30',
            'delivery_method' => 'manual',
            'acknowledged' => true,
            'acknowledgement_note' => 'The sealed precinct evidence package was received for the field rehearsal.',
            'stage' => Lifecycle::Transmission,
        ]);
        $deliveryReceipt = $this->deliveryReceipt->prepare([
            'stage' => Lifecycle::Transmission,
            'delivery_note' => 'Deterministic 50-ballot field rehearsal handoff.',
        ]);
        $finalBackup = $this->finalBackup->perform([
            'stage' => Lifecycle::FinalBackup,
            'backup_media' => 'local-storage',
            'backup_type' => 'local-storage',
            'backup_note' => 'Deterministic field rehearsal final backup.',
        ]);
        $custody = $this->custody->record();
        $this->ceremonies->recordCustody();
        $this->ceremonies->closePrecinct('Simulation Election Board Chairperson');
        $this->ceremonies->beginAudit();

        $minutes = $this->officialMinutes->write();
        $referenceBaseline = $this->baseline->write();
        $audit = $this->auditReconciliation->write();
        $archive = $this->archiveBuilder->build();
        $archiveVerification = $this->archiveVerifier->writeReport($archive['archive_path']);
        $paperBallotAccounting = $this->paperBallots->summary();

        $passed = (bool) $devices['passed']
            && (bool) $initialization['passed']
            && (bool) $certification['passed']
            && (bool) $manualVerification['passed']
            && (bool) $discrepancy['passed']
            && (bool) $zeroOut['passed']
            && (bool) $sealing['passed']
            && ($restartRecovery['resume_status'] ?? null) === 'resume-allowed'
            && $duplicateScan['status'] === 'rejected'
            && (bool) $reconciliation['passed']
            && ($tally['accepted_ballots'] ?? 0) === 50
            && ($paperBallotAccounting['issued'] ?? 0) === 52
            && ($paperBallotAccounting['spoiled'] ?? 0) === 2
            && ($paperBallotAccounting['deposited'] ?? 0) === 50
            && (bool) $paperBallotAccounting['balanced']
            && (bool) $returnApproval['passed']
            && (bool) $audit['reconciliation_complete']
            && (bool) $archiveVerification['passed']
            && $this->lifecycle->current() === Lifecycle::Audit;

        return [
            'scenario' => 'field-50-ballots',
            'passed' => $passed,
            'precinct_id' => $configuration['precinct_id'],
            'pop_import' => $activation['pop_import'],
            'ballot_definition' => $activation['ballot_definition'],
            'lifecycle_stage' => $this->lifecycle->current(),
            'statistics' => [
                'voters_served' => 50,
                'paper_ballots_issued' => $paperBallotAccounting['issued'],
                'paper_ballots_printed' => $paperBallotAccounting['printed'],
                'paper_ballots_spoiled' => $paperBallotAccounting['spoiled'],
                'paper_ballots_deposited' => $paperBallotAccounting['deposited'],
                'accepted_ballots' => $tally['accepted_ballots'],
                'rejected_scans' => $tally['rejected_ballots'],
                'adjudicated_rejections' => $reconciliation['adjudicated_rejections'],
                'physical_ballots' => $reconciliation['physical_ballots'],
                'journal_entries' => count($this->journal->entries()),
                'archive_checked_files' => $archiveVerification['checked_files'],
            ],
            'checks' => [
                'restart_resume_status' => $restartRecovery['resume_status'] ?? null,
                'paper_ballot_accounting_balanced' => $paperBallotAccounting['balanced'],
                'counting_reconciliation_passed' => $reconciliation['passed'],
                'return_dual_approval_passed' => $returnApproval['passed'],
                'audit_reconciliation_complete' => $audit['reconciliation_complete'],
                'archive_verification_passed' => $archiveVerification['passed'],
            ],
            'artifacts' => [
                'initialization_report' => $initialization['artifact_path'],
                'certification_report' => $this->storage->path('certification/friday-certification-report.json'),
                'certification_attestation' => $certificationAttestation['artifact_path'],
                'restart_recovery_report' => $restartRecovery['artifact_path'],
                'physical_ballot_control' => $physicalControl['artifact_path'],
                'duplicate_adjudication' => $duplicateAdjudication['artifact_path'],
                'counting_evidence' => $countingEvidence['artifact_path'],
                'close_polls_evidence' => $closePollsEvidence['artifact_path'],
                'tally' => $this->storage->path('runtime/tally.json'),
                'tally_sheet' => $this->storage->path('runtime/tally-sheet.pdf'),
                'election_return' => $this->storage->path("returns/{$configuration['precinct_id']}-return.pdf"),
                'copy_distribution' => $copyDistribution['artifact_path'],
                'return_approval' => $returnApproval['artifact_path'],
                'return_attestation' => $returnAttestation['artifact_path'],
                'transmission' => $transmission['artifact_path'],
                'delivery_package' => $deliveryPackage['artifact_path'],
                'officer_verification' => $officerVerification['artifact_path'],
                'recipient_verification' => $recipientVerification['artifact_path'],
                'delivery_receipt' => $deliveryReceipt['artifact_path'],
                'final_backup' => $finalBackup['artifact_path'],
                'custody' => $custody['artifact_path'],
                'official_minutes' => $minutes['artifact_path'],
                'evidence_reference_baseline' => $referenceBaseline['artifact_path'],
                'audit_reconciliation' => $audit['artifact_path'],
                'evidence_archive' => $archive['archive_path'],
                'archive_verification' => $archiveVerification['artifact_path'],
            ],
            'tally_hash' => $tally['tally_hash'],
            'return_hash' => $return['return_hash'],
            'archive_sha256' => $archive['archive_sha256'],
            'archive_verification_hash' => $archiveVerification['verification_hash'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function votingLegalEdgeCasesScenario(): array
    {
        $this->activateConfiguredPrecinctBallot();
        $this->clock->tick();

        $this->lifecycle->set(Lifecycle::Provision);

        $invalidOpenFromProvision = false;
        $invalidCloseFromOpenPolls = false;
        $invalidCloseFromClosePolls = false;
        $stageAfterValidOpen = null;
        $stageAfterClose = null;

        try {
            $this->ceremonies->openPolls();
        } catch (RuntimeException) {
            $invalidOpenFromProvision = true;
        }

        $this->lifecycle->set(Lifecycle::OpenPrecinct);
        $this->ceremonies->openPolls('Scenario Officer');
        $stageAfterValidOpen = $this->lifecycle->current();

        try {
            $this->ceremonies->closePolls('Scenario Officer');
        } catch (RuntimeException) {
            $invalidCloseFromOpenPolls = true;
        }

        $this->lifecycle->set(Lifecycle::OpenPolls);
        $this->ceremonies->openPolls('Scenario Officer');
        $this->ceremonies->closePolls('Scenario Officer');

        $stageAfterClose = $this->lifecycle->current();

        try {
            $this->ceremonies->closePolls('Scenario Officer');
        } catch (RuntimeException) {
            $invalidCloseFromClosePolls = true;
        }

        return [
            'scenario' => 'voting-legal-edge-cases',
            'passed' => $invalidOpenFromProvision && $invalidCloseFromOpenPolls && $invalidCloseFromClosePolls,
            'invalid_open_from_provision' => $invalidOpenFromProvision,
            'invalid_close_from_open_polls' => $invalidCloseFromOpenPolls,
            'invalid_close_from_close_polls' => $invalidCloseFromClosePolls,
            'stage_after_valid_open' => $stageAfterValidOpen,
            'stage_after_close' => $stageAfterClose,
            'journal_entries' => count($this->journal->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function specialPollingIntakeScenario(): array
    {
        $activation = $this->activateConfiguredPrecinctBallot();
        $devices = $this->devices->run();
        $this->clock->tick();
        $this->lifecycle->set(Lifecycle::OpenPrecinct);

        $this->ceremonies->openPolls('Scenario Officer');
        $this->ceremonies->openPolls('Scenario Officer');

        $first = $this->specialPollingIntake->record([
            'stage' => $this->lifecycle->current(),
            'intake_type' => 'ppp',
            'ballot_count' => 7,
            'received_from' => 'City Clerk',
            'received_by' => 'Scenario Officer',
            'notes' => 'S-PPP bundle 1.',
        ]);

        $this->clock->tick();
        $second = $this->specialPollingIntake->record([
            'stage' => $this->lifecycle->current(),
            'intake_type' => 'ip',
            'ballot_count' => 4,
            'received_from' => 'Barangay Social Welfare Officer',
            'received_by' => 'Scenario Officer',
            'notes' => 'Indigenous peoples packet.',
        ]);

        $this->ceremonies->closePolls('Scenario Officer');

        $this->clock->tick();
        $third = $this->specialPollingIntake->record([
            'stage' => $this->lifecycle->current(),
            'intake_type' => 'pdl',
            'ballot_count' => 3,
            'received_from' => 'Social Worker',
            'received_by' => 'Scenario Officer',
            'notes' => 'Accessible voting bundle.',
        ]);

        $summary = $this->storage->readJson('voting/special-polling-intake.json');

        return [
            'scenario' => 'special-polling-intake',
            'passed' => (bool) (($summary['special_polling_intake_hash'] ?? null) !== null)
                && (($summary['entry_count'] ?? 0) === 3),
            'run_id' => $summary['run_id'] ?? null,
            'precinct_id' => $activation['configuration']['precinct_id'] ?? null,
            'device_report_hash' => $devices['report_hash'] ?? null,
            'special_polling_intake_path' => $this->storage->path('voting/special-polling-intake.json'),
            'special_polling_intake_hash' => $summary['special_polling_intake_hash'] ?? null,
            'entry_count' => $summary['entry_count'] ?? 0,
            'total_ballots' => $summary['total_ballots'] ?? 0,
            'totals_by_type' => $summary['totals_by_type'] ?? [],
            'entry_paths' => $summary['entry_paths'] ?? [],
            'latest_entry_hash' => $third['entry_hash'] ?? null,
            'first_entry_id' => $first['intake_id'] ?? null,
            'second_entry_id' => $second['intake_id'] ?? null,
            'third_entry_id' => $third['intake_id'] ?? null,
            'first_entry_path' => $first['entry_path'] ?? null,
            'second_entry_path' => $second['entry_path'] ?? null,
            'third_entry_path' => $third['entry_path'] ?? null,
            'journal_entries' => count($this->journal->entries()),
            'stage_after_special_intake' => $this->lifecycle->current(),
        ];
    }

    /**
     * @return array{configuration: array<string, mixed>, pop_import: array<string, mixed>, ballot_definition: array<string, mixed>}
     */
    private function activateConfiguredPrecinctBallot(): array
    {
        $result = $this->activateConfiguredPrecinct->handle();
        $this->precinctSetup->record((array) config('election.simulation.precinct_setup'));
        $sourcePath = $this->popSourcePath();
        $profile = $this->popProfile();
        $clusteredPrecinct = $this->popClusteredPrecinct();

        return [
            'configuration' => $result['configuration'],
            'pop_import' => [
                ...$this->popReport($result['pop_import'], $result['imported_package'], $sourcePath, $profile, $clusteredPrecinct),
                'clc_manifest_hash' => $result['clc_import']['manifest_hash'] ?? null,
                'clc_registry_hash' => $result['clc_import']['registry_hash'] ?? null,
                'clc_manifest_path' => $result['clc_import']['artifact_path'] ?? null,
            ],
            'ballot_definition' => $result['report'],
        ];
    }

    private function popSourcePath(): string
    {
        return (string) config('election.pop.source_path');
    }

    private function popProfile(): string
    {
        return (string) config('election.pop.profile');
    }

    private function popClusteredPrecinct(): string
    {
        return (string) config('election.pop.clustered_precinct');
    }

    private function popDistrict(): string
    {
        return (string) config('election.pop.district');
    }

    private function scenarioPrecinct(string $name): string
    {
        return match ($name) {
            'friday-certification', 'full-demo', 'evidence-folder-demo', 'pop-import-demo', 'legal-suite', 'eb-role-baseline', 'initialization-report', 'supply-verification-baseline', 'fts-manual-verification-discrepancy', 'fts-zero-out' => $this->popClusteredPrecinct(),
            'open-polls-initialization-report', 'voting-legal-edge-cases', 'special-polling-intake', 'close-polls-and-counting-legal-evidence', 'election-return-legal-artifact', 'election-return-copy-distribution', 'delivery-package', 'delivery-receipt', 'manual-handoff', 'final-backup', 'custody-turnover', 'audit-reconciliation-baseline', 'field-50-ballots' => $this->popClusteredPrecinct(),
            default => 'unknown-precinct',
        };
    }

    private function approveElectionReturn(): void
    {
        $this->returnApproval->approve(
            'SIM-OFFICER-001',
            '123456',
            'SIM-OFFICER-002',
            '123456',
        );
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    private function popReport(array $manifest, array $package, string $sourcePath, string $profile, string $clusteredPrecinct): array
    {
        return [
            'source_path' => $sourcePath,
            'mapping_profile' => $profile,
            'source_label' => $manifest['source_label'] ?? null,
            'row_count' => $manifest['row_count'] ?? null,
            'unique_clustered_precinct_count' => $manifest['unique_clustered_precinct_count'] ?? null,
            'total_registered_voters' => $manifest['total_registered_voters'] ?? null,
            'registry_hash' => $manifest['registry_hash'] ?? null,
            'manifest_hash' => $manifest['manifest_hash'] ?? null,
            'manifest_path' => $manifest['artifact_path'] ?? null,
            'clustered_precinct' => $clusteredPrecinct,
            'precinct_location' => $package['location'] ?? [],
            'package_hash' => $package['package_hash'] ?? null,
            'package_path' => $package['artifact_path'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attest(string $ceremony, string $stage, string $statement): array
    {
        return $this->attestations->attest([
            'ceremony' => $ceremony,
            'officer_code' => 'SIM-OFFICER-001',
            'officer_pin' => '123456',
            'signature_data' => self::SIGNATURE_DATA_URI,
            'stage' => $stage,
            'statement' => $statement,
        ]);
    }

    private const SIGNATURE_DATA_URI = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGOSHzRgAAAAABJRU5ErkJggg==';
}
