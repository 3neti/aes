<?php

namespace App\Election\Preparation;

use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionStorage;
use RuntimeException;

final class PrecinctCandidateResolver
{
    public function __construct(
        private readonly PopPrecinctRegistry $pop,
        private readonly ClcCandidateRegistry $clc,
        private readonly ElectionStorage $storage,
        private readonly CanonicalJson $json,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $clusteredPrecinct, ?string $district = null, bool $writeReport = false): array
    {
        $precinct = $this->pop->find($clusteredPrecinct);
        $manifest = $this->clc->manifest();
        $city = $this->normalize($this->candidateLocality((string) $precinct['city_municipality']));
        $candidates = collect($this->clc->candidates());
        $districts = $candidates
            ->filter(fn (array $candidate): bool => $candidate['scope'] === 'district' && str_contains($this->normalize((string) $candidate['geography']), $city))
            ->pluck('district')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($district === null && count($districts) > 1) {
            throw new RuntimeException('District is required for this precinct. Available districts: '.implode(', ', $districts).'.');
        }

        $selected = $candidates
            ->filter(function (array $candidate) use ($city, $district): bool {
                if ($candidate['scope'] === 'national') {
                    return true;
                }

                $geography = $this->normalize((string) $candidate['geography']);

                if (! str_contains($geography, $city)) {
                    return false;
                }

                return $candidate['scope'] !== 'district' || $district === null || $candidate['district'] === $district;
            })
            ->values()
            ->all();

        $contests = collect($selected)
            ->groupBy(fn (array $candidate): string => implode('|', [
                $candidate['scope'],
                $candidate['geography'],
                $candidate['office'],
                $candidate['district'] ?? '',
            ]))
            ->map(fn ($group): array => [
                'scope' => $group->first()['scope'],
                'geography' => $group->first()['geography'],
                'district' => $group->first()['district'],
                'office' => $group->first()['office'],
                'candidates' => $group->values()->all(),
            ])
            ->values()
            ->all();

        $report = [
            'schema_version' => 'precinct-candidates-report-1',
            'clustered_precinct' => $clusteredPrecinct,
            'precinct' => $precinct,
            'clc_registry_hash' => $manifest['registry_hash'] ?? null,
            'clc_manifest_hash' => $manifest['manifest_hash'] ?? null,
            'needs_review_count' => $manifest['needs_review_count'] ?? 0,
            'district' => $district,
            'available_districts' => $districts,
            'contest_count' => count($contests),
            'candidate_count' => count($selected),
            'contests' => $contests,
        ];
        $report['report_hash'] = $this->json->hash($report);

        if ($writeReport) {
            $jsonPath = $this->storage->writeJson("precinct-candidates/{$clusteredPrecinct}-candidates.json", $report);
            $textPath = $this->storage->writeText("precinct-candidates/{$clusteredPrecinct}-candidates.txt", $this->text($report));
            $report['artifact_path'] = $jsonPath;
            $report['text_artifact_path'] = $textPath;
        }

        return $report;
    }

    private function normalize(string $value): string
    {
        $value = strtoupper($value);
        $value = str_replace(['CITY OF ', 'MUNICIPALITY OF '], '', $value);

        return trim((string) preg_replace('/[^A-Z0-9]+/', ' ', $value));
    }

    private function candidateLocality(string $cityMunicipality): string
    {
        $aliases = config('election.clc.precinct_aliases', []);
        $key = strtoupper(trim($cityMunicipality));

        return is_array($aliases) && isset($aliases[$key]) ? (string) $aliases[$key] : $cityMunicipality;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function text(array $report): string
    {
        $lines = [
            'PRECINCT CANDIDATES',
            'Clustered precinct: '.$report['clustered_precinct'],
            'Location: '.$report['precinct']['city_municipality'].', '.$report['precinct']['province'],
            'Candidates: '.$report['candidate_count'],
            'Needs review: '.$report['needs_review_count'],
            'Report hash: '.$report['report_hash'],
            '',
        ];

        foreach ($report['contests'] as $contest) {
            $lines[] = $contest['office'].' - '.$contest['geography'];

            foreach ($contest['candidates'] as $candidate) {
                $lines[] = '  '.$candidate['ballot_number'].'. '.$candidate['name_on_ballot'];
            }

            $lines[] = '';
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
