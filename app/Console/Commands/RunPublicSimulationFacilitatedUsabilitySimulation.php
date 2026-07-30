<?php

namespace App\Console\Commands;

use App\Election\PublicSimulation\PublicSimulationFacilitatedUsabilitySimulation;
use App\Election\PublicSimulation\PublicSimulationService;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Illuminate\Console\Command;
use RuntimeException;

final class RunPublicSimulationFacilitatedUsabilitySimulation extends Command
{
    /** @var string */
    protected $signature = 'election:public-simulation:facilitated-usability-simulation
        {round? : Public simulation round code. Defaults to the current open round.}
        {precinct? : Ready public simulation precinct code. Defaults to the first ready precinct in the round.}
        {--voters=5 : Voter cohort size for the synthetic dry-run}';

    /** @var string */
    protected $description = 'Run a synthetic facilitated usability simulation and persist the review/backlog artifacts';

    public function handle(PublicSimulationService $simulations, PublicSimulationFacilitatedUsabilitySimulation $simulation): int
    {
        try {
            $round = $this->round($simulations);
            $precinct = $this->precinct($round);

            if (! $precinct instanceof SimulationPrecinct) {
                throw new RuntimeException('No ready public simulation precinct is available for the facilitated usability simulation.');
            }

            $report = $simulation->run($precinct, (int) $this->option('voters'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Facilitated usability simulation completed for {$round->code}/{$precinct->code}: {$report['artifact_path']}");

        return self::SUCCESS;
    }

    private function round(PublicSimulationService $simulations): SimulationRound
    {
        $roundCode = $this->argument('round');

        if (is_string($roundCode) && $roundCode !== '') {
            $round = SimulationRound::query()->with('precincts')->where('code', $roundCode)->first();

            if ($round instanceof SimulationRound) {
                return $round;
            }

            throw new RuntimeException('Public simulation round not found.');
        }

        return $simulations->currentRound();
    }

    private function precinct(SimulationRound $round): ?SimulationPrecinct
    {
        $precinctCode = $this->argument('precinct');

        if (is_string($precinctCode) && $precinctCode !== '') {
            return $round->precincts->first(fn (SimulationPrecinct $precinct): bool => $precinct->code === $precinctCode && $precinct->status === 'ready');
        }

        return $round->precincts
            ->sortBy('code')
            ->first(fn (SimulationPrecinct $precinct): bool => $precinct->status === 'ready');
    }
}
