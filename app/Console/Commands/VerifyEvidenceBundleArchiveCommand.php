<?php

namespace App\Console\Commands;

use App\Election\Diagnostics\EvidenceBundleArchiveVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:archive-verify {path? : Downloaded evidence bundle TAR path; defaults to the latest local archive} {--json : Emit the verification report as JSON}')]
#[Description('Verify a downloaded election evidence bundle TAR archive.')]
final class VerifyEvidenceBundleArchiveCommand extends Command
{
    public function handle(EvidenceBundleArchiveVerifier $verifier): int
    {
        $report = $verifier->verify($this->argument('path') ?: null);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['passed'] ? self::SUCCESS : self::FAILURE;
        }

        if ($report['passed']) {
            $this->info("Evidence bundle archive {$report['archive_id']} verified.");
            $this->line("Checked files: {$report['checked_files']}");

            return self::SUCCESS;
        }

        $this->error('Evidence bundle archive verification failed.');

        foreach ($report['mismatches'] as $mismatch) {
            $this->line("- {$mismatch['type']}: {$mismatch['path']}");
            $this->line("  {$mismatch['message']}");

            if (($mismatch['expected'] ?? null) !== null || ($mismatch['actual'] ?? null) !== null) {
                $this->line('  expected: '.json_encode($mismatch['expected'], JSON_UNESCAPED_SLASHES));
                $this->line('  actual: '.json_encode($mismatch['actual'], JSON_UNESCAPED_SLASHES));
            }
        }

        return self::FAILURE;
    }
}
