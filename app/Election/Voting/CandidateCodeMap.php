<?php

namespace App\Election\Voting;

use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionStorage;
use RuntimeException;

final class CandidateCodeMap
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly CanonicalJson $json,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function write(array $configuration): array
    {
        $manifest = $this->build($configuration);
        $manifest['artifact_path'] = $this->storage->path('mappings/candidate-code-map.json');
        $this->storage->writeJson('mappings/candidate-code-map.json', $manifest);

        return $manifest;
    }

    /**
     * @return array<string, mixed>
     */
    public function active(): array
    {
        $manifest = $this->storage->readJson('mappings/candidate-code-map.json');
        $configuration = $this->storage->readJson('runtime/active-precinct.json');

        if ($configuration === []) {
            throw new RuntimeException('No active precinct configuration.');
        }

        if (($manifest['mapping_hash'] ?? null) !== ($configuration['mapping_hash'] ?? null)) {
            return $this->write($configuration);
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    public function codesForPayload(array $payload): array
    {
        return $this->codesForSelections((array) ($payload['selections'] ?? []));
    }

    /**
     * @param  array<string, array<int, string>>  $selections
     * @return array<int, string>
     */
    public function codesForSelections(array $selections): array
    {
        $manifest = $this->active();
        $codesByCandidateId = collect($manifest['candidates'] ?? [])
            ->mapWithKeys(fn (array $candidate, string $code): array => [(string) $candidate['candidate_id'] => $code]);
        $codes = [];

        foreach ($selections as $candidateIds) {
            foreach ($candidateIds as $candidateId) {
                $code = $codesByCandidateId->get((string) $candidateId);

                if (! is_string($code)) {
                    throw new RuntimeException("Candidate [{$candidateId}] has no compact QR code mapping.");
                }

                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<string, array<int, string>>
     */
    public function selectionsForCodes(array $codes): array
    {
        $manifest = $this->active();
        $candidates = $manifest['candidates'] ?? [];
        $selections = [];

        foreach ($codes as $code) {
            $candidate = $candidates[$code] ?? null;

            if (! is_array($candidate)) {
                throw new RuntimeException("Candidate code [{$code}] is not present in this precinct mapping.");
            }

            $contestId = (string) $candidate['contest_id'];
            $selections[$contestId] ??= [];
            $selections[$contestId][] = (string) $candidate['candidate_id'];
        }

        return $selections;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function build(array $configuration): array
    {
        $candidates = [];
        $sequence = 1;

        foreach (($configuration['contests'] ?? []) as $contest) {
            if (! is_array($contest)) {
                continue;
            }

            foreach (($contest['candidates'] ?? []) as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                $code = sprintf('CAND%05d', $sequence);
                $candidates[$code] = [
                    'code' => $code,
                    'contest_id' => (string) ($contest['id'] ?? ''),
                    'contest_title' => (string) ($contest['title'] ?? $contest['id'] ?? ''),
                    'office' => $contest['office'] ?? $contest['title'] ?? null,
                    'geography' => $contest['geography'] ?? null,
                    'district' => $contest['district'] ?? null,
                    'candidate_id' => (string) ($candidate['id'] ?? ''),
                    'name' => (string) ($candidate['name'] ?? $candidate['id'] ?? ''),
                    'party' => $candidate['political_party'] ?? null,
                    'ballot_number' => $candidate['ballot_number'] ?? $candidate['ordinal'] ?? null,
                    'locality' => $contest['geography'] ?? $contest['district'] ?? null,
                ];
                $sequence++;
            }
        }

        $manifest = [
            'schema_version' => 'candidate-code-map-1',
            'election_id' => $configuration['election_id'] ?? null,
            'precinct_id' => $configuration['precinct_id'] ?? null,
            'ballot_style_id' => $configuration['ballot_style_id'] ?? null,
            'mapping_hash' => $configuration['mapping_hash'] ?? null,
            'candidate_count' => count($candidates),
            'candidates' => $candidates,
        ];
        $manifest['candidate_code_map_hash'] = $this->json->hash($manifest);

        return $manifest;
    }
}
