<?php

namespace App\Election\Certification;

final class CertificationDeckBuilder
{
    /**
     * @param  array<string, mixed>  $configuration
     * @return array{ballots: array<int, array{id: string, selections: array<string, array<int, string>>}>, expected_tally: array<string, array<string, int>>}
     */
    public function build(array $configuration): array
    {
        $ballots = [
            [
                'id' => 'cert-001',
                'selections' => $this->selections($configuration, 0),
            ],
            [
                'id' => 'cert-002',
                'selections' => $this->selections($configuration, 1),
            ],
            [
                'id' => 'cert-003',
                'selections' => $this->selections($configuration, 0),
            ],
        ];

        return [
            'ballots' => $ballots,
            'expected_tally' => $this->expectedTally($configuration, $ballots),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, array<int, string>>
     */
    public function selections(array $configuration, int $offset = 0): array
    {
        $selections = [];

        foreach ($configuration['contests'] ?? [] as $contest) {
            $candidateIds = collect($contest['candidates'])
                ->pluck('id')
                ->values();
            $limit = (int) $contest['max_selections'];

            $selected = $candidateIds
                ->slice($offset, $limit)
                ->values();

            if ($selected->count() < $limit) {
                $selected = $selected->merge($candidateIds->take($limit - $selected->count()));
            }

            $selections[$contest['id']] = $selected->values()->all();
        }

        return $selections;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<int, array{id: string, selections: array<string, array<int, string>>}>  $ballots
     * @return array<string, array<string, int>>
     */
    private function expectedTally(array $configuration, array $ballots): array
    {
        $tally = [];

        foreach ($configuration['contests'] ?? [] as $contest) {
            $tally[$contest['id']] = [];

            foreach ($contest['candidates'] as $candidate) {
                $tally[$contest['id']][$candidate['id']] = 0;
            }
        }

        foreach ($ballots as $ballot) {
            foreach ($ballot['selections'] as $contestId => $candidateIds) {
                foreach ($candidateIds as $candidateId) {
                    $tally[$contestId][$candidateId]++;
                }
            }
        }

        return $tally;
    }
}
