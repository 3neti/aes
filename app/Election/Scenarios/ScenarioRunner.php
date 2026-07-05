<?php

namespace App\Election\Scenarios;

use App\Election\Attestation\OfficerAttestationService;
use App\Election\Certification\CertificationService;
use App\Election\Core\ActivityJournal;
use App\Election\Counting\CountingService;
use App\Election\Devices\DeviceCertificationService;
use App\Election\Lifecycle\CeremonyActions;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Preparation\ActivateSamplePackage;
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
        private readonly ActivateSamplePackage $activate,
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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(string $name): array
    {
        $this->storage->reset();
        $this->clock->freeze('2026-05-08 08:00:00');

        $report = match ($name) {
            'friday-certification' => $this->fridayCertification(),
            'full-demo' => $this->fullDemo(),
            default => throw new InvalidArgumentException("Unknown scenario [{$name}]."),
        };

        $this->storage->writeJson("scenarios/{$name}-report.json", $report);
        $this->clock->unfreeze();

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function fridayCertification(): array
    {
        $configuration = $this->activate->handle();
        $this->clock->tick();
        $devices = $this->devices->run();
        $certification = $this->certification->run();
        $attestation = $this->attest('Certification', Lifecycle::Certification, 'Certification officer review complete.');

        return [
            'scenario' => 'friday-certification',
            'passed' => $devices['passed'] && $certification['passed'],
            'precinct_id' => $configuration['precinct_id'],
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
        $configuration = $this->activate->handle();
        $this->clock->tick();
        $devices = $this->devices->run();
        $certification = $this->certification->run();
        $certificationAttestation = $this->attest('Certification', Lifecycle::Certification, 'Certification officer review complete.');
        $this->lifecycle->set(Lifecycle::OpenPrecinct);
        $this->ceremonies->openPolls();

        $payload = $this->payloads->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana', 'council-cora'],
        ], 'demo-ballot-001');
        $printJob = $this->printer->print($payload);

        $spoiledPayload = $this->payloads->finalize([
            'president' => ['pres-grace'],
            'mayor' => ['mayor-jose'],
            'council' => ['council-ben'],
        ], 'demo-ballot-spoiled');
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
        $this->ceremonies->closePrecinct();

        return [
            'scenario' => 'full-demo',
            'passed' => $devices['passed'] && $certification['passed'] && $accepted['status'] === 'accepted' && $rejected['status'] === 'rejected',
            'precinct_id' => $configuration['precinct_id'],
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
    private function attest(string $ceremony, string $stage, string $statement): array
    {
        return $this->attestations->attest([
            'ceremony' => $ceremony,
            'officer_code' => 'SIM-OFFICER-001',
            'officer_pin' => '123456',
            'stage' => $stage,
            'statement' => $statement,
        ]);
    }
}
