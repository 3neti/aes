<?php

namespace App\Election\Core;

use App\Election\Support\ElectionStorage;

final class BallotConfigurationLabels
{
    public function __construct(private readonly ElectionStorage $storage) {}

    public function contest(string $contestId): string
    {
        $contest = $this->contestMap()[$contestId] ?? null;

        return is_array($contest) ? (string) $contest['title'] : $contestId;
    }

    public function candidate(string $contestId, string $candidateId): string
    {
        $candidate = $this->candidateMap($contestId)[$candidateId] ?? null;

        if (! is_array($candidate)) {
            return $candidateId;
        }

        return trim(($candidate['ballot_number'] ?? $candidate['ordinal']).'. '.$candidate['name']);
    }

    /**
     * @param  array<string, array<int, string>>  $selections
     * @return array<int, string>
     */
    public function selectionLines(array $selections): array
    {
        return collect($selections)
            ->map(function (array $candidateIds, string $contestId): string {
                $labels = collect($candidateIds)
                    ->map(fn (string $candidateId): string => $this->candidate($contestId, $candidateId))
                    ->implode(', ');

                return $this->contest($contestId).': '.$labels;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, int>>  $tally
     * @return array<int, string>
     */
    public function tallyLines(array $tally): array
    {
        $lines = [];

        foreach ($tally as $contestId => $totals) {
            $lines[] = strtoupper($this->contest((string) $contestId));

            foreach ($totals as $candidateId => $votes) {
                $lines[] = '  '.$this->candidate((string) $contestId, (string) $candidateId).": {$votes}";
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, array<string, int>>  $tally
     * @return array<int, string>
     */
    public function displayTallyLines(array $tally): array
    {
        $lines = [];

        foreach ($tally as $contestId => $totals) {
            $lines[] = strtoupper($this->contest((string) $contestId));

            if ($totals === []) {
                $lines[] = '  No votes recorded for this contest.';

                continue;
            }

            foreach ($totals as $candidateId => $votes) {
                $lines[] = '  '.$this->candidate((string) $contestId, (string) $candidateId).": {$votes}";
            }
        }

        return $lines;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function contestMap(): array
    {
        return collect($this->configuration()['contests'] ?? [])
            ->keyBy('id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function configuration(): array
    {
        return $this->storage->readJson('runtime/active-precinct.json');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function candidateMap(string $contestId): array
    {
        $contest = $this->contestMap()[$contestId] ?? null;

        if (! is_array($contest)) {
            return [];
        }

        return collect($contest['candidates'] ?? [])
            ->keyBy('id')
            ->all();
    }
}
