<?php

namespace App\Election\Counting;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;

final class CountingLegalEvidenceService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly LifecycleState $lifecycle,
        private readonly ActivityJournal $journal,
        private readonly CountingReconciliationService $reconciliation,
    ) {}

    public function writeForClosePolls(): array
    {
        $runId = basename($this->storage->activeRunPath());
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $artifactPath = 'closing/close-polls-legal-evidence.json';
        $acceptedBallots = count($this->storage->files('counting/accepted'));
        $rejectedBallots = count($this->storage->files('counting/rejected'));

        $report = [
            'schema_version' => 'close-polls-legal-evidence-1',
            'evidence_profile' => 'legal-close-polls-v1',
            'generated_at' => $this->clock->now()->toIso8601String(),
            'run_id' => $runId,
            'precinct_id' => $configuration['precinct_id'] ?? null,
            'stage' => $this->lifecycle->current(),
            'election_id' => $configuration['election_id'] ?? null,
            'mapping_hash' => $configuration['mapping_hash'] ?? null,
            'accepted_ballots_before_counting' => $acceptedBallots,
            'rejected_ballots_before_counting' => $rejectedBallots,
            'tally_sheet_exists' => $this->storage->readJson('runtime/tally.json') !== [],
        ];

        $report['evidence_hash'] = $this->json->hash($this->reportForHash($report));
        $report['artifact_path'] = $this->storage->writeJson($artifactPath, $report);

        $this->journal->record('counting_legal_evidence.close_polls_generated', [
            'run_id' => $runId,
            'precinct_id' => $configuration['precinct_id'] ?? null,
            'evidence_hash' => $report['evidence_hash'],
            'artifact_path' => $report['artifact_path'],
        ]);

        return $report;
    }

    public function writeForCompletion(array $tally): array
    {
        $runId = basename($this->storage->activeRunPath());
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $artifactPath = 'counting/counting-legal-evidence.json';
        $acceptedBallots = (int) ($tally['accepted_ballots'] ?? count($this->storage->files('counting/accepted')));
        $rejectedBallots = (int) ($tally['rejected_ballots'] ?? count($this->storage->files('counting/rejected')));
        $reconciliation = $this->reconciliation->summary();

        $report = [
            'schema_version' => 'counting-legal-evidence-1',
            'evidence_profile' => 'legal-counting-v1',
            'generated_at' => $this->clock->now()->toIso8601String(),
            'run_id' => $runId,
            'precinct_id' => $configuration['precinct_id'] ?? null,
            'stage' => $this->lifecycle->current(),
            'election_id' => $configuration['election_id'] ?? null,
            'mapping_hash' => $configuration['mapping_hash'] ?? null,
            'accepted_ballots' => $acceptedBallots,
            'rejected_ballots' => $rejectedBallots,
            'tally_hash' => $tally['tally_hash'] ?? null,
            'tally' => $tally['tally'] ?? [],
            'reconciliation' => $reconciliation,
            'passed' => $this->lifecycle->current() === Lifecycle::Counting && $reconciliation['passed'],
        ];

        $report['evidence_hash'] = $this->json->hash($this->reportForHash($report));
        $report['artifact_path'] = $this->storage->writeJson($artifactPath, $report);

        $this->journal->record('counting_legal_evidence.counting_completed', [
            'run_id' => $runId,
            'precinct_id' => $configuration['precinct_id'] ?? null,
            'accepted_ballots' => $acceptedBallots,
            'rejected_ballots' => $rejectedBallots,
            'evidence_hash' => $report['evidence_hash'],
            'artifact_path' => $report['artifact_path'],
        ]);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function closePollsSummary(): array
    {
        $path = $this->storage->path('closing/close-polls-legal-evidence.json');
        $artifact = $this->readIfExists('closing/close-polls-legal-evidence.json');

        return [
            'exists' => $artifact !== null,
            'run_id' => $artifact['run_id'] ?? null,
            'precinct_id' => $artifact['precinct_id'] ?? null,
            'generated_at' => $artifact['generated_at'] ?? null,
            'evidence_hash' => $artifact['evidence_hash'] ?? null,
            'accepted_ballots_before_counting' => $artifact['accepted_ballots_before_counting'] ?? null,
            'rejected_ballots_before_counting' => $artifact['rejected_ballots_before_counting'] ?? null,
            'artifact' => $path,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function countingSummary(): array
    {
        $path = $this->storage->path('counting/counting-legal-evidence.json');
        $artifact = $this->readIfExists('counting/counting-legal-evidence.json');

        return [
            'exists' => $artifact !== null,
            'run_id' => $artifact['run_id'] ?? null,
            'precinct_id' => $artifact['precinct_id'] ?? null,
            'generated_at' => $artifact['generated_at'] ?? null,
            'evidence_hash' => $artifact['evidence_hash'] ?? null,
            'accepted_ballots' => $artifact['accepted_ballots'] ?? null,
            'rejected_ballots' => $artifact['rejected_ballots'] ?? null,
            'tally_hash' => $artifact['tally_hash'] ?? null,
            'reconciliation_passed' => $artifact['reconciliation']['passed'] ?? false,
            'physical_ballots' => $artifact['reconciliation']['physical_ballots'] ?? null,
            'unresolved_rejections' => $artifact['reconciliation']['unresolved_rejections'] ?? null,
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
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readIfExists(string $relative): ?array
    {
        $path = $this->storage->path($relative);

        if (! file_exists($path)) {
            return null;
        }

        return $this->storage->readJson($relative);
    }
}
