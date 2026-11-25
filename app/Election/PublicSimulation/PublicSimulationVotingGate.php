<?php

namespace App\Election\PublicSimulation;

use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionOperationLock;
use App\Exceptions\ElectionOperationBusy;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use RuntimeException;

final class PublicSimulationVotingGate
{
    public function __construct(
        private readonly ElectionOperationLock $lock,
        private readonly LifecycleState $lifecycle,
    ) {}

    public function execute(Closure $operation): mixed
    {
        $started = false;

        try {
            $guardedOperation = function () use ($operation, &$started): mixed {
                $started = true;
                if ($this->lifecycle->current() !== Lifecycle::Voting) {
                    throw new RuntimeException('Voting is no longer open for this precinct.');
                }

                return $operation();
            };

            return match (config('election.voting_lock_driver')) {
                'local' => $this->lock->executeLocalVoting($guardedOperation),
                'cache' => $this->lock->execute('public-simulation-voting', $guardedOperation, leaseSeconds: 60),
                default => throw new RuntimeException('Invalid election voting lock driver.'),
            };
        } catch (LockTimeoutException $exception) {
            if ($started) {
                throw $exception;
            }

            throw new ElectionOperationBusy('The election system is busy. Please wait a moment and try again.', previous: $exception);
        }
    }
}
