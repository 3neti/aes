<?php

namespace App\Console\Commands;

use App\Election\PublicSimulation\PublicSimulationImprovementBacklog;
use App\Election\PublicSimulation\PublicSimulationService;
use App\Election\Support\ElectionRunScopeLock;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Illuminate\Console\Command;
use RuntimeException;

final class BuildPublicSimulationImprovementBacklog extends Command
{
    /** @var string */
    protected $signature = 'election:public-simulation:improvement-backlog {round : Public simulation round code} {precinct : Published public simulation precinct code}';

    /** @var string */
    protected $description = 'Create a private improvement backlog from reviewed public-simulation observations';

    public function handle(PublicSimulationService $simulations, PublicSimulationImprovementBacklog $backlog, ElectionRunScopeLock $runLock): int
    {
        $operation = fn (): int => $this->buildBacklog($simulations, $backlog);

        return config('election.voting_lock_driver') === 'local' ? $runLock->executeCommand($operation) : $operation();
    }

    private function buildBacklog(PublicSimulationService $simulations, PublicSimulationImprovementBacklog $backlog): int
    {
        $round = SimulationRound::query()->where('code', $this->argument('round'))->first();
        $precinct = $round?->precincts()->where('code', $this->argument('precinct'))->first();

        if (! $precinct instanceof SimulationPrecinct) {
            $this->error('Public simulation precinct not found.');

            return self::FAILURE;
        }

        if ($precinct->status !== 'published') {
            $this->error('Improvement backlog can be created after watcher publication and observation review.');

            return self::FAILURE;
        }

        try {
            $simulations->applyScope($precinct);
            $report = $backlog->build();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Improvement backlog {$report['sequence']} created for {$round->code}/{$precinct->code}: {$report['artifact_path']}");

        return self::SUCCESS;
    }
}
