<?php

namespace App\Election\Returns;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use RuntimeException;

final class ElectionReturnCopyDistributionService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @param  array<string, mixed>|null  $return
     * @return array<string, mixed>
     */
    public function prepare(?array $return = null): array
    {
        $return = $return ?? $this->latestReturn();
        $precinct = (string) ($return['precinct_id'] ?? 'unknown');

        $distribution = $this->build($return);
        $distribution['artifact_path'] = $this->storage->writeJson("returns/{$precinct}-copy-distribution.json", $distribution);

        $this->journal->record('return.copy_distribution_prepared', [
            'precinct_id' => $distribution['precinct_id'],
            'return_hash' => $distribution['return_hash'],
            'copy_count' => $distribution['copy_count'],
            'required_copy_count' => $distribution['required_copy_count'],
            'distribution_hash' => $distribution['distribution_hash'],
        ]);

        return $distribution;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $precinct = (string) ($configuration['precinct_id'] ?? '0421-A');
        $distribution = $this->storage->readJson("returns/{$precinct}-copy-distribution.json");

        if ($distribution !== []) {
            return [
                'exists' => true,
                'distribution_path' => "returns/{$precinct}-copy-distribution.json",
                'distribution_hash' => $distribution['distribution_hash'] ?? null,
                'copy_count' => $distribution['copy_count'] ?? 0,
                'required_copy_count' => $distribution['required_copy_count'] ?? 0,
                'posting' => $distribution['posting'] ?? null,
            ];
        }

        return ['exists' => false];
    }

    /**
     * @param  array<string, mixed>  $return
     * @return array<string, mixed>
     */
    private function build(array $return): array
    {
        $precinct = (string) ($return['precinct_id'] ?? 'unknown');
        $timestamp = $this->clock->now()->toIso8601String();

        $distribution = [
            'schema_version' => 'election-return-copy-distribution-1',
            'distribution_profile' => 'legal-returns-copy-distribution-v1',
            'generated_at' => $timestamp,
            'election_id' => $return['election_id'] ?? null,
            'precinct_id' => $precinct,
            'run_id' => (string) ($this->storage->currentRun()['run_id'] ?? ''),
            'return_hash' => $return['return_hash'] ?? null,
            'copy_count' => 3,
            'required_copy_count' => 2,
            'copies' => [
                [
                    'copy_id' => 'official_board',
                    'recipient' => 'Election Board',
                    'recipient_role' => 'Election Board Officer',
                    'purpose' => 'Official Election Return Copy',
                    'required' => true,
                    'prepared_at' => $timestamp,
                    'status' => 'prepared',
                ],
                [
                    'copy_id' => 'watchers',
                    'recipient' => 'Poll Watchers',
                    'recipient_role' => 'Watcher',
                    'purpose' => 'Public Witness Copy',
                    'required' => true,
                    'prepared_at' => $timestamp,
                    'status' => 'prepared',
                ],
                [
                    'copy_id' => 'precinct_file',
                    'recipient' => 'Precinct Records',
                    'recipient_role' => 'Custodian',
                    'purpose' => 'Audit and retention file',
                    'required' => false,
                    'prepared_at' => $timestamp,
                    'status' => 'prepared',
                ],
            ],
            'posting' => [
                'location' => 'Public Notice Board',
                'status' => 'completed',
                'posted_at' => $timestamp,
                'method' => 'manual',
                'document' => "{$precinct}-return.pdf",
            ],
        ];

        $distribution['distribution_hash'] = $this->json->hash($distribution);

        return $distribution;
    }

    /**
     * @return array<string, mixed>
     */
    private function latestReturn(): array
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $precinct = (string) ($configuration['precinct_id'] ?? '0421-A');
        $return = $this->storage->readJson("returns/{$precinct}-return.json");

        if ($return === []) {
            throw new RuntimeException('No election return exists yet.');
        }

        return $return;
    }
}
