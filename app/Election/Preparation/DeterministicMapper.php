<?php

namespace App\Election\Preparation;

use App\Election\Core\CanonicalJson;
use RuntimeException;

final class DeterministicMapper
{
    public function __construct(private readonly CanonicalJson $json) {}

    /**
     * @param  array<string, mixed>  $registries
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    public function derive(array $registries, array $package): array
    {
        $style = collect($registries['ballot_styles'])->firstWhere('id', $package['ballot_style_id']);

        if (! is_array($style)) {
            throw new RuntimeException('Ballot style not found.');
        }

        $contests = collect($style['contest_ids'])
            ->map(function (string $contestId) use ($registries): array {
                $contest = collect($registries['contests'])->firstWhere('id', $contestId);

                if (! is_array($contest)) {
                    throw new RuntimeException("Contest [{$contestId}] not found.");
                }

                $candidates = collect($contest['candidate_ids'])
                    ->values()
                    ->map(function (string $candidateId, int $index) use ($registries): array {
                        $candidate = collect($registries['candidates'])->firstWhere('id', $candidateId);

                        if (! is_array($candidate)) {
                            throw new RuntimeException("Candidate [{$candidateId}] not found.");
                        }

                        return [
                            'id' => $candidate['id'],
                            'name' => $candidate['name'],
                            'ordinal' => $index + 1,
                        ];
                    })
                    ->all();

                return [
                    'id' => $contest['id'],
                    'title' => $contest['title'],
                    'max_selections' => $contest['max_selections'],
                    'candidates' => $candidates,
                ];
            })
            ->all();

        $configuration = [
            'schema_version' => 'precinct-configuration-1',
            'election_id' => $package['election_id'],
            'precinct_id' => $package['precinct_id'],
            'ballot_style_id' => $package['ballot_style_id'],
            'contests' => $contests,
        ];

        $configuration['mapping_hash'] = $this->json->hash($configuration);

        return $configuration;
    }
}
