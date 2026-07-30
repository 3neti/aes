<?php

namespace App\Console\Commands;

use App\Election\PublicSimulation\PublicSimulationService;
use App\Models\SimulationRound;
use Illuminate\Console\Command;
use RuntimeException;

final class ArchivePublicSimulationRound extends Command
{
    /** @var string */
    protected $signature = 'election:public-simulation:archive {round : Public simulation round code}';

    /** @var string */
    protected $description = 'Archive a fully published public simulation round without deleting its evidence';

    public function handle(PublicSimulationService $simulations): int
    {
        $round = SimulationRound::query()->where('code', $this->argument('round'))->first();

        if ($round === null) {
            $this->error('Public simulation round not found.');

            return self::FAILURE;
        }

        try {
            $archived = $simulations->archive($round);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Public simulation round {$archived->code} archived. Evidence remains in its precinct storage namespaces.");

        return self::SUCCESS;
    }
}
