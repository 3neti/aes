<?php

namespace App\Election\Certification;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;

final class ZeroOutService
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
    public function run(): array
    {
        $runPath = $this->storage->activeRunPath();
        $runId = basename($runPath);
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $precinct = (string) ($configuration['precinct_id'] ?? 'unknown-precinct');
        $certificationReport = $this->storage->readJson('certification/friday-certification-report.json');
        $certificationState = $this->certificationState($certificationReport);

        $countsBefore = $this->snapshotCounts();
        $clearedArtifacts = $this->clearEphemeralArtifacts();
        $countsAfter = $this->snapshotCounts();

        $passed =
            $countsAfter['accepted_ballots'] === 0
            && $countsAfter['rejected_ballots'] === 0
            && $countsAfter['spoiled_ballots'] === 0;

        $report = [
            'schema_version' => 'zero-out-report-1',
            'report_profile' => 'fts-zero-out-v1',
            'run_id' => $runId,
            'precinct_id' => $precinct,
            'generated_at' => $this->clock->now()->toIso8601String(),
            'certification_report_hash' => $certificationReport['report_hash'] ?? null,
            'counts_before' => $countsBefore,
            'counts_after' => $countsAfter,
            'cleared_artifacts' => $clearedArtifacts,
            'passed' => $passed,
            'checks' => [
                [
                    'name' => 'accepted_ballots_zeroed',
                    'passed' => $countsAfter['accepted_ballots'] === 0,
                    'details' => [
                        'accepted_before' => $countsBefore['accepted_ballots'],
                        'accepted_after' => $countsAfter['accepted_ballots'],
                    ],
                ],
                [
                    'name' => 'rejected_ballots_zeroed',
                    'passed' => $countsAfter['rejected_ballots'] === 0,
                    'details' => [
                        'rejected_before' => $countsBefore['rejected_ballots'],
                        'rejected_after' => $countsAfter['rejected_ballots'],
                    ],
                ],
                [
                    'name' => 'spoiled_ballots_zeroed',
                    'passed' => $countsAfter['spoiled_ballots'] === 0,
                    'details' => [
                        'spoiled_before' => $countsBefore['spoiled_ballots'],
                        'spoiled_after' => $countsAfter['spoiled_ballots'],
                    ],
                ],
                [
                    'name' => 'certification_report_present',
                    'passed' => $certificationState['passed'],
                    'details' => [
                        'certification_present' => $certificationReport !== [],
                        'certification_passed' => $certificationReport['passed'] ?? null,
                        'expected_ballots' => $certificationReport['expected_ballots'] ?? null,
                        'actual_ballots' => $certificationReport['actual_ballots'] ?? null,
                    ],
                    'message' => $certificationState['message'],
                ],
            ],
        ];

        $report['artifact_path'] = $this->storage->path('certification/zero-out-report.json');
        $report['report_hash'] = $this->json->hash($this->reportForHash($report));
        $this->storage->writeJson('certification/zero-out-report.json', $report);

        $this->journal->record('certification.zero_out', [
            'run_id' => $runId,
            'precinct_id' => $precinct,
            'passed' => $passed,
            'report_hash' => $report['report_hash'],
            'cleared_artifact_count' => count($clearedArtifacts),
        ]);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $certificationReport
     * @return array<string, mixed>
     */
    private function certificationState(array $certificationReport): array
    {
        if ($certificationReport === []) {
            return [
                'passed' => false,
                'message' => 'Certification report is missing. Run certification ballots before zero-out.',
            ];
        }

        return [
            'passed' => (bool) ($certificationReport['passed'] ?? false),
            'message' => (bool) ($certificationReport['passed'] ?? false)
                ? 'Certification report is present and passed.'
                : 'Certification report exists but did not pass.',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function snapshotCounts(): array
    {
        return [
            'accepted_ballots' => count($this->storage->files('counting/accepted')),
            'rejected_ballots' => count($this->storage->files('counting/rejected')),
            'spoiled_ballots' => count($this->storage->files('runtime/spoiled-ballots')),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function clearEphemeralArtifacts(): array
    {
        $cleared = [];
        $targets = [
            'counting/accepted',
            'counting/rejected',
            'runtime/spoiled-ballots',
        ];

        foreach ($targets as $target) {
            foreach ($this->storage->files($target) as $path) {
                if (! $this->files->isFile($path)) {
                    continue;
                }

                $this->files->delete($path);
                $cleared[] = [
                    'artifact' => str_replace($this->storage->activeRunPath().'/', '', $path),
                    'size' => 0,
                ];
            }
        }

        return $cleared;
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
            'artifact_file_size' => null,
        ];
    }
}
