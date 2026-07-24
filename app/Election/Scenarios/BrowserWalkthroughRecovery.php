<?php

namespace App\Election\Scenarios;

use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\ElectionRunType;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;

final class BrowserWalkthroughRecovery
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly Filesystem $files,
    ) {}

    /**
     * @param  array<string, mixed>  $control
     * @return array<string, mixed>
     */
    public function recover(array $control, string $message): array
    {
        $runPath = (string) ($control['run_path'] ?? '');
        $runId = (string) ($control['run_id'] ?? '');
        $browserDirectory = $runPath.'/12-audit-and-reconciliation/browser-recordings';
        $completionPath = $browserDirectory.'/browser-walkthrough-completion.json';
        $reportPath = $browserDirectory.'/browser-walkthrough-report.json';
        $previousRunType = config('election.runtime.run_type');

        $this->files->ensureDirectoryExists($browserDirectory);
        $completion = $this->readJson($completionPath);
        $completionFound = $completion !== [];
        $passed = ($completion['passed'] ?? false) === true;

        if (! $completionFound) {
            $completion = [
                'schema_version' => 'browser-walkthrough-completion-1',
                'run_id' => $runId,
                'walkthrough_id' => $control['walkthrough_id'] ?? null,
                'recording_passed' => false,
                'post_recording' => [],
                'passed' => false,
                'error' => $message,
                'completed_at' => $this->clock->now()->toIso8601String(),
            ];
            $completion['completion_hash'] = $this->json->hash($completion);
            $this->files->put($completionPath, $this->json->encode($completion));
        }

        $recovery = [
            'schema_version' => 'browser-walkthrough-recovery-1',
            'run_id' => $runId,
            'walkthrough_id' => $control['walkthrough_id'] ?? null,
            'detected_at' => $this->clock->now()->toIso8601String(),
            'previous_status' => $control['status'] ?? null,
            'coordinator_pid' => $control['coordinator_pid'] ?? null,
            'completion_found' => $completionFound,
            'completion_passed' => $passed,
            'message' => $message,
        ];
        $recovery['recovery_hash'] = $this->json->hash($recovery);
        $recoveryPath = $browserDirectory.'/browser-walkthrough-recovery.json';
        $this->files->put($recoveryPath, $this->json->encode($recovery));

        $report = $this->readJson($reportPath);
        $report = [
            ...$report,
            'schema_version' => $report['schema_version'] ?? 'browser-walkthrough-report-1',
            'scenario' => $report['scenario'] ?? ($control['scenario'] ?? 'full-election'),
            'run_id' => $runId,
            'precinct_id' => $report['precinct_id'] ?? null,
            'passed' => $passed,
            'statistics' => [
                ...($report['statistics'] ?? []),
                'coordinator_recovered' => true,
            ],
            'error' => $passed ? ($report['error'] ?? null) : ($completion['error'] ?? $message),
            'artifacts' => $report['artifacts'] ?? [],
        ];

        $finalized = false;

        try {
            $this->storage->selectRunType(ElectionRunType::Rehearsal);
            $current = $this->storage->currentRun(ElectionRunType::Rehearsal);

            if (($current['run_id'] ?? null) === $runId) {
                if (($current['status'] ?? null) !== 'locked') {
                    $this->storage->lockActiveRun();
                }

                $this->storage->finalizeRun((string) $report['scenario'], $report);
                $finalized = true;
            }
        } finally {
            config()->set('election.runtime.run_type', $previousRunType);
        }

        return [
            ...$recovery,
            'status' => $passed ? 'passed' : 'failed',
            'run_finalized' => $finalized,
            'completion_path' => $completionPath,
            'recovery_path' => $recoveryPath,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (! $this->files->exists($path)) {
            return [];
        }

        return json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
