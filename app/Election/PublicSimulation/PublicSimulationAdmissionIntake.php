<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionOperationLock;
use App\Election\Support\ElectionStorage;
use RuntimeException;

final class PublicSimulationAdmissionIntake
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly ElectionOperationLock $lock,
        private readonly ActivityJournal $journal,
    ) {}

    /** @return array{status: string, changed_at: string|null} */
    public function status(): array
    {
        $record = $this->storage->readJson('admission-intake.json');

        return [
            'status' => in_array($record['status'] ?? null, ['open', 'paused'], true) ? $record['status'] : 'open',
            'changed_at' => isset($record['changed_at']) ? (string) $record['changed_at'] : null,
        ];
    }

    public function assertAcceptingNewTickets(): void
    {
        if ($this->status()['status'] === 'paused') {
            throw new RuntimeException('The anonymous waiting line is temporarily paused. Please wait for the Election Officer to reopen it.');
        }
    }

    /** @return array{status: string, changed_at: string|null} */
    public function pause(): array
    {
        return $this->change('paused');
    }

    /** @return array{status: string, changed_at: string|null} */
    public function resume(): array
    {
        return $this->change('open');
    }

    /** @return array{status: string, changed_at: string|null} */
    private function change(string $status): array
    {
        return $this->lock->execute('public-simulation-admission-intake', function () use ($status): array {
            $current = $this->status();

            if ($current['status'] === $status) {
                return $current;
            }

            $record = [
                'schema_version' => 'public-simulation-admission-intake-1',
                'status' => $status,
                'changed_at' => $this->clock->now()->toIso8601String(),
            ];
            $this->storage->writeJson('admission-intake.json', $record);
            $this->journal->record($status === 'paused'
                ? 'public_simulation.admission_intake_paused'
                : 'public_simulation.admission_intake_resumed', [
                    'status' => $status,
                ]);

            return $record;
        });
    }
}
