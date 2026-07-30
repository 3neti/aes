<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;

final class PublicSimulationOperationalObservation
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function record(string $role, string $ceremony, string $assessment, string $note): array
    {
        $sequence = count($this->storage->files('operational-observations')) + 1;
        $observation = [
            'schema_version' => 'public-simulation-operational-observation-1',
            'sequence' => $sequence,
            'recorded_at' => $this->clock->now()->toIso8601String(),
            'reported_role' => $role,
            'ceremony' => $ceremony,
            'assessment' => $assessment,
            'note' => trim($note),
        ];
        $observation['observation_hash'] = $this->json->hash($observation);
        $observation['artifact_path'] = $this->storage->writeJson(sprintf('operational-observations/%06d-observation.json', $sequence), $observation);
        $this->journal->record('public_simulation.operational_observation_recorded', [
            'sequence' => $sequence,
            'reported_role' => $role,
            'ceremony' => $ceremony,
            'assessment' => $assessment,
            'observation_hash' => $observation['observation_hash'],
        ]);

        return $observation;
    }

    /** @return array{total: int, clear: int, needs_attention: int, blocking: int} */
    public function summary(): array
    {
        $counts = collect($this->storage->files('operational-observations'))
            ->map(fn (string $path): array => $this->storage->readJson('operational-observations/'.basename($path)))
            ->countBy(fn (array $observation): string => (string) ($observation['assessment'] ?? 'unknown'));

        return [
            'total' => $counts->sum(),
            'clear' => (int) $counts->get('clear', 0),
            'needs_attention' => (int) $counts->get('needs_attention', 0),
            'blocking' => (int) $counts->get('blocking', 0),
        ];
    }
}
