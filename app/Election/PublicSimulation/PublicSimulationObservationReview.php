<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;

final class PublicSimulationObservationReview
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
        $observations = collect($this->storage->files('operational-observations'))
            ->map(fn (string $path): array => $this->storage->readJson('operational-observations/'.basename($path)))
            ->sortBy('sequence')
            ->values();
        $summary = [
            'total' => $observations->count(),
            'by_role' => $observations->countBy('reported_role')->sortKeys()->all(),
            'by_ceremony' => $observations->countBy('ceremony')->sortKeys()->all(),
            'by_assessment' => $observations->countBy('assessment')->sortKeys()->all(),
        ];
        $followUps = $observations
            ->whereIn('assessment', ['needs_attention', 'blocking'])
            ->map(fn (array $observation): array => [
                'sequence' => $observation['sequence'],
                'reported_role' => $observation['reported_role'],
                'ceremony' => $observation['ceremony'],
                'assessment' => $observation['assessment'],
                'note' => $observation['note'],
                'observation_hash' => $observation['observation_hash'],
            ])
            ->values()
            ->all();
        $sequence = count($this->storage->files('observation-review')) + 1;
        $report = [
            'schema_version' => 'public-simulation-observation-review-1',
            'sequence' => $sequence,
            'generated_at' => $this->clock->now()->toIso8601String(),
            'summary' => $summary,
            'follow_up_observations' => $followUps,
            'privacy_notice' => 'This facilitator-only review may contain debrief notes. Do not publish it or add voter or personal information to observations.',
        ];
        $report['review_hash'] = $this->json->hash($report);
        $report['artifact_path'] = $this->storage->writeJson(sprintf('observation-review/%06d-observation-review.json', $sequence), $report);
        $this->journal->record('public_simulation.operational_observations_reviewed', [
            'sequence' => $sequence,
            'review_hash' => $report['review_hash'],
            'total_observations' => $summary['total'],
            'follow_up_count' => count($followUps),
        ]);

        return $report;
    }
}
