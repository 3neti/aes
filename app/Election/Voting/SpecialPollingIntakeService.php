<?php

namespace App\Election\Voting;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Validation\ValidationException;

final class SpecialPollingIntakeService
{
    /**
     * @var array<int, array<string, string>>
     */
    public const TYPES = [
        [
            'value' => 'ppp',
            'label' => 'PPP/S-PPP',
        ],
        [
            'value' => 'pdl',
            'label' => 'Persons with Disabilities (PDL)',
        ],
        [
            'value' => 'ip',
            'label' => 'Indigenous Peoples',
        ],
    ];

    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly LifecycleState $lifecycle,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function record(array $input): array
    {
        $stage = (string) $input['stage'];
        $this->assertStage($stage);

        $current = $this->latest();
        $entries = $current['entries'] ?? [];
        $sequence = count($entries) + 1;
        $intakeType = (string) $input['intake_type'];
        $precinct = (string) ($this->storage->readJson('runtime/active-precinct.json')['precinct_id'] ?? '0421-A');
        $configuration = $this->storage->readJson('runtime/active-precinct.json');

        $entry = [
            'sequence' => $sequence,
            'intake_id' => sprintf(
                'special-polling-intake-%s-%s',
                $this->clock->now()->format('YmdHis'),
                str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            ),
            'run_id' => basename($this->storage->activeRunPath()),
            'precinct_id' => $precinct,
            'election_id' => $configuration['election_id'] ?? null,
            'mapping_hash' => $configuration['mapping_hash'] ?? null,
            'lifecycle_stage' => $this->lifecycle->current(),
            'recorded_at' => $this->clock->now()->toIso8601String(),
            'intake_type' => $intakeType,
            'intake_type_label' => $this->labelForType($intakeType),
            'ballot_count' => (int) $input['ballot_count'],
            'received_from' => trim((string) $input['received_from']),
            'received_by' => trim((string) $input['received_by']),
            'notes' => trim((string) $input['notes']),
        ];

        $entry['entry_hash'] = $this->json->hash($this->entryForHash($entry));
        $entries[] = $entry;

        $report = [
            'schema_version' => 'special-polling-intake-1',
            'artifact_profile' => 'special-polling-v1',
            'run_id' => basename($this->storage->activeRunPath()),
            'precinct_id' => $precinct,
            'election_id' => $configuration['election_id'] ?? null,
            'mapping_hash' => $configuration['mapping_hash'] ?? null,
            'generated_at' => $this->clock->now()->toIso8601String(),
            'stage' => $this->lifecycle->current(),
            'entry_count' => count($entries),
            'total_ballots' => collect($entries)->sum('ballot_count'),
            'totals_by_type' => $this->totalsByType($entries),
            'entries' => $entries,
            'entry_paths' => collect($entries)->map(fn (array $entry): string => $this->entryPath($entry['intake_id']))->all(),
        ];

        $report['special_polling_intake_hash'] = $this->json->hash($this->reportForHash($report));
        $report['artifact_path'] = $this->storage->path('voting/special-polling-intake.json');
        $this->storage->writeJson('voting/special-polling-intake.json', $report);

        $entryPath = $this->entryPath($entry['intake_id']);
        $this->storage->writeJson($entryPath, $entry);

        $this->journal->record('voting.special_polling_intake.recorded', [
            'run_id' => $report['run_id'],
            'precinct_id' => $precinct,
            'intake_id' => $entry['intake_id'],
            'intake_type' => $intakeType,
            'ballot_count' => $entry['ballot_count'],
            'entry_hash' => $entry['entry_hash'],
            'artifact_path' => $report['artifact_path'],
        ]);

        return [
            ...$entry,
            'artifact_path' => $report['artifact_path'],
            'entry_path' => $this->storage->path($entryPath),
            'entry_count' => $report['entry_count'],
            'total_ballots' => $report['total_ballots'],
            'special_polling_intake_hash' => $report['special_polling_intake_hash'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $path = $this->storage->path('voting/special-polling-intake.json');
        if (! file_exists($path)) {
            return [
                'exists' => false,
                'generate_url' => route('election.voting.special-polling-intake'),
            ];
        }

        $artifact = $this->storage->readJson('voting/special-polling-intake.json');

        return [
            'exists' => true,
            'artifact_path' => $path,
            'artifact' => basename($path),
            'run_id' => $artifact['run_id'] ?? null,
            'precinct_id' => $artifact['precinct_id'] ?? null,
            'generated_at' => $artifact['generated_at'] ?? null,
            'entry_count' => $artifact['entry_count'] ?? 0,
            'total_ballots' => $artifact['total_ballots'] ?? 0,
            'totals_by_type' => $artifact['totals_by_type'] ?? [],
            'latest_entry_hash' => collect($artifact['entries'] ?? [])->last()['entry_hash'] ?? null,
            'entries' => $artifact['entries'] ?? [],
            'special_polling_intake_hash' => $artifact['special_polling_intake_hash'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function latest(): array
    {
        return $this->storage->readJson('voting/special-polling-intake.json');
    }

    private function assertStage(string $stage): void
    {
        if (! in_array($stage, [Lifecycle::Voting, Lifecycle::ClosePolls], true)) {
            throw ValidationException::withMessages([
                'stage' => 'Special polling intake is only allowed before results are generated.',
            ]);
        }

        if ($stage !== $this->lifecycle->current()) {
            throw ValidationException::withMessages([
                'stage' => 'The ceremony stage changed. Reload and continue from the current stage.',
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, int>
     */
    private function totalsByType(array $entries): array
    {
        return collect($entries)
            ->groupBy(fn (array $entry): string => (string) $entry['intake_type'])
            ->map(fn ($buckets): int => collect($buckets)->sum('ballot_count'))
            ->all();
    }

    private function labelForType(string $type): string
    {
        foreach (self::TYPES as $candidateType) {
            if ($candidateType['value'] === $type) {
                return $candidateType['label'];
            }
        }

        return strtoupper($type);
    }

    private function entryPath(string $intakeId): string
    {
        return 'voting/special-polling-intake-records/'.$intakeId.'.json';
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function entryForHash(array $entry): array
    {
        return [
            ...$entry,
            'entry_hash' => null,
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
}
