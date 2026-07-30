<?php

namespace App\Console\Commands;

use App\Election\Audit\RandomManualAuditEvidencePackVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

#[Signature('election:rma-pack-verify {path : Downloaded random manual audit evidence pack JSON path} {--json : Emit the verification report as JSON} {--report= : Optional path for a persisted JSON verification report}')]
#[Description('Verify a downloaded random manual audit evidence pack without reading precinct storage.')]
final class VerifyRandomManualAuditEvidencePack extends Command
{
    public function handle(RandomManualAuditEvidencePackVerifier $verifier, Filesystem $files): int
    {
        $path = (string) $this->argument('path');

        if (! $files->exists($path)) {
            $this->error("Evidence pack file was not found: {$path}");

            return self::FAILURE;
        }

        $report = $verifier->verify($files->get($path));
        $report['verified_path'] = $path;

        if (is_string($this->option('report')) && $this->option('report') !== '') {
            $reportPath = (string) $this->option('report');
            $files->ensureDirectoryExists(dirname($reportPath));
            $files->put($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $this->line("Verification report: {$reportPath}");
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $report['passed'] ? self::SUCCESS : self::FAILURE;
        }

        if ($report['passed']) {
            $this->info('Random manual audit evidence pack verified.');
            $this->line('Sample size: '.($report['sample_size'] ?? 0));
            $this->line('Verified comparisons: '.($report['verified_ballots'] ?? 0));
            $this->line('Paper discrepancies: '.($report['discrepancy_ballots'] ?? 0));

            return self::SUCCESS;
        }

        $this->error('Random manual audit evidence pack verification failed.');

        foreach ($report['errors'] as $error) {
            $this->line("- {$error}");
        }

        return self::FAILURE;
    }
}
