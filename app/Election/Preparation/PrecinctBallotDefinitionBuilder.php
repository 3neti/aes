<?php

namespace App\Election\Preparation;

use App\Election\Core\CanonicalJson;
use Illuminate\Support\Str;
use RuntimeException;

final class PrecinctBallotDefinitionBuilder
{
    public function __construct(
        private readonly PrecinctCandidateResolver $resolver,
        private readonly CanonicalJson $json,
    ) {}

    /**
     * @return array{registries: array<string, mixed>, package: array<string, mixed>, report: array<string, mixed>}
     */
    public function build(string $clusteredPrecinct, ?string $district = null): array
    {
        $district ??= (string) config('election.pop.district');
        $report = $this->resolver->resolve($clusteredPrecinct, $district);
        $contestRules = config('election.pop.contest_rules', []);
        $contests = collect($report['contests'])
            ->filter(fn (array $contest): bool => $this->isSupportedContest((string) $contest['office']))
            ->sortBy(fn (array $contest): int => $this->contestOrder((string) $contest['office']))
            ->values()
            ->map(function (array $contest) use ($contestRules): array {
                $office = (string) $contest['office'];
                $maxSelections = $contestRules[$office] ?? null;

                if (! is_int($maxSelections)) {
                    throw new RuntimeException("No selection rule configured for [{$office}].");
                }

                $contestId = $this->contestId($contest);
                $candidates = collect($contest['candidates'])
                    ->sortBy(fn (array $candidate): int => (int) $candidate['ballot_number'])
                    ->values();

                $candidateLimit = $this->candidateLimit($office);

                if ($candidateLimit > 0) {
                    $candidates = $candidates->take($candidateLimit)->values();
                }

                $candidates = $candidates
                    ->map(fn (array $candidate): array => $this->candidate($contestId, $candidate))
                    ->all();

                return [
                    'id' => $contestId,
                    'title' => $this->contestTitle($contest),
                    'office' => $office,
                    'geography' => $contest['geography'],
                    'district' => $contest['district'],
                    'max_selections' => $maxSelections,
                    'candidate_ids' => collect($candidates)->pluck('id')->all(),
                    'candidates' => $candidates,
                ];
            })
            ->all();

        if (count($contests) !== 6) {
            throw new RuntimeException("Expected 6 contests for precinct [{$clusteredPrecinct}], found ".count($contests).'.');
        }

        $candidates = collect($contests)
            ->flatMap(fn (array $contest): array => $contest['candidates'])
            ->values()
            ->all();

        $ballotStyleId = 'BS-2025NLE-'.$clusteredPrecinct;
        $registries = [
            'schema_version' => 'precinct-clc-ballot-1',
            'election_id' => '2025NLE-POP-CLC',
            'precincts' => [
                [
                    'id' => $clusteredPrecinct,
                    'name' => $report['precinct']['polling_place'] ?? $clusteredPrecinct,
                    'ballot_style_id' => $ballotStyleId,
                ],
            ],
            'ballot_styles' => [
                [
                    'id' => $ballotStyleId,
                    'contest_ids' => collect($contests)->pluck('id')->all(),
                ],
            ],
            'contests' => collect($contests)
                ->map(fn (array $contest): array => [
                    'id' => $contest['id'],
                    'title' => $contest['title'],
                    'office' => $contest['office'],
                    'geography' => $contest['geography'],
                    'district' => $contest['district'],
                    'max_selections' => $contest['max_selections'],
                    'candidate_ids' => $contest['candidate_ids'],
                ])
                ->all(),
            'candidates' => $candidates,
            'source' => [
                'clustered_precinct' => $clusteredPrecinct,
                'district' => $district,
                'pop_precinct' => $report['precinct'],
                'clc_registry_hash' => $report['clc_registry_hash'],
                'clc_manifest_hash' => $report['clc_manifest_hash'],
                'precinct_candidate_report_hash' => $report['report_hash'],
            ],
        ];

        $package = [
            'schema_version' => 'precinct-clc-package-1',
            'election_id' => $registries['election_id'],
            'precinct_id' => $clusteredPrecinct,
            'ballot_style_id' => $ballotStyleId,
            'registry_version' => ClcCandidateImporter::registryVersion(),
            'transport' => 'pop-workbook-import-with-clc-ballot-definition',
            'signature' => 'UNSIGNED-POP-CLC-SIMULATION',
            'location' => [
                'region' => $report['precinct']['region'] ?? null,
                'province' => $report['precinct']['province'] ?? null,
                'city_municipality' => $report['precinct']['city_municipality'] ?? null,
                'barangay' => $report['precinct']['barangay'] ?? null,
                'polling_place' => $report['precinct']['polling_place'] ?? null,
            ],
            'source' => $registries['source'],
        ];
        $package['package_hash'] = $this->json->hash($package);
        $package['registry_hash'] = $this->json->hash($registries);

        return [
            'registries' => $registries,
            'package' => $package,
            'report' => [
                'schema_version' => 'ballot-definition-report-1',
                'clustered_precinct' => $clusteredPrecinct,
                'district' => $district,
                'contest_count' => count($contests),
                'candidate_count' => count($candidates),
                'contests' => collect($contests)
                    ->map(fn (array $contest): array => [
                        'id' => $contest['id'],
                        'title' => $contest['title'],
                        'office' => $contest['office'],
                        'geography' => $contest['geography'],
                        'district' => $contest['district'],
                        'max_selections' => $contest['max_selections'],
                        'candidate_count' => count($contest['candidate_ids']),
                    ])
                    ->all(),
                'registry_hash' => $package['registry_hash'],
                'package_hash' => $package['package_hash'],
                'clc_registry_hash' => $report['clc_registry_hash'],
                'clc_manifest_hash' => $report['clc_manifest_hash'],
                'precinct_candidate_report_hash' => $report['report_hash'],
            ],
        ];
    }

