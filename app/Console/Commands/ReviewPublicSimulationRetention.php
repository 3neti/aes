<?php

namespace App\Console\Commands;

use App\Election\PublicSimulation\PublicSimulationRetentionReview;
use App\Models\SimulationRound;
use Illuminate\Console\Command;

final class ReviewPublicSimulationRetention extends Command
{
    /** @var string */
    protected $signature = 'election:public-simulation:retention-review {round : Public simulation round code}';

    /** @var string */
    protected $description = 'Write a no-delete retention review report for a public simulation round';

    public function handle(PublicSimulationRetentionReview $retentionReview): int
    {
        $round = SimulationRound::query()->with('precincts')->where('code', $this->argument('round'))->first();

        if ($round === null) {
            $this->error('Public simulation round not found.');

            return self::FAILURE;
        }

        $report = $retentionReview->review($round);
        $this->info("Retention review {$report['review_status']} for {$round->code}: {$report['artifact_path']}");

        return self::SUCCESS;
    }
}
