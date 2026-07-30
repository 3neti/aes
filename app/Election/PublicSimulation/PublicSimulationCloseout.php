<?php

namespace App\Election\PublicSimulation;

use App\Election\Counting\CountingLegalEvidenceService;
use App\Election\Counting\CountingReconciliationService;
use App\Election\Counting\CountingService;
use App\Election\Lifecycle\CeremonyActions;
use App\Election\Returns\ElectionReturnService;
use App\Election\Tabulation\DeviceTabulationLedger;
use App\Election\Voting\SealedBallotBox;
use App\Models\SimulationPrecinct;

final class PublicSimulationCloseout
{
    public function __construct(
        private readonly CeremonyActions $ceremonies,
        private readonly CountingLegalEvidenceService $legalEvidence,
        private readonly SealedBallotBox $ballotBox,
        private readonly DeviceTabulationLedger $ledger,
        private readonly CountingReconciliationService $reconciliation,
        private readonly CountingService $counting,
        private readonly ElectionReturnService $returns,
        private readonly VvdatLedgerFreeze $freeze,
        private readonly PublicSimulationVotingGate $voting,
        private readonly PublicSimulationVotingQuiescence $quiescence,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function close(SimulationPrecinct $precinct, string $officerCode, string $officerPin): array
    {
        return $this->voting->execute(function () use ($precinct, $officerCode, $officerPin): array {
            $this->quiescence->assertReadyForClose();
            $this->ceremonies->closePolls($precinct->officer_name);
            $this->legalEvidence->writeForClosePolls();
            $freeze = $this->freeze->freeze();
            $validation = $this->freeze->validation();

            if (! $validation['passed']) {
                throw new \RuntimeException('The VVDAT ledger freeze validation failed: '.implode(' ', $validation['errors']));
            }

            $this->ceremonies->startCounting();
            $this->ballotBox->openForCounting($this->counting);

            $physicalCount = $this->ledger->summary()['recorded_ballots'];
            $this->reconciliation->recordPhysicalCount($physicalCount, $officerCode, $officerPin);
            $tally = $this->counting->tally();
            $this->legalEvidence->writeForCompletion($tally);
            $this->ceremonies->moveToReturns();
            $return = $this->returns->generate($tally);

            $precinct->forceFill([
                'status' => 'results_ready',
                'closed_at' => now(),
            ])->save();

            return ['tally' => $tally, 'return' => $return, 'freeze' => $freeze];
        });
    }
}
