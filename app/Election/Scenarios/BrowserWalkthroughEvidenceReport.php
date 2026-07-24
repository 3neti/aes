<?php

namespace App\Election\Scenarios;

use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use Illuminate\Filesystem\Filesystem;

final class BrowserWalkthroughEvidenceReport
{
    public function __construct(
        private readonly CanonicalJson $json,
        private readonly ElectionClock $clock,
        private readonly Filesystem $files,
    ) {}

    /**
     * @param  array<string, mixed>  $browserReport
     * @return array<string, mixed>
     */
    public function write(string $runPath, string $artifactDirectory, array $browserReport): array
    {
        $completedActions = $this->completedActions($artifactDirectory.'/action-log.jsonl');
        $report = [
            'schema_version' => 'browser-walkthrough-lifecycle-report-1',
            'run_id' => basename($runPath),
            'scenario' => $browserReport['scenario'] ?? 'full-election',
            'precinct_id' => $browserReport['precinct_id'] ?? null,
            'passed' => ($browserReport['passed'] ?? false) === true,
            'generated_at' => $this->clock->now()->toIso8601String(),
            'flow' => $this->flow($completedActions),
            'statistics' => $browserReport['statistics'] ?? [],
            'principal_artifacts' => $this->principalArtifacts(
                $runPath,
                (string) ($browserReport['precinct_id'] ?? 'unknown'),
            ),
            'directories' => $this->directories(),
        ];
        $report['report_hash'] = $this->json->hash($report);

        $jsonPath = $artifactDirectory.'/browser-lifecycle-report.json';
        $textPath = $artifactDirectory.'/browser-lifecycle-report.txt';
        $this->files->put($jsonPath, $this->json->encode($report));
        $this->files->put($textPath, $this->renderText($report));

        $index = $this->recordingIndex($runPath, $artifactDirectory);
        $indexPath = $artifactDirectory.'/browser-artifact-index.json';
        $this->files->put($indexPath, $this->json->encode($index));

        return [
            'lifecycle_report_path' => $jsonPath,
            'lifecycle_report_text_path' => $textPath,
            'browser_artifact_index_path' => $indexPath,
            'browser_artifact_count' => $index['artifact_count'],
            'browser_artifact_total_bytes' => $index['total_bytes'],
            'browser_artifact_index_hash' => $index['index_hash'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function completedActions(string $actionLogPath): array
    {
        if (! $this->files->exists($actionLogPath)) {
            return [];
        }

        return collect(preg_split('/\R/', trim($this->files->get($actionLogPath))) ?: [])
            ->filter()
            ->map(fn (string $line): mixed => json_decode($line, true))
            ->filter(fn (mixed $action): bool => is_array($action) && ($action['status'] ?? null) === 'passed')
            ->pluck('action')
            ->filter(fn (mixed $action): bool => is_string($action))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $completedActions
     * @return array<int, array<string, mixed>>
     */
    private function flow(array $completedActions): array
    {
        $ceremonies = [
            ['sequence' => 1, 'ceremony' => 'Precinct Package and Configuration', 'directory' => '01-precinct-package-and-configuration', 'actions' => ['activate-precinct-package', 'record-dual-control-setup', 'record-board-and-supply-baselines']],
            ['sequence' => 2, 'ceremony' => 'Final Testing and Sealing', 'directory' => '02-final-testing-and-sealing', 'actions' => ['run-friday-certification', 'run-manual-verification', 'run-discrepancy-analysis', 'run-zero-out', 'record-certification-signature', 'seal-certification']],
            ['sequence' => 3, 'ceremony' => 'Opening of Polls', 'directory' => '03-opening-of-polls', 'actions' => ['initialize-opening-of-polls', 'record-opening-signature', 'begin-active-voting']],
            ['sequence' => 4, 'ceremony' => 'Voting and Printing', 'directory' => '04-voting', 'actions' => ['finalize-spoiled-ballot', 'print-spoiled-ballot', 'mark-ballot-spoiled', 'complete-voting-and-printing-segment']],
            ['sequence' => 5, 'ceremony' => 'Closing, Counting, and Tally', 'directory' => '06-counting-and-tally', 'actions' => ['close-polls', 'scan-spoiled-ballot', 'adjudicate-spoiled-ballot', 'record-physical-ballot-control', 'complete-counting-and-tally']],
            ['sequence' => 6, 'ceremony' => 'Election Return', 'directory' => '07-election-return', 'actions' => ['generate-election-return', 'prepare-return-copies-and-posting', 'approve-election-return', 'complete-election-return-ceremony']],
            ['sequence' => 7, 'ceremony' => 'Official Handoff and Custody', 'directory' => '08-transmission-or-official-handoff', 'actions' => ['prepare-transmission-report', 'prepare-delivery-package', 'record-handoff-officer-verification', 'record-handoff-recipient-verification', 'generate-delivery-receipt', 'record-final-backup', 'record-custody-turnover', 'close-precinct']],
            ['sequence' => 8, 'ceremony' => 'Audit and Reconciliation', 'directory' => '12-audit-and-reconciliation', 'actions' => ['begin-audit-and-reconciliation', 'generate-evidence-reference-baseline', 'generate-official-minutes-baseline', 'generate-audit-reconciliation-baseline', 'generate-final-evidence-manifest', 'build-evidence-bundle-archive', 'verify-evidence-bundle-archive']],
        ];

        return collect($ceremonies)
            ->map(function (array $ceremony) use ($completedActions): array {
                $completed = collect($ceremony['actions'])
                    ->filter(fn (string $action): bool => in_array($action, $completedActions, true))
                    ->count();

                return [
                    ...$ceremony,
                    'status' => $completed === count($ceremony['actions']) ? 'completed' : 'incomplete',
                    'completed_actions' => $completed,
                    'required_actions' => count($ceremony['actions']),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function principalArtifacts(string $runPath, string $precinctId): array
    {
        $paths = [
            '01-precinct-package-and-configuration/configured-precinct-activation.json',
            '01-precinct-package-and-configuration/precinct-setup.json',
            '02-final-testing-and-sealing/friday-certification-report.json',
            '02-final-testing-and-sealing/sealing-report.json',
            '06-counting-and-tally/tally-sheet.pdf',
            '06-counting-and-tally/tally.json',
            "07-election-return/{$precinctId}-return.pdf",
            '08-transmission-or-official-handoff/delivery-package.json',
            '08-transmission-or-official-handoff/delivery-receipt.pdf',
            '09-final-backup/final-backup-report.pdf',
            '10-custody-turnover/custody-turnover-report.pdf',
            '12-audit-and-reconciliation/evidence-manifest.json',
            '12-audit-and-reconciliation/browser-recordings/full-election.webm',
            '12-audit-and-reconciliation/browser-recordings/playwright-trace.zip',
            '12-audit-and-reconciliation/browser-recordings/browser-walkthrough-report.json',
            '12-audit-and-reconciliation/browser-recordings/action-log.jsonl',
            '12-audit-and-reconciliation/browser-recordings/walkthrough-storyboard.html',
            '12-audit-and-reconciliation/browser-recordings/walkthrough-storyboard.pdf',
            '12-audit-and-reconciliation/browser-recordings/walkthrough-storyboard.json',
        ];
        $ballots = glob($runPath.'/04-voting/ballots/*.pdf') ?: [];
        $paths = [
            ...$paths,
            ...array_map(fn (string $path): string => $this->relativePath($runPath, $path), $ballots),
        ];

        return collect($paths)
            ->unique()
            ->filter(fn (string $relativePath): bool => $this->files->exists($runPath.'/'.$relativePath))
            ->map(function (string $relativePath) use ($runPath): array {
                $path = $runPath.'/'.$relativePath;

                return [
                    'relative_path' => $relativePath,
                    'bytes' => $this->files->size($path),
                    'sha256' => hash_file('sha256', $path),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function directories(): array
    {
        return [
            'precinct_package_and_configuration' => '01-precinct-package-and-configuration',
            'final_testing_and_sealing' => '02-final-testing-and-sealing',
            'opening_of_polls' => '03-opening-of-polls',
            'voting_and_ballots' => '04-voting',
            'closing_of_polls' => '05-closing-of-polls',
            'counting_and_tally' => '06-counting-and-tally',
            'election_return' => '07-election-return',
            'official_handoff' => '08-transmission-or-official-handoff',
            'final_backup' => '09-final-backup',
            'custody_turnover' => '10-custody-turnover',
            'close_precinct' => '11-close-precinct',
            'audit_and_reconciliation' => '12-audit-and-reconciliation',
            'journal' => '13-journal',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordingIndex(string $runPath, string $artifactDirectory): array
    {
        $artifacts = collect($this->files->allFiles($artifactDirectory))
            ->reject(fn ($file): bool => $file->getFilename() === 'browser-artifact-index.json')
            ->map(fn ($file): array => [
                'relative_path' => $this->relativePath($runPath, $file->getPathname()),
                'bytes' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getPathname()),
            ])
            ->sortBy('relative_path')
            ->values()
            ->all();
        $index = [
            'schema_version' => 'browser-walkthrough-artifact-index-1',
            'run_id' => basename($runPath),
            'generated_at' => $this->clock->now()->toIso8601String(),
            'artifact_count' => count($artifacts),
            'total_bytes' => collect($artifacts)->sum(fn (array $artifact): int => (int) $artifact['bytes']),
            'artifacts' => $artifacts,
        ];
        $index['index_hash'] = $this->json->hash($index);

        return $index;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderText(array $report): string
    {
        $lines = [
            'BROWSER LIFECYCLE WALKTHROUGH',
            'Run ID: '.$report['run_id'],
            'Scenario: '.$report['scenario'],
            'Precinct: '.($report['precinct_id'] ?? 'unknown'),
            'Passed: '.($report['passed'] ? 'yes' : 'no'),
            'Generated: '.$report['generated_at'],
            'Report Hash: '.$report['report_hash'],
            '',
            'Ceremony flow:',
        ];

        foreach ($report['flow'] as $ceremony) {
            $lines[] = sprintf(
                '%d. %s: %s (%d/%d actions) -> %s',
                $ceremony['sequence'],
                $ceremony['ceremony'],
                $ceremony['status'],
                $ceremony['completed_actions'],
                $ceremony['required_actions'],
                $ceremony['directory'],
            );
        }

        $lines[] = '';
        $lines[] = 'Statistics:';

        foreach ($report['statistics'] as $label => $value) {
            $lines[] = '- '.ucfirst(str_replace('_', ' ', (string) $label)).': '.$this->textValue($value);
        }

        $lines[] = '';
        $lines[] = 'Principal artifacts:';

        foreach ($report['principal_artifacts'] as $artifact) {
            $lines[] = '- '.$artifact['relative_path'];
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function relativePath(string $runPath, string $path): string
    {
        return ltrim(str_replace(rtrim($runPath, '/').'/', '', $path), '/');
    }

    private function textValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_SLASHES) ?: '',
        };
    }
}
