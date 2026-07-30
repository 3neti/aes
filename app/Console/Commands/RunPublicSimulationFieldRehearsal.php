<?php

namespace App\Console\Commands;

use App\Election\PublicSimulation\PublicSimulationFieldRehearsal;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Illuminate\Console\Command;
use RuntimeException;

final class RunPublicSimulationFieldRehearsal extends Command
{
    /** @var string */
    protected $signature = 'election:public-simulation:field-rehearsal {round : Public simulation round code} {precinct : Ready public simulation precinct code} {--voters=5 : Voter cohort size (2 through the active-admission limit)}';

    /** @var string */
    protected $description = 'Run a deterministic public-simulation voter cohort rehearsal and publish its results';

    public function handle(PublicSimulationFieldRehearsal $rehearsal): int
    {
        $round = SimulationRound::query()->where('code', $this->argument('round'))->first();
        $precinct = $round?->precincts()->where('code', $this->argument('precinct'))->first();

        if ($precinct === null || ! $precinct instanceof SimulationPrecinct) {
            $this->error('Public simulation precinct not found.');

            return self::FAILURE;
        }

        try {
            $report = $rehearsal->run($precinct, (int) $this->option('voters'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Field rehearsal completed for {$round->code}/{$precinct->code}: {$report['artifact_path']}");

        return self::SUCCESS;
    }
}
