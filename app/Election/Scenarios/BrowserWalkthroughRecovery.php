<?php

namespace App\Election\Scenarios;

use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\ElectionRunType;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionRunScopeLock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class BrowserWalkthroughRecovery
{
    private const ProcessNotFound = 3;

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
        if (config('election.voting_lock_driver') !== 'local') {
            throw new RuntimeException('Walkthrough recovery requires the single-host local lock profile.');
        }
        $operation = fn (): array => $this->recoverWithinLock($control, $message);

        return app(ElectionRunScopeLock::class)->execute($operation);
    }

    /** @param array<string, mixed> $control @return array<string, mixed> */
    private function recoverWithinLock(array $control, string $message): array
    {
        if (array_key_exists('simulation_scope', $control)) {
            throw new RuntimeException('Scoped walkthrough recovery requires review of both runs.');
        }
        $this->validateControl($control);
        $currentControl = app(BrowserWalkthroughControl::class)->read();
        if ($currentControl === [] || ! hash_equals($this->json->hash($currentControl), $this->json->hash($control))) {
            throw new RuntimeException('The walkthrough reservation does not match the current control record. Operator investigation is required.');
        }
        $pid = filter_var($control['coordinator_pid'] ?? null, FILTER_VALIDATE_INT);
        if (! is_int($pid) || $pid < 1 || $pid === getmypid()
            || ! function_exists('posix_kill') || ! function_exists('posix_get_last_error')
            || @posix_kill($pid, 0) || posix_get_last_error() !== self::ProcessNotFound) {
            throw new RuntimeException('The walkthrough coordinator may still be running. Operator investigation is required.');
        }
        $runPath = (string) ($control['run_path'] ?? '');
        $runId = (string) ($control['run_id'] ?? '');
        $browserDirectory = $runPath.'/12-audit-and-reconciliation/browser-recordings';
        $completionPath = $browserDirectory.'/browser-walkthrough-completion.json';
        $reportPath = $browserDirectory.'/browser-walkthrough-report.json';
        $previousRunType = config('election.runtime.run_type');

        foreach (['artifact-index.json', 'run-summary.json', 'run-summary.txt'] as $finalizedFile) {
            $path = $runPath.'/'.$finalizedFile;
            if ($this->files->exists($path) || is_link($path)) {
                throw new RuntimeException('Walkthrough finalization evidence already exists. Operator investigation is required.');
            }
        }
        foreach ([$runPath.'/12-audit-and-reconciliation', $browserDirectory, $completionPath, $reportPath, $browserDirectory.'/browser-walkthrough-recovery.json'] as $path) {
            if (is_link($path)) {
                throw new RuntimeException('Walkthrough recovery evidence contains a symbolic link. Operator investigation is required.');
            }
        }
        if ($this->files->exists($browserDirectory.'/browser-walkthrough-recovery.json')) {
            throw new RuntimeException('Walkthrough recovery evidence already exists. Operator investigation is required.');
        }
        $completionFound = $this->files->exists($completionPath);
        $completion = $this->readJson($completionPath);
        if ($completionFound) {
            $this->validateEvidence($completion, 'browser-walkthrough-completion-1', 'completion_hash', $control);
        }
        $reportFound = $this->files->exists($reportPath);
        $report = $this->readJson($reportPath);
        if ($reportFound) {
            $this->validateEvidence($report, 'browser-walkthrough-report-1', 'report_hash', $control);
        }
        $completionPassed = ($completion['passed'] ?? false) === true;
        $passed = false;

        $this->files->ensureDirectoryExists($browserDirectory);
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
            $this->writeJson($completionPath, $completion);
        }

        $recovery = [
            'schema_version' => 'browser-walkthrough-recovery-1',
            'run_id' => $runId,
            'walkthrough_id' => $control['walkthrough_id'] ?? null,
            'detected_at' => $this->clock->now()->toIso8601String(),
            'previous_status' => $control['status'] ?? null,
            'coordinator_pid' => $control['coordinator_pid'] ?? null,
            'completion_found' => $completionFound,
            'completion_passed' => $completionPassed,
            'message' => $message,
        ];
        $recovery['recovery_hash'] = $this->json->hash($recovery);
        $recoveryPath = $browserDirectory.'/browser-walkthrough-recovery.json';
        $this->writeJson($recoveryPath, $recovery);

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

    /** @param array<string, mixed> $control */
    private function validateControl(array $control): void
    {
        $hash = $control['control_hash'] ?? null;
        $hashable = $control;
        unset($hashable['control_hash']);
        $runId = $control['run_id'] ?? null;
        $runPath = $control['run_path'] ?? null;
        $root = realpath($this->storage->root().'/runs');
        $resolved = is_string($runPath) ? realpath($runPath) : false;
        if (($control['schema_version'] ?? null) !== 'browser-walkthrough-control-1'
            || ($control['status'] ?? null) !== 'active'
            || ($control['run_type'] ?? null) !== ElectionRunType::Rehearsal->value
            || ! is_string($control['walkthrough_id'] ?? null) || $control['walkthrough_id'] === ''
            || ! is_string($hash) || ! hash_equals($hash, $this->json->hash($hashable))
            || ! is_string($runId) || $runId === '' || basename($runId) !== $runId
            || ! is_string($runPath) || $resolved === false || $root === false
            || is_link($runPath) || dirname($resolved) !== $root || basename($resolved) !== $runId) {
            throw new RuntimeException('The interrupted walkthrough reservation is inconsistent. Operator investigation is required.');
        }

        if (is_link($runPath.'/run-context.json')) {
            throw new RuntimeException('The interrupted walkthrough run context is a symbolic link.');
        }
        $context = $this->readJson($runPath.'/run-context.json');
        $current = $this->storage->currentRun(ElectionRunType::Rehearsal);
        if (($context['run_id'] ?? null) !== $runId || ($context['run_path'] ?? null) !== $runPath
            || ($context['run_type'] ?? null) !== ElectionRunType::Rehearsal->value
            || ! in_array($context['status'] ?? null, ['open', 'locked'], true)
            || ($current['run_id'] ?? null) !== $runId || ($current['run_path'] ?? null) !== $runPath
            || ($current['status'] ?? null) !== ($context['status'] ?? null)) {
            throw new RuntimeException('The interrupted walkthrough run no longer matches its reservation.');
        }
    }

    /** @param array<string, mixed> $evidence @param array<string, mixed> $control */
    private function validateEvidence(array $evidence, string $schema, string $hashKey, array $control): void
    {
        $hash = $evidence[$hashKey] ?? null;
        unset($evidence[$hashKey]);
        if (($evidence['schema_version'] ?? null) !== $schema
            || ($evidence['run_id'] ?? null) !== ($control['run_id'] ?? null)
            || ($evidence['walkthrough_id'] ?? null) !== ($control['walkthrough_id'] ?? null)
            || ! is_string($hash) || ! hash_equals($hash, $this->json->hash($evidence))) {
            throw new RuntimeException('Walkthrough recovery evidence is inconsistent. Operator investigation is required.');
        }
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $contents = $this->json->encode($data);
        if ($this->files->put($path, $contents) !== strlen($contents)) {
            throw new RuntimeException('Unable to write walkthrough recovery evidence completely. Operator investigation is required.');
        }
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