    private function isSupportedContest(string $office): bool
    {
        return in_array($office, [
            'SENATOR',
            'PARTY LIST',
            'MEMBER, HOUSE OF REPRESENTATIVES',
            'MAYOR',
            'VICE-MAYOR',
            'COUNCILOR',
        ], true);
    }

    private function contestOrder(string $office): int
    {
        return match ($office) {
            'SENATOR' => 10,
            'PARTY LIST' => 20,
            'MEMBER, HOUSE OF REPRESENTATIVES' => 30,
            'MAYOR' => 40,
            'VICE-MAYOR' => 50,
            'COUNCILOR' => 60,
            default => 99,
        };
    }

    private function candidateLimit(string $office): int
    {
        $limits = config('election.pop.candidate_limits', []);
        $limit = is_array($limits) ? ($limits[$office] ?? 0) : 0;

        return max(0, (int) $limit);
    }

    /**
     * @param  array<string, mixed>  $contest
     */
    private function contestId(array $contest): string
    {
        return (string) Str::of(implode('-', [
            $contest['office'],
            $contest['geography'],
            $contest['district'] ?? '',
        ]))->ascii()->slug('_');
    }

    /**
     * @param  array<string, mixed>  $contest
     */
    private function contestTitle(array $contest): string
    {
        $parts = array_filter([
            $contest['office'],
            $contest['geography'],
            $contest['district'],
        ]);

        return implode(' - ', $parts);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function candidate(string $contestId, array $candidate): array
    {
        $id = (string) Str::of($contestId.'-'.$candidate['ballot_number'].'-'.substr((string) $candidate['candidate_hash'], 0, 12))
            ->ascii()
            ->slug('_');

        return [
            'id' => $id,
            'name' => $candidate['name_on_ballot'],
            'ballot_number' => $candidate['ballot_number'],
            'full_name' => $candidate['full_name'],
            'political_party' => $candidate['political_party'],
            'source_file' => $candidate['source_file'],
            'source_page' => $candidate['source_page'],
            'candidate_hash' => $candidate['candidate_hash'],
            'candidate_image' => $candidate['candidate_image'],
        ];
    }
}
