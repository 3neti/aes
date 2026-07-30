<?php

namespace App\Console\Commands;

use App\Election\PublicSimulation\PublicSimulationReviewKit;
use App\Models\SimulationRound;
use Illuminate\Console\Command;

final class BuildPublicSimulationReviewKit extends Command
{
    /** @var string */
    protected $signature = 'election:public-simulation:review-kit {round : Public simulation round code}';

    /** @var string */
    protected $description = 'Build a privacy-safe COMELEC review index for a public simulation round';

    public function handle(PublicSimulationReviewKit $reviewKit): int
    {
        $round = SimulationRound::query()->with('precincts')->where('code', $this->argument('round'))->first();

        if ($round === null) {
            $this->error('Public simulation round not found.');

            return self::FAILURE;
        }

        $kit = $reviewKit->build($round);
        $this->info("Review Kit generated for {$round->code}: {$kit['artifact_path']}");

        return self::SUCCESS;
    }
}
