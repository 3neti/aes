<?php

namespace App\Console\Commands;

use App\Election\Diagnostics\CloudEvidenceMirror;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:evidence-mirror {run? : Absolute path to an election run; defaults to the active run} {--json : Emit the mirror report as JSON}')]
#[Description('Mirror and verify one local election evidence run in configured private object storage.')]
final class MirrorElectionEvidenceCommand extends Command
{
    public function handle(CloudEvidenceMirror $mirror): int
    {
        $report = $mirror->mirror($this->argument('run') ?: null);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Mirrored election run {$report['run_id']}.");
        $this->line("Artifacts: {$report['artifact_count']}");
        $this->line("Manifest: {$report['manifest_path']}");
        $this->line("Manifest hash: {$report['manifest_hash']}");

        return self::SUCCESS;
    }
}
