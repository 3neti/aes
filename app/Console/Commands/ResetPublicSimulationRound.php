<?php

namespace App\Console\Commands;

use App\Election\PublicSimulation\PublicSimulationService;
use App\Models\SimulationRound;
use Illuminate\Console\Command;
use RuntimeException;

final class ResetPublicSimulationRound extends Command
{
    /** @var string */
    protected $signature = 'election:public-simulation:reset {round : Published public simulation round code}';

    /** @var string */
    protected $description = 'Archive a completed simulation round and create a fresh three-precinct public lobby';

    public function handle(PublicSimulationService $simulations): int
    {
        $round = SimulationRound::query()->where('code', $this->argument('round'))->first();

        if ($round === null) {
            $this->error('Public simulation round not found.');

            return self::FAILURE;
        }

        try {
            $result = $simulations->reset($round);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Archived {$result['archived']->code}; created fresh public simulation round {$result['fresh']->code}. Evidence remains in the archived precinct namespaces.");

        return self::SUCCESS;
    }
}
