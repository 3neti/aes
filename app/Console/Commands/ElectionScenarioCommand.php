<?php

namespace App\Console\Commands;

use App\Election\Scenarios\ScenarioRunner;
use Illuminate\Console\Command;

final class ElectionScenarioCommand extends Command
{
    protected $signature = 'election:scenario {scenario : friday-certification or full-demo}';

    protected $description = 'Run a deterministic Alternative Election System scenario.';

    public function handle(ScenarioRunner $runner): int
    {
        $report = $runner->run((string) $this->argument('scenario'));

        $this->line("Scenario {$report['scenario']} ".($report['passed'] ? 'passed' : 'failed').'.');
        $this->line("Report: {$report['archived_report_path']}");

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
