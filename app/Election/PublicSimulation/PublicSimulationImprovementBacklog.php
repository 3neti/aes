<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use RuntimeException;

final class PublicSimulationImprovementBacklog
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $review = $this->latestObservationReview();
        $items = collect($review['follow_up_observations'] ?? [])
            ->map(fn (array $observation): array => [
                'source_observation_sequence' => $observation['sequence'],
                'reported_role' => $observation['reported_role'],
                'ceremony' => $observation['ceremony'],
                'assessment' => $observation['assessment'],
                'priority' => $this->priority((string) $observation['assessment']),
                'status' => 'open',
                'recommended_owner' => $this->recommendedOwner((string) $observation['ceremony']),
                'problem_statement' => $observation['note'],
                'source_observation_hash' => $observation['observation_hash'],
            ])
            ->values()
            ->all();
        $sequence = count($this->storage->files('improvement-backlog')) + 1;
        $backlog = [
            'schema_version' => 'public-simulation-improvement-backlog-1',
            'sequence' => $sequence,
            'generated_at' => $this->clock->now()->toIso8601String(),
            'source_review_sequence' => $review['sequence'] ?? null,
            'source_review_hash' => $review['review_hash'] ?? null,
            'summary' => [
                'total_items' => count($items),
                'by_priority' => collect($items)->countBy('priority')->sortKeys()->all(),
                'by_ceremony' => collect($items)->countBy('ceremony')->sortKeys()->all(),
            ],
            'items' => $items,
            'privacy_notice' => 'Private improvement backlog. Review before sharing because problem statements may include facilitator notes.',
        ];
        $backlog['backlog_hash'] = $this->json->hash($backlog);
        $backlog['artifact_path'] = $this->storage->writeJson(sprintf('improvement-backlog/%06d-improvement-backlog.json', $sequence), $backlog);

        $this->journal->record('public_simulation.improvement_backlog_created', [
            'sequence' => $sequence,
            'backlog_hash' => $backlog['backlog_hash'],
            'total_items' => count($items),
        ]);

        return $backlog;
    }

    /** @return array<string, mixed> */
    private function latestObservationReview(): array
    {
        $path = collect($this->storage->files('observation-review'))
            ->sort()
            ->last();

        if (! is_string($path)) {
            throw new RuntimeException('Create an observation review before building the improvement backlog.');
        }

        return $this->storage->readJson('observation-review/'.basename($path));
    }

    private function priority(string $assessment): string
    {
        return match ($assessment) {
            'blocking' => 'high',
            'needs_attention' => 'medium',
            default => 'low',
        };
    }

    private function recommendedOwner(string $ceremony): string
    {
        return match ($ceremony) {
            'admission', 'voting' => 'voter-experience',
            'results', 'audit' => 'transparency-and-audit',
            default => 'simulation-operations',
        };
    }
}
