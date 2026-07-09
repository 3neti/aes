<?php

namespace App\Election\Scenarios;

use App\Election\Attestation\ElectoralBoardBaselineService;
use App\Election\Attestation\OfficerAttestationService;
use App\Election\Certification\CertificationDeckBuilder;
use App\Election\Certification\CertificationService;
use App\Election\Core\ActivityJournal;
use App\Election\Counting\CountingService;
use App\Election\Devices\DeviceCertificationService;
use App\Election\Diagnostics\EvidenceReferenceBaselineService;
use App\Election\Lifecycle\CeremonyActions;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Minutes\OfficialMinutesBaselineService;
use App\Election\Preparation\ActivateImportedPrecinctPackage;
use App\Election\Preparation\ActivatePrecinctBallotPackage;
use App\Election\Preparation\ClcCandidateImporter;
use App\Election\Preparation\PopWorkbookImporter;
use App\Election\Preparation\SupplyVerificationBaselineService;
use App\Election\Printing\BallotPrinter;
use App\Election\Printing\SpoilBallot;
use App\Election\Returns\ElectionReturnService;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\BallotPayloadService;
use InvalidArgumentException;

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
        private readonly CertificationDeckBuilder $deckBuilder,
        private readonly EvidenceReferenceBaselineService $baseline,
        private readonly OfficialMinutesBaselineService $officialMinutes,
        private readonly ElectoralBoardBaselineService $electoralBoardBaseline,
        private readonly SupplyVerificationBaselineService $supplyVerificationBaseline,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(string $name): array
    {
        $this->storage->reset();
        $this->clock->freeze('2026-05-08 08:00:00');
        $this->storage->startRun($name, $this->scenarioPrecinct($name), $this->clock->now()->format('Ymd-His'));

        $report = match ($name) {
            'friday-certification' => $this->fridayCertification(),
            'full-demo' => $this->fullDemo(),
            'evidence-folder-demo' => $this->evidenceFolderDemo(),
            'pop-import-demo' => $this->popImportDemo(),
            'supply-verification-baseline' => $this->supplyVerificationBaselineScenario(),
            'eb-role-baseline' => $this->electoralBoardBaselineScenario(),
            'legal-suite' => $this->legalSuite(),
            default => throw new InvalidArgumentException("Unknown scenario [{$name}]."),
        };

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
        $attestation = $this->attest('Certification', Lifecycle::Certification, 'Certification officer review complete.');

        return [
            'scenario' => 'friday-certification',
            'passed' => $devices['passed'] && $certification['passed'],
            'precinct_id' => $configuration['precinct_id'],
            'pop_import' => $activation['pop_import'],
            'ballot_definition' => $activation['ballot_definition'],
            'device_report_hash' => $devices['report_hash'],
            'report_hash' => $certification['report_hash'],
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
     * @return array{configuration: array<string, mixed>, pop_import: array<string, mixed>, ballot_definition: array<string, mixed>}
     */
    private function activateConfiguredPrecinctBallot(): array
    {
        $sourcePath = $this->popSourcePath();
        $profile = $this->popProfile();
        $clusteredPrecinct = $this->popClusteredPrecinct();
        $manifest = $this->popImporter->import($sourcePath, $profile);
        $clcManifest = $this->clcImporter->import();
        $importedPackage = $this->activateImportedPrecinct->handle($clusteredPrecinct);
        $activation = $this->activatePrecinctBallot->handle($clusteredPrecinct, $this->popDistrict());

        return [
            'configuration' => $activation['configuration'],
            'pop_import' => [
                ...$this->popReport($manifest, $importedPackage, $sourcePath, $profile, $clusteredPrecinct),
                'clc_manifest_hash' => $clcManifest['manifest_hash'] ?? null,
                'clc_registry_hash' => $clcManifest['registry_hash'] ?? null,
                'clc_manifest_path' => $clcManifest['artifact_path'] ?? null,
            ],
            'ballot_definition' => $activation['report'],
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
            'friday-certification', 'full-demo', 'evidence-folder-demo', 'pop-import-demo', 'legal-suite', 'eb-role-baseline' => $this->popClusteredPrecinct(),
            'supply-verification-baseline' => $this->popClusteredPrecinct(),
            default => 'unknown-precinct',
        };
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
