<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionOperationLock;
use App\Election\Support\ElectionStorage;

final class PublicSimulationParticipation
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly ElectionOperationLock $lock,
        private readonly ActivityJournal $journal,
        private readonly CanonicalJson $json,
    ) {}

    /** @return array<string, mixed> */
    public function policy(): array
    {
        $existing = $this->storage->readJson('public-simulation-participation-policy.json');

        if ($existing !== []) {
            return $existing;
        }

        return $this->lock->execute('public-simulation-participation-policy', function (): array {
            $existing = $this->storage->readJson('public-simulation-participation-policy.json');

            if ($existing !== []) {
                return $existing;
            }

            $policy = [
                'schema_version' => 'public-simulation-participation-policy-1',
                'published_at' => $this->clock->now()->toIso8601String(),
                'purpose' => 'Public election simulation for learning and review. It is not an official election service.',
                'retention_days' => max(1, (int) config('election.public_simulation.retention_days', 30)),
                'data_practices' => [
                    'The voter interface does not request or retain a voter name, government identifier, or contact detail.',
                    'The ballot is private. Public results are released only after precinct close under the configured simulation policy.',
                    'This browser acknowledgment is kept only in the current browser session.',
                    'Precinct evidence is retained for the configured simulation review window, then handled by the controlled archive and retention review procedure.',
                ],
            ];
            $policy['policy_hash'] = $this->json->hash($policy);
            $policy['artifact_path'] = $this->storage->writeJson('public-simulation-participation-policy.json', $policy);
            $this->journal->record('public_simulation.participation_policy_published', [
                'policy_hash' => $policy['policy_hash'],
                'retention_days' => $policy['retention_days'],
            ]);

            return $policy;
        });
    }

    /** @return array<string, mixed> */
    public function accept(): array
    {
        $policy = $this->policy();
        $this->journal->record('public_simulation.participation_accepted', [
            'policy_hash' => $policy['policy_hash'],
        ]);

        return $policy;
    }
}
