<?php

namespace App\Console\Commands;

use App\Election\Scenarios\ScenarioRunner;
use Illuminate\Console\Command;

final class ElectionScenarioCommand extends Command
{
    protected $signature = 'election:scenario {scenario : legal-suite, supply-verification-baseline, initialization-report, fts-manual-verification-discrepancy, fts-zero-out, friday-certification, full-demo, evidence-folder-demo, pop-import-demo, or eb-role-baseline}';

    protected $description = 'Run a deterministic Alternative Election System scenario.';

    public function handle(ScenarioRunner $runner): int
    {
        $report = $runner->run((string) $this->argument('scenario'));

        $this->line("Scenario {$report['scenario']} ".($report['passed'] ? 'passed' : 'failed').'.');
        $this->line("Run ID: {$report['run_id']}");
        $this->line("Run Folder: {$report['run_path']}");
        $this->line("Start Here: {$report['start_here_path']}");
        $this->line("Report: {$report['archived_report_path']}");
        $this->line("Summary Report: {$report['summary_report_path']}");
        $this->line("Artifact Index: {$report['artifact_index_path']}");

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
