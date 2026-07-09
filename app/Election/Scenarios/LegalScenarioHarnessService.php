<?php

namespace App\Election\Scenarios;

use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;

final class LegalScenarioHarnessService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ScenarioRunner $runner,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        return $this->runner->run('legal-suite');
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $path = $this->storage->path('scenarios/legal-suite-report.json');

        if (! $this->files->exists($path)) {
            return [
                'exists' => false,
                'run_suite_url' => route('election.provision.legal-scenario-suite'),
            ];
        }

        $report = $this->storage->readJson('scenarios/legal-suite-report.json');

        return [
            'exists' => true,
            'report_path' => $path,
            'scenario' => $report['scenario'] ?? null,
            'suite' => $report['suite'] ?? null,
            'passed' => (bool) ($report['passed'] ?? false),
            'run_id' => $report['run_id'] ?? null,
            'precinct_id' => $report['precinct_id'] ?? null,
            'artifact_reference_count' => $report['artifact_reference_count'] ?? null,
            'sub_scenarios' => $report['sub_scenarios'] ?? [],
            'evidence_reference_baseline' => $report['evidence_reference_baseline'] ?? [],
            'official_minutes_baseline' => $report['official_minutes_baseline'] ?? [],
            'electoral_board_baseline' => $report['electoral_board_baseline'] ?? [],
            'harness_stages' => $report['harness_stages'] ?? [],
            'generated_at' => $this->clockTimestamp($report),
            'run_suite_url' => route('election.provision.legal-scenario-suite'),
        ];
    }

    private function clockTimestamp(array $report): ?string
    {
        $timestamp = $report['generated_at'] ?? $report['started_at'] ?? null;

        if (is_string($timestamp)) {
            return $timestamp;
        }

        return null;
    }
}
