<?php

namespace App\Console\Commands;

use App\Election\Diagnostics\ApplianceRecoveryService;
use App\Election\Support\ElectionRunScopeLock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:recover')]
#[Description('Inspect election evidence and report whether the active ceremony can resume.')]
final class ElectionRecoveryCommand extends Command
{
    public function handle(ApplianceRecoveryService $recovery, ElectionRunScopeLock $runLock): int
    {
        $operation = fn (): int => $this->inspectRecovery($recovery);

        return config('election.voting_lock_driver') === 'local' ? $runLock->executeCommand($operation) : $operation();
    }

    private function inspectRecovery(ApplianceRecoveryService $recovery): int
    {
        $report = $recovery->inspect();

        $this->line('Run: '.($report['run_id'] ?? 'none'));
        $this->line('Lifecycle: '.$report['lifecycle_stage']);
        $this->line('Resume status: '.$report['resume_status']);
        $this->line('Device status: '.$report['device_status']);
        $this->line('Report: '.$report['artifact_path']);

        return $report['critical_checks_passed'] ? self::SUCCESS : self::FAILURE;
    }
}
