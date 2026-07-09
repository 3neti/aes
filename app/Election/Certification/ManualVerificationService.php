<?php

namespace App\Election\Certification;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionStorage;

final class ManualVerificationService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @param  array<string, mixed>  $manualReturn
     * @return array<string, mixed>
     */
    public function run(array $manualReturn): array
    {
        $certification = $this->storage->readJson('certification/friday-certification-report.json');
        $normalizedManual = $this->normalizeManualReturn($manualReturn, $certification);

        $machineTally = (array) ($certification['actual_tally'] ?? []);
        $machineAccepted = (int) ($certification['accepted_ballots'] ?? 0);
        $machineRejected = (int) ($certification['rejected_ballots'] ?? 0);
        $manualTally = (array) $normalizedManual['tally'];
        $manualAccepted = (int) $normalizedManual['accepted_ballots'];
        $manualRejected = (int) $normalizedManual['rejected_ballots'];
        $precinctId = $this->firstStringOrNull($manualReturn['precinct_id'] ?? $certification['precinct_id'] ?? null);
        $checks = [
            $this->certificationReportCheck($certification),
            $this->manualReturnShapeCheck($normalizedManual),
            $this->tallyComparisonCheck(
                $manualTally,
                $machineTally,
                $manualAccepted,
                $manualRejected,
                $machineAccepted,
                $machineRejected,
            ),
        ];

        $manualReturnPath = $this->storage->writeJson('certification/manual-return.json', [
            'schema_version' => 'manual-return-1',
            'generated_at' => now()->toIso8601String(),
            'precinct_id' => $precinctId,
            'accepted_ballots' => $manualAccepted,
            'rejected_ballots' => $manualRejected,
            'tally' => $manualTally,
        ]);

        $report = [
            'schema_version' => 'manual-verification-report-1',
            'run_id' => basename($this->storage->activeRunPath()),
            'precinct_id' => $precinctId,
            'generated_at' => now()->toIso8601String(),
            'manual_return_path' => $manualReturnPath,
            'manual_return' => $normalizedManual,
            'manual_tally' => $manualTally,
            'manual_accepted_ballots' => $manualAccepted,
            'manual_rejected_ballots' => $manualRejected,
            'machine_tally' => $machineTally,
            'machine_accepted_ballots' => $machineAccepted,
            'machine_rejected_ballots' => $machineRejected,
            'checks' => $checks,
            'comparison_summary' => $this->buildComparisonSummary($manualTally, $machineTally, $manualAccepted, $manualRejected, $machineAccepted, $machineRejected),
            'passed' => true,
        ];

        $report['passed'] = collect($checks)
            ->every(fn (array $check): bool => $check['passed'] === true);
        $report['report_hash'] = $this->json->hash($this->reportForHash($report));
        $report['artifact_path'] = $this->storage->path('certification/manual-verification-report.json');
        $report['artifact_path'] = $this->storage->writeJson('certification/manual-verification-report.json', $report);

        $this->journal->record('certification.manual_verification', [
            'run_id' => $report['run_id'],
            'precinct_id' => $report['precinct_id'],
            'passed' => $report['passed'],
            'report_hash' => $report['report_hash'],
        ]);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function certificationReportCheck(array $certification): array
    {
        return [
            'name' => 'certification_report_present',
            'passed' => $certification !== [],
            'details' => [
                'certification_present' => $certification !== [],
                'schema_version' => $certification['schema_version'] ?? null,
                'certification_passed' => $certification['passed'] ?? null,
                'machine_accepted_ballots' => $certification['accepted_ballots'] ?? null,
                'machine_rejected_ballots' => $certification['rejected_ballots'] ?? null,
            ],
            'message' => $certification === []
                ? 'Certification report is missing. Run test ballots first.'
                : 'Certification report is present.',
        ];
    }

    /**
     * @param  array<string, mixed>  $manualReturn
     * @return array<string, mixed>
     */
    private function manualReturnShapeCheck(array $manualReturn): array
    {
        $tally = $manualReturn['tally'] ?? null;
        $precinctId = $manualReturn['precinct_id'] ?? null;
        $hasManualAccepted = array_key_exists('accepted_ballots', $manualReturn);
        $hasManualRejected = array_key_exists('rejected_ballots', $manualReturn);
        $isTallyObject = is_array($tally) && collect($tally)->every(
            fn (mixed $candidates): bool => is_array($candidates) && collect($candidates)->every(fn (mixed $count): bool => is_numeric($count)),
        );

        return [
            'name' => 'manual_return_shape',
            'passed' => $manualReturn !== []
                && is_array($tally)
                && $isTallyObject
                && $hasManualAccepted
                && $hasManualRejected
                && is_string($precinctId)
                && $precinctId !== ''
                && is_string(($manualReturn['schema_version'] ?? '')),
            'details' => [
                'manual_return_present' => $manualReturn !== [],
                'tally_present' => is_array($tally),
                'tally_type' => is_array($tally) ? 'array' : gettype($tally),
                'accepted_ballots_present' => array_key_exists('accepted_ballots', $manualReturn),
                'rejected_ballots_present' => array_key_exists('rejected_ballots', $manualReturn),
                'precinct_id_present' => is_string($precinctId) && $precinctId !== '',
                'schema_version' => $manualReturn['schema_version'] ?? null,
                'contest_count' => is_array($tally) ? count($tally) : 0,
            ],
            'message' => is_array($manualReturn) && is_array($tally)
                ? 'Manual return payload includes a tally structure.'
                : 'Manual return payload is missing a valid tally object.',
        ];
    }

    /**
     * @param  array<string, mixed>  $manualTally
     * @param  array<string, mixed>  $machineTally
     * @return array<string, mixed>
     */
    private function tallyComparisonCheck(
        array $manualTally,
        array $machineTally,
        int $manualAccepted,
        int $manualRejected,
        int $machineAccepted,
        int $machineRejected,
    ): array {
        $normalizedManual = $this->normalizeTally($manualTally);
        $normalizedMachine = $this->normalizeTally($machineTally);

        $contestIds = collect(array_keys($normalizedMachine))
            ->merge(array_keys($normalizedManual))
            ->unique()
            ->sort()
            ->values();

        $contestComparison = [];

        foreach ($contestIds as $contestId) {
            $machineContest = $normalizedMachine[(string) $contestId] ?? [];
            $manualContest = $normalizedManual[(string) $contestId] ?? [];
            $candidateIds = collect(array_keys($machineContest))->merge(array_keys($manualContest))->unique()->sort()->values();
            $candidateMatches = [];
            $contestMatch = true;

            foreach ($candidateIds as $candidateId) {
                $machineCount = (int) ($machineContest[(string) $candidateId] ?? 0);
                $manualCount = (int) ($manualContest[(string) $candidateId] ?? 0);
                $matched = $machineCount === $manualCount;

                if (! $matched) {
                    $contestMatch = false;
                }

                $candidateMatches[(string) $candidateId] = [
                    'machine_count' => $machineCount,
                    'manual_count' => $manualCount,
                    'matched' => $matched,
                ];
            }

            $contestComparison[(string) $contestId] = [
                'matched' => $contestMatch,
                'candidates' => $candidateMatches,
            ];
        }

        $acceptedMatch = $manualAccepted === $machineAccepted;
        $rejectedMatch = $manualRejected === $machineRejected;

        return [
            'name' => 'tally_comparison',
            'passed' => $contestIds->isNotEmpty() && $acceptedMatch && $rejectedMatch && collect($contestComparison)->every(fn (array $contest): bool => $contest['matched']),
            'details' => [
                'contest_comparison' => $contestComparison,
                'machine_accepted_ballots' => $machineAccepted,
                'manual_accepted_ballots' => $manualAccepted,
                'machine_rejected_ballots' => $machineRejected,
                'manual_rejected_ballots' => $manualRejected,
                'accepted_match' => $acceptedMatch,
                'rejected_match' => $rejectedMatch,
            ],
            'message' => $contestIds->isEmpty()
                ? 'Manual tally is empty.'
                : (! $acceptedMatch || ! $rejectedMatch
                    ? 'Accepted or rejected ballot totals do not match.'
                    : (collect($contestComparison)->every(fn (array $contest): bool => $contest['matched'])
                        ? 'Manual return tally matches machine tally.'
                        : 'Manual return tally mismatch detected.')),
        ];
    }

    /**
     * @param  array<string, mixed>  $manualTally
     * @param  array<string, mixed>  $machineTally
     * @return array<string, mixed>
     */
    private function buildComparisonSummary(
        array $manualTally,
        array $machineTally,
        int $manualAccepted,
        int $manualRejected,
        int $machineAccepted,
        int $machineRejected,
    ): array {
        $normalizedManual = $this->normalizeTally($manualTally);
        $normalizedMachine = $this->normalizeTally($machineTally);

        $contests = collect(array_keys($normalizedMachine))
            ->merge(array_keys($normalizedManual))
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $contestId) use ($normalizedManual, $normalizedMachine): array {
                $machineContest = $normalizedMachine[$contestId] ?? [];
                $manualContest = $normalizedManual[$contestId] ?? [];

                return [
                    'contest_id' => $contestId,
                    'machine_count' => collect($machineContest)->sum(fn (int $count): int => $count),
                    'manual_count' => collect($manualContest)->sum(fn (int $count): int => $count),
                ];
            })
            ->all();

        return [
            'contests' => $contests,
            'totals' => [
                'machine' => [
                    'accepted_ballots' => $machineAccepted,
                    'rejected_ballots' => $machineRejected,
                ],
                'manual' => [
                    'accepted_ballots' => $manualAccepted,
                    'rejected_ballots' => $manualRejected,
                ],
            ],
        ];
    }

    private function normalizeManualReturn(array $manualReturn, array $certification): array
    {
        $manualTally = (array) ($manualReturn['tally'] ?? []);
        $normalizedTally = $this->normalizeTally($manualTally);
        $hasAcceptedField = array_key_exists('accepted_ballots', $manualReturn);
        $hasRejectedField = array_key_exists('rejected_ballots', $manualReturn);
        $accepted = $this->toInt($manualReturn['accepted_ballots'] ?? null);
        $rejected = $this->toInt($manualReturn['rejected_ballots'] ?? null);

        if (! $hasAcceptedField && $accepted === 0 && $manualReturn !== []) {
            $accepted = $this->sumTally($normalizedTally);
        }

        if (! $hasRejectedField && $rejected === 0 && $manualReturn !== []) {
            $rejected = $this->toInt($manualReturn['rejected_ballots'] ?? 0);
        }

        $precinctId = $this->firstStringOrNull($manualReturn['precinct_id'] ?? null)
            ?? $this->firstStringOrNull($certification['precinct_id'] ?? null)
            ?? 'unknown-precinct';

        return [
            'schema_version' => is_string($manualReturn['schema_version'] ?? null)
                ? $this->firstStringOrNull($manualReturn['schema_version']) ?? 'manual-return-1'
                : 'manual-return-1',
            'precinct_id' => $precinctId,
            'accepted_ballots' => max(0, $accepted),
            'rejected_ballots' => max(0, $rejected),
            'tally' => $normalizedTally,
        ];
    }

    private function firstStringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function sumTally(array $tally): int
    {
        if ($tally === []) {
            return 0;
        }

        return collect($tally)
            ->flatMap(fn (array $candidates): array => $candidates)
            ->sum(fn (mixed $count): int => $this->toInt($count));
    }

    private function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $tally
     * @return array<string, array<string, int>>
     */
    private function normalizeTally(array $tally): array
    {
        return collect($tally)
            ->mapWithKeys(function (mixed $candidates, mixed $contestId): array {
                if (! is_array($candidates)) {
                    return [(string) $contestId => []];
                }

                return [(string) $contestId => collect($candidates)
                    ->mapWithKeys(fn (mixed $count, mixed $candidateId): array => [
                        (string) $candidateId => (int) $count,
                    ])->all()];
            })
            ->all();
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
