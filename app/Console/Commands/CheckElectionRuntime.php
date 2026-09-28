<?php

namespace App\Console\Commands;

use App\Election\Runtime\RuntimeReadinessService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:runtime-check {--json : Emit the report as JSON}')]
#[Description('Inspect WAES queue, scheduler, Reverb, and scanner runtime readiness.')]
final class CheckElectionRuntime extends Command
{
    public function handle(RuntimeReadinessService $readiness): int
    {
        $report = $readiness->inspect();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['ready'] ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('WAES runtime', strtoupper($report['status']));

        foreach ($report['checks'] as $check) {
            $marker = match ($check['status']) {
                'pass' => '<fg=green>PASS</>',
                'warn' => '<fg=yellow>WARN</>',
                default => '<fg=red>FAIL</>',
            };

            $this->components->twoColumnDetail($check['label'], $marker);
            $this->line('  '.$check['detail']);
        }

        return $report['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
