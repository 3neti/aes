<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\CanonicalJson;

final class PublicVvdatAuditExportVerifier
{
    public function __construct(private readonly CanonicalJson $json) {}

    /**
     * @return array{passed: bool, record_count: int, errors: array<int, string>, derived_tally_hash: string|null}
     */
    public function verify(string $contents): array
    {
        try {
            $export = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return $this->failed(0, ['The export is not valid JSON: '.$exception->getMessage()]);
        }

        if (! is_array($export)) {
            return $this->failed(0, ['The export must contain a JSON object.']);
        }

        $records = $export['records'] ?? [];
        $errors = [];

        if (! is_array($records) || ! is_int($export['record_count'] ?? null) || $export['record_count'] !== count($records)) {
            $errors[] = 'The exported record count does not match the record list.';
        }

        $expectedHash = $this->json->hash(collect($export)->except(['artifact_path', 'export_hash'])->all());

        if (! is_string($export['export_hash'] ?? null) || ! hash_equals($export['export_hash'], $expectedHash)) {
            $errors[] = 'The export hash does not match the export content.';
        }

        $recordHashes = [];
        $derived = [];

        foreach ($records as $record) {
            if (! is_array($record) || ! is_string($record['record_hash'] ?? null) || ! is_array($record['selections'] ?? null)) {
                $errors[] = 'A record is missing its hash or selections.';

                continue;
            }

            $recordHashes[] = $record['record_hash'];

            foreach ($record['selections'] as $contestId => $candidateIds) {
                if (! is_array($candidateIds)) {
                    $errors[] = 'A contest selection is malformed.';

                    continue;
                }

                foreach ($candidateIds as $candidateId) {
                    if (! is_string($candidateId)) {
                        $errors[] = 'A candidate selection is malformed.';

                        continue;
                    }

                    $derived[(string) $contestId][$candidateId] = ($derived[(string) $contestId][$candidateId] ?? 0) + 1;
                }
            }
        }

        if (count(array_unique($recordHashes)) !== count($recordHashes)) {
            $errors[] = 'The export contains a duplicate ledger record hash.';
        }

        $published = $this->nonZeroTally($export['published_tally'] ?? []);
        $derived = $this->nonZeroTally($derived);

        if ($published === [] || $this->json->hash($published) !== $this->json->hash($derived)) {
            $errors[] = 'The independently derived tally does not match the published tally.';
        }

        return [
            'passed' => $errors === [],
            'record_count' => is_array($records) ? count($records) : 0,
            'errors' => array_values(array_unique($errors)),
            'derived_tally_hash' => $this->json->hash($derived),
        ];
    }

    /**
     * @param  array<string, mixed>  $tally
     * @return array<string, array<string, int>>
     */
    private function nonZeroTally(array $tally): array
    {
        return collect($tally)
            ->filter(fn (mixed $candidates): bool => is_array($candidates))
            ->map(fn (array $candidates): array => collect($candidates)
                ->filter(fn (mixed $votes): bool => is_int($votes) && $votes > 0)
                ->map(fn (int $votes): int => $votes)
                ->all())
            ->filter(fn (array $candidates): bool => $candidates !== [])
            ->all();
    }

    /**
     * @param  array<int, string>  $errors
     * @return array{passed: false, record_count: int, errors: array<int, string>, derived_tally_hash: null}
     */
    private function failed(int $recordCount, array $errors): array
    {
        return [
            'passed' => false,
            'record_count' => $recordCount,
            'errors' => $errors,
            'derived_tally_hash' => null,
        ];
    }
}
