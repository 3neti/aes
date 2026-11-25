<?php

namespace App\Console\Commands;

use App\Election\Scenarios\BrowserWalkthroughControl;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('election:browser-walkthrough:recover {run : Exact rehearsal run ID} {--coordinator-stopped : Attest that the coordinator and its browser are stopped on this appliance}')]
#[Description('Recover a stopped demo walkthrough and preserve its rehearsal evidence.')]
final class RecoverBrowserWalkthrough extends Command
{
    public function handle(BrowserWalkthroughControl $control): int
    {
        if (! $this->option('coordinator-stopped')) {
            $this->error('Confirm that the walkthrough coordinator and its browser are stopped on this appliance before recovery.');

            return self::FAILURE;
        }

        try {
            $recovery = $control->recoverInterrupted((string) $this->argument('run'));
        } catch (Throwable $exception) {
            $this->error('Walkthrough recovery could not be confirmed. Preserve the evidence and investigate.');
            if ($exception->getMessage() !== '') {
                $this->line($exception->getMessage());
            }

            return self::FAILURE;
        }

        $this->info('Interrupted demo walkthrough recovered.');
        $this->line('Run: '.$recovery['run_id']);
        $this->line('Status: '.$recovery['status']);
        $this->line('Recovery evidence: '.$recovery['recovery_path']);

        return self::SUCCESS;
    }
}
