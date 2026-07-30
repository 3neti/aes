<?php

namespace App\Console\Commands;

use App\Election\PublicSimulation\PublicSimulationObservationReview;
use App\Election\PublicSimulation\PublicSimulationService;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Illuminate\Console\Command;
use RuntimeException;

final class ReviewPublicSimulationObservations extends Command
{
    /** @var string */
    protected $signature = 'election:public-simulation:observation-review {round : Public simulation round code} {precinct : Published public simulation precinct code}';

    /** @var string */
    protected $description = 'Create a private facilitator review of public-simulation operational observations';

    public function handle(PublicSimulationService $simulations, PublicSimulationObservationReview $observations): int
    {
        $round = SimulationRound::query()->where('code', $this->argument('round'))->first();
        $precinct = $round?->precincts()->where('code', $this->argument('precinct'))->first();

        if (! $precinct instanceof SimulationPrecinct) {
            $this->error('Public simulation precinct not found.');

            return self::FAILURE;
        }

        if ($precinct->status !== 'published') {
            $this->error('Operational observations can be reviewed after watcher publication.');

            return self::FAILURE;
        }

        try {
            $simulations->applyScope($precinct);
            $review = $observations->build();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Observation review {$review['sequence']} created for {$round->code}/{$precinct->code}: {$review['artifact_path']}");

        return self::SUCCESS;
    }
}
