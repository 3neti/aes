<?php

namespace App\Console\Commands;

use App\Election\PublicSimulation\PublicSimulationService;
use App\Election\PublicSimulation\PublicSimulationUsabilitySessionKit;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Illuminate\Console\Command;
use RuntimeException;

final class BuildPublicSimulationUsabilitySessionKit extends Command
{
    /** @var string */
    protected $signature = 'election:public-simulation:usability-session-kit {round : Public simulation round code} {precinct : Ready public simulation precinct code}';

    /** @var string */
    protected $description = 'Prepare a privacy-safe external usability session guide for a public simulation precinct';

    public function handle(PublicSimulationService $simulations, PublicSimulationUsabilitySessionKit $sessionKit): int
    {
        $round = SimulationRound::query()->where('code', $this->argument('round'))->first();
        $precinct = $round?->precincts()->where('code', $this->argument('precinct'))->first();

        if (! $precinct instanceof SimulationPrecinct) {
            $this->error('Public simulation precinct not found.');

            return self::FAILURE;
        }

        try {
            $simulations->applyScope($precinct);
            $kit = $sessionKit->build($precinct);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Usability session kit prepared for {$round->code}/{$precinct->code}: {$kit['artifact_path']}");

        return self::SUCCESS;
    }
}
