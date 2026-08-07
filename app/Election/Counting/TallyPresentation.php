<?php

namespace App\Election\Counting;

final class TallyPresentation
{
    /**
     * @param  array<string, array<string, int>>  $tally
     * @return array<string, array<string, int>>
     */
    public function displayTally(array $tally): array
    {
        return collect($tally)
            ->map(fn (array $candidates): array => collect($candidates)
                ->filter(fn (mixed $votes): bool => is_numeric($votes) && (int) $votes > 0)
                ->map(fn (mixed $votes): int => (int) $votes)
                ->all())
            ->all();
    }

    /**
     * @param  array<string, array<string, int>>  $displayTally
     * @return array<string, array{candidate_rows: int, total_votes: int}>
     */
    public function summary(array $displayTally): array
    {
        return collect($displayTally)
            ->map(fn (array $candidates): array => [
                'candidate_rows' => count($candidates),
                'total_votes' => array_sum($candidates),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $tally
     * @return array<string, mixed>
     */
    public function forHumanArtifacts(array $tally): array
    {
        $displayTally = (array) ($tally['display_tally'] ?? $this->displayTally((array) ($tally['tally'] ?? [])));

        return [
            ...$tally,
            'tally' => $displayTally,
            'display_tally' => $displayTally,
            'display_summary' => $tally['display_summary'] ?? $this->summary($displayTally),
        ];
    }
}
