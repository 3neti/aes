<?php

namespace App\Election\Returns;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;

final class ElectionReturnLegalEvidenceService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly LifecycleState $lifecycle,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @param  array<string, mixed>  $return
     * @return array<string, mixed>
     */
    public function write(array $return): array
    {
        $runId = basename($this->storage->activeRunPath());
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $precinctId = (string) ($return['precinct_id'] ?? $configuration['precinct_id'] ?? 'unknown');
        $artifactPath = 'returns/election-return-legal-evidence.json';

        $report = [
            'schema_version' => 'election-return-legal-evidence-1',
            'evidence_profile' => 'legal-election-return-v1',
            'generated_at' => $this->clock->now()->toIso8601String(),
            'run_id' => $runId,
            'precinct_id' => $precinctId,
            'stage' => $this->lifecycle->current(),
            'election_id' => $configuration['election_id'] ?? null,
            'mapping_hash' => $configuration['mapping_hash'] ?? null,
            'return_path' => $this->storage->path("returns/{$precinctId}-return.json"),
            'return_hash' => $return['return_hash'] ?? null,
            'accepted_ballots' => $return['accepted_ballots'] ?? null,
            'rejected_ballots' => $return['rejected_ballots'] ?? null,
            'tally_hash' => $return['tally_hash'] ?? null,
            'counts_match' => $this->countsMatch($return),
            'passed' => $this->lifecycle->current() === Lifecycle::ElectionReturn,
        ];

        $report['artifact_path'] = $this->storage->path($artifactPath);
        $report['evidence_hash'] = $this->json->hash($this->reportForHash($report));
        $this->storage->writeJson($artifactPath, $report);

        $this->journal->record('election_return_legal_evidence.generated', [
            'run_id' => $runId,
            'precinct_id' => $precinctId,
            'return_hash' => $report['return_hash'],
            'evidence_hash' => $report['evidence_hash'],
            'artifact_path' => $report['artifact_path'],
            'accepted_ballots' => $report['accepted_ballots'],
            'rejected_ballots' => $report['rejected_ballots'],
        ]);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $path = $this->storage->path('returns/election-return-legal-evidence.json');

        if (! file_exists($path)) {
            return [
                'exists' => false,
            ];
        }

        $artifact = $this->storage->readJson('returns/election-return-legal-evidence.json');

        return [
            'exists' => true,
            'run_id' => $artifact['run_id'] ?? null,
            'precinct_id' => $artifact['precinct_id'] ?? null,
            'generated_at' => $artifact['generated_at'] ?? null,
            'evidence_hash' => $artifact['evidence_hash'] ?? null,
            'accepted_ballots' => $artifact['accepted_ballots'] ?? null,
            'rejected_ballots' => $artifact['rejected_ballots'] ?? null,
            'tally_hash' => $artifact['tally_hash'] ?? null,
            'return_hash' => $artifact['return_hash'] ?? null,
            'return_path' => $artifact['return_path'] ?? null,
            'passed' => $artifact['passed'] ?? false,
            'counts_match' => $artifact['counts_match'] ?? false,
            'artifact' => $path,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function reportForHash(array $report): array
    {
        return [
            ...$report,
            'artifact_path' => null,
            'return_path' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $return
     */
    private function countsMatch(array $return): bool
    {
        $accepted = $return['accepted_ballots'] ?? null;
        $rejected = $return['rejected_ballots'] ?? null;
        $tally = $return['tally'] ?? [];

        if (! is_int($accepted) || ! is_int($rejected)) {
            return false;
        }

        if ($accepted < 0 || $rejected < 0) {
            return false;
        }

        $tallySelections = 0;

        foreach ($tally as $contestTallies) {
            if (! is_array($contestTallies)) {
                return false;
            }

            foreach ($contestTallies as $count) {
                if (! is_int($count)) {
                    return false;
                }

                if ($count < 0) {
                    return false;
                }

                $tallySelections += $count;
            }
        }

        return $tallySelections >= $accepted;
    }
}
