<?php

namespace App\Election\PublicSimulation;

use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionOperationLock;
use Closure;
use RuntimeException;

final class PublicSimulationVotingGate
{
    public function __construct(
        private readonly ElectionOperationLock $lock,
        private readonly LifecycleState $lifecycle,
    ) {}

    public function execute(Closure $operation): mixed
    {
        return $this->lock->execute('public-simulation-voting', function () use ($operation): mixed {
            if ($this->lifecycle->current() !== Lifecycle::Voting) {
                throw new RuntimeException('Voting is no longer open for this precinct.');
            }

            return $operation();
        }, leaseSeconds: 60);
    }
}
