<?php

namespace App\Election\Minutes;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;

final class OfficialMinutesBaselineService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function write(): array
    {
        $runPath = $this->storage->activeRunPath();
        $runPathTail = basename($runPath);
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $precinct = (string) ($configuration['precinct_id'] ?? 'unknown');
        $journalEntries = $this->journal->entries();

        $attestations = $this->collectAttestationMinutes();
        $journalMinutes = $this->collectJournalMinutes($journalEntries);

        $minutes = collect()
            ->merge($attestations)
            ->merge($journalMinutes)
            ->sortBy([fn (array $entry): int => $entry['sort_timestamp'], fn (array $entry): int => $entry['sort_sequence']])
            ->values()
            ->map(function (array $entry, int $index): array {
                $entry['minute_sequence'] = $index + 1;

                return $entry;
            })
            ->values()
            ->all();

        $report = [
            'schema_version' => 'official-minutes-baseline-1',
            'baseline_profile' => 'official-minutes-v1',
            'generated_at' => $this->clock->now()->toIso8601String(),
            'run_id' => $runPathTail,
            'precinct_id' => $precinct,
            'run_path' => $runPath,
            'source_journal_event_count' => count($journalEntries),
            'source_attestation_count' => count($attestations),
            'minute_count' => count($minutes),
            'minutes' => collect($minutes)
                ->map(fn (array $entry): array => [
                    'minute_sequence' => $entry['minute_sequence'],
                    'source_type' => $entry['source_type'],
                    'source_reference' => $entry['source_reference'],
                    'occurred_at' => $entry['occurred_at'],
                    'ceremony' => $entry['ceremony'],
                    'stage' => $entry['stage'],
                    'actor' => $entry['actor'],
                    'event_type' => $entry['event_type'],
                    'artifact' => $entry['artifact'],
                    'journal_event_sequence' => $entry['journal_event_sequence'],
                    'journal_event_hash' => $entry['journal_event_hash'],
                    'summary' => $entry['summary'],
                ])
                ->all(),
        ];

        $report['official_minute_hash'] = $this->json->hash($report);
        $report['artifact_path'] = $this->storage->writeJson('diagnostics/official-minutes-baseline.json', $report);

        $this->journal->record('official_minutes_baseline.generated', [
            'run_id' => $runPathTail,
            'precinct_id' => $precinct,
            'minute_count' => $report['minute_count'],
            'source_journal_event_count' => $report['source_journal_event_count'],
            'source_attestation_count' => $report['source_attestation_count'],
            'official_minute_hash' => $report['official_minute_hash'],
        ]);

        return $report;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectAttestationMinutes(): array
    {
        $journalEntriesByAttestation = collect($this->journal->entries())
            ->filter(fn (array $entry): bool => $entry['event_type'] === 'officer.attested')
            ->keyBy(fn (array $entry): string => (string) ($entry['payload']['attestation_id'] ?? ''));

        return collect($this->storage->files('attestations'))
            ->map(function (string $path, int $index) use ($journalEntriesByAttestation): array {
                if (! $this->files->exists($path)) {
                    return [];
                }

                $record = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
                $attestationId = (string) ($record['attestation_id'] ?? basename($path));
                $linkedJournal = $journalEntriesByAttestation->get($attestationId);
                $sortSequence = $this->parseAttestationSequence($attestationId, $index);

                return [
                    'source_type' => 'attestation',
                    'source_reference' => basename($path),
                    'sort_timestamp' => $this->sortTimestamp($record['attested_at'] ?? null, 0),
                    'sort_sequence' => $sortSequence,
                    'occurred_at' => $record['attested_at'] ?? null,
                    'ceremony' => $record['ceremony'] ?? null,
                    'stage' => $record['stage'] ?? null,
                    'actor' => $record['officer_name'] ?? null,
                    'event_type' => 'official_attestation_recorded',
                    'artifact' => basename($path),
                    'journal_event_sequence' => $linkedJournal['sequence'] ?? null,
                    'journal_event_hash' => $linkedJournal['event_hash'] ?? null,
                    'summary' => (string) ($record['statement'] ?? ''),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $journalEntries
     * @return array<int, array<string, mixed>>
     */
    private function collectJournalMinutes(array $journalEntries): array
    {
        return collect($journalEntries)
            ->map(function (array $entry): array {
                $actor = $entry['payload']['officer_name'] ?? ($entry['payload']['officer'] ?? ($entry['payload']['operator'] ?? null));

                return [
                    'source_type' => 'activity_journal',
                    'source_reference' => (string) ($entry['event_hash'] ?? ''),
                    'sort_timestamp' => $this->sortTimestamp($entry['occurred_at'] ?? null, $entry['sequence'] ?? 0),
                    'sort_sequence' => (int) ($entry['sequence'] ?? 0),
                    'occurred_at' => $entry['occurred_at'] ?? null,
                    'ceremony' => $this->journalCeremony($entry['event_type'] ?? ''),
                    'stage' => $entry['payload']['stage'] ?? null,
                    'actor' => is_string($actor) ? $actor : null,
                    'event_type' => (string) $entry['event_type'],
                    'artifact' => null,
                    'journal_event_sequence' => (int) ($entry['sequence'] ?? 0),
                    'journal_event_hash' => (string) ($entry['event_hash'] ?? ''),
                    'summary' => $entry['event_type'] === '' ? null : (string) $entry['event_type'],
                ];
            })
            ->values()
            ->all();
    }

    private function journalCeremony(string $eventType): ?string
    {
        return match ($eventType) {
            'precinct.opened', 'polls.opened', 'polls.closed' => 'Opening and Closing',
            'counting.started', 'ballot.finalized', 'ballot.counted', 'ballot.rejected' => 'Voting and Counting',
            'return.generated', 'return.generated_and_saved' => 'Election Return',
            'transmission.completed', 'transmission.failed', 'transmission.retrying' => 'Transmission',
            'custody.recorded' => 'Custody',
            'lifecycle.stage_set' => 'Lifecycle',
            default => null,
        };
    }

    private function parseAttestationSequence(string $attestationId, int $fallbackIndex): int
    {
        $matches = [];
        $matched = preg_match('/(?:attestation-)(\\d+)/', $attestationId, $matches);

        if ($matched === 1 && isset($matches[1])) {
            return (int) $matches[1];
        }

        return $fallbackIndex + 1;
    }

    private function sortTimestamp(?string $occurredAt, int $fallback): int
    {
        if (! is_string($occurredAt)) {
            return $fallback;
        }

        $timestamp = strtotime($occurredAt);

        return $timestamp === false ? $fallback : $timestamp;
    }
}
