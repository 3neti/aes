<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Support\ElectionOperationLock;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\AnonymousVoterAuthorization;
use RuntimeException;

final class PublicSimulationAdmissionCapacity
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionOperationLock $lock,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @return array{authorization_id: string, code: string, expires_at: string}
     */
    public function issue(AnonymousVoterAuthorization $authorizations): array
    {
        return $this->lock->execute('public-simulation-admission-capacity', function () use ($authorizations): array {
            $summary = $this->summary();

            if ($summary['active_admissions'] >= $summary['maximum_active_admissions']) {
                throw new RuntimeException('This precinct has reached its active voter-admission limit. Wait for an issued control number to be claimed or expire.');
            }

            $authorization = $authorizations->issue();
            $this->journal->record('public_simulation.admission_capacity_reserved', [
                'authorization_id' => $authorization['authorization_id'],
                'active_admissions_before_issue' => $summary['active_admissions'],
                'maximum_active_admissions' => $summary['maximum_active_admissions'],
            ]);

            return $authorization;
        });
    }

    /**
     * @return array{active_admissions: int, maximum_active_admissions: int, available_admissions: int}
     */
    public function summary(): array
    {
        $active = collect($this->storage->files('voter-authorizations'))
            ->map(fn (string $path): array => $this->storage->readJson('voter-authorizations/'.basename($path)))
            ->whereIn('status', ['issued', 'claimed'])
            ->count();

        $maximum = max(1, (int) config('election.public_simulation.maximum_active_admissions', 10));

        return [
            'active_admissions' => $active,
            'maximum_active_admissions' => $maximum,
            'available_admissions' => max(0, $maximum - $active),
        ];
    }
}
