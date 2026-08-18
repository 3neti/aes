<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Counting\CountingService;
use App\Election\Returns\ElectionReturnService;
use App\Models\SimulationPrecinct;

final class RoleDemoInterimCloseout
{
    public function __construct(
        private readonly CountingService $counting,
        private readonly ElectionReturnService $returns,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function tally(): array
    {
        return $this->counting->tally();
    }

    /**
     * @return array{tally: array<string, mixed>, return: array<string, mixed>}
     */
    public function generate(SimulationPrecinct $precinct, string $reason): array
    {
        $tally = $this->counting->tally();
        $return = $this->returns->generate($tally);

        $this->journal->record('role_demo.interim_forms_generated', [
            'precinct_code' => $precinct->code,
            'reason' => $reason,
            'accepted_ballots' => $tally['accepted_ballots'],
            'tally_hash' => $tally['tally_hash'],
            'return_hash' => $return['return_hash'],
        ]);

        return [
            'tally' => $tally,
            'return' => $return,
        ];
    }
}
