<?php

namespace App\Election\Diagnostics;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\PaperBallotLedger;

final class ApplianceRecoveryService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly PaperBallotLedger $paperBallots,
        private readonly LifecycleState $lifecycle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function inspect(): array
    {
        $run = $this->storage->currentRun();
        $journalChain = $this->journal->verifyChain();
        $paperBallotChain = $this->paperBallots->verifyChain();
        $lifecycleStage = $this->lifecycle->current();
        $deviceReport = $this->storage->readJson('certification/device-certification-report.json');
        $devices = $deviceReport['devices'] ?? [];
        $degradedDevices = $deviceReport === []
            ? ['device-certification-report']
            : collect($devices)
                ->filter(fn (mixed $device): bool => is_array($device) && ($device['status'] ?? null) !== 'ready')
                ->keys()
                ->values()
                ->all();

        $checks = [
            $this->check('active_run_context', $run !== [], $run['run_id'] ?? null),
            $this->check(
                'active_run_directory',
                isset($run['run_path']) && is_dir((string) $run['run_path']),
                $run['run_path'] ?? null,
            ),
            $this->check(
                'precinct_identity',
                $this->storage->readJson('runtime/active-precinct.json') !== [],
                $this->storage->readJson('runtime/active-precinct.json')['precinct_id'] ?? null,
            ),
            $this->check('known_lifecycle_stage', in_array($lifecycleStage, Lifecycle::stages(), true), $lifecycleStage),
            $this->check('activity_journal_chain', $journalChain['passed'], $journalChain),
            $this->check('paper_ballot_ledger_chain', $paperBallotChain['passed'], $paperBallotChain),
        ];
        $criticalChecksPassed = collect($checks)->every(
            fn (array $check): bool => $check['passed'],
        );

        $report = [
            'schema_version' => 'appliance-recovery-report-1',
            'inspected_at' => $this->clock->now()->toIso8601String(),
            'run_id' => $run['run_id'] ?? null,
            'run_type' => $run['run_type'] ?? null,
            'lifecycle_stage' => $lifecycleStage,
            'resume_status' => $criticalChecksPassed ? 'resume-allowed' : 'locked-for-diagnostics',
            'critical_checks_passed' => $criticalChecksPassed,
            'device_status' => $degradedDevices === [] ? 'ready' : 'degraded',
            'degraded_devices' => $degradedDevices,
            'checks' => $checks,
        ];
        $report['report_hash'] = $this->json->hash($report);
        $report['artifact_path'] = $this->storage->writeJson(
            'diagnostics/appliance-recovery-report.json',
            $report,
        );

        return $report;
    }

    /**
     * @return array{name: string, passed: bool, detail: mixed}
     */
    private function check(string $name, bool $passed, mixed $detail): array
    {
        return [
            'name' => $name,
            'passed' => $passed,
            'detail' => $detail,
        ];
    }
}
