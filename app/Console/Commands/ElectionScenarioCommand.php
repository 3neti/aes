<?php

namespace App\Console\Commands;

use App\Election\Scenarios\ScenarioRunner;
use Illuminate\Console\Command;

final class ElectionScenarioCommand extends Command
{
    protected $signature = 'election:scenario {scenario : friday-certification, full-demo, or evidence-folder-demo}';

    protected $description = 'Run a deterministic Alternative Election System scenario.';

    public function handle(ScenarioRunner $runner): int
    {
        $report = $runner->run((string) $this->argument('scenario'));

        $this->line("Scenario {$report['scenario']} ".($report['passed'] ? 'passed' : 'failed').'.');
        $this->line("Report: {$report['archived_report_path']}");

        if (isset($report['evidence_folder_path'])) {
            $this->line("Evidence Folder: {$report['evidence_folder_path']}");
        }

        if (isset($report['summary_report_path'])) {
            $this->line("Summary Report: {$report['summary_report_path']}");
        }

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
