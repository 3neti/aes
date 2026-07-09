<?php

namespace App\Election\Support;

use App\Election\Core\CanonicalJson;
use Illuminate\Filesystem\Filesystem;

final class ElectionStorage
{
    /**
     * @var array<string, string>
     */
    private const CeremonyDirectories = [
        'start' => '00-start-here',
        'preparation' => '01-precinct-preparation',
        'certification' => '02-device-certification',
        'opening' => '03-polls-opening',
        'voting' => '04-voting-and-printing',
        'closing' => '05-polls-closing',
        'counting' => '06-counting-and-tally',
        'returns' => '07-election-return',
        'precinct_closing' => '08-precinct-closing',
        'exports' => '09-exports-and-verification',
        'journal' => '10-journal',
    ];

    public function __construct(
        private readonly Filesystem $files,
        private readonly CanonicalJson $json,
    ) {}

    public function root(): string
    {
        return storage_path('app/election');
    }

    public function scenarioReportsRoot(): string
    {
        return $this->root().'/runs';
    }

    public function scenarioArtifactsRoot(): string
    {
        return $this->root().'/runs';
    }

    public function path(string $relative): string
    {
        return $this->root().'/'.$this->operatorRelativePath($relative);
    }

    public function scenarioReportPath(string $filename): string
    {
        return $this->activeRunPath().'/'.self::CeremonyDirectories['start'].'/'.basename($filename);
    }

    public function scenarioArtifactPath(string $relative): string
    {
        return $this->activeRunPath().'/'.ltrim($relative, '/');
    }

    public function ensureDirectories(): void
    {
        $this->files->ensureDirectoryExists($this->root());
        $this->files->ensureDirectoryExists($this->root().'/runs');
        $this->files->ensureDirectoryExists($this->root().'/source-data/pop');
        $this->files->ensureDirectoryExists($this->root().'/source-data/clc');
        $this->files->ensureDirectoryExists($this->root().'/source-data/imported-packages');

        $readme = $this->root().'/README.txt';

        if (! $this->files->exists($readme)) {
            $this->files->put($readme, $this->rootReadme());
        }
    }

    public function reset(): void
    {
        if ($this->files->exists($this->root())) {
            $this->files->deleteDirectory($this->root());
        }

        foreach ([
            storage_path('app/election-scenario-reports'),
            storage_path('app/election-scenario-artifacts'),
        ] as $legacyRoot) {
            if ($this->files->exists($legacyRoot)) {
                $this->files->deleteDirectory($legacyRoot);
            }
        }

        $this->ensureDirectories();
    }

    /**
     * @return array<string, mixed>
     */
    public function startRun(string $scenario, string $precinctId, string $timestamp): array
    {
        $this->ensureDirectories();

        $runId = $this->runId($timestamp, $precinctId, $scenario);
        $runPath = $this->root().'/runs/'.$runId;

        if ($this->files->isDirectory($runPath)) {
            $this->files->deleteDirectory($runPath);
        }

        foreach (self::CeremonyDirectories as $directory) {
            $this->files->ensureDirectoryExists($runPath.'/'.$directory);
        }

        $context = [
            'schema_version' => 'election-run-context-1',
            'run_id' => $runId,
            'scenario' => $scenario,
            'precinct_id' => $precinctId,
            'started_at' => $timestamp,
            'run_path' => $runPath,
            'start_here_path' => $runPath.'/'.self::CeremonyDirectories['start'],
            'summary_report_path' => $runPath.'/run-summary.json',
            'summary_report_text_path' => $runPath.'/run-summary.txt',
            'artifact_index_path' => $runPath.'/artifact-index.json',
        ];

        $this->files->put($runPath.'/README.txt', $this->runReadme($context));
        $this->files->put($runPath.'/'.self::CeremonyDirectories['start'].'/README.txt', $this->startHereReadme($context));
        $this->files->put($this->root().'/LATEST_RUN.txt', $runPath.PHP_EOL);
        $this->files->put($this->root().'/current-run.json', $this->json->encode($context));

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    public function currentRun(): array
    {
        $path = $this->root().'/current-run.json';

        if (! $this->files->exists($path)) {
            return [];
        }

        return json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function activeRunPath(): string
    {
        $context = $this->currentRun();

        if ($context === []) {
            $context = $this->startRun('manual-run', 'unknown-precinct', now()->format('Ymd-His'));
        }

        return (string) $context['run_path'];
    }

    public function runPath(string $relative = ''): string
    {
        $relative = trim($relative, '/');

        return $relative === '' ? $this->activeRunPath() : $this->activeRunPath().'/'.$relative;
    }

    /**
     * @return array<string, mixed>
     */
    public function readJson(string $relative, array $default = []): array
    {
        $path = $this->path($relative);

        if (! $this->files->exists($path)) {
            return $default;
        }

        return json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function writeJson(string $relative, array $data): string
    {
        $this->ensureDirectories();
        $path = $this->path($relative);
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $this->json->encode($data));

        return $path;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function writeScenarioReport(string $scenario, array $report, string $timestamp): string
    {
        $this->ensureDirectories();

        $precinct = $this->slug((string) ($report['precinct_id'] ?? 'unknown-precinct'));
        $scenario = $this->slug($scenario);
        $hash = substr($this->json->hash($report), 0, 12);
        $filename = "{$timestamp}-{$precinct}-{$scenario}-{$hash}-report.json";
        $path = $this->scenarioReportPath($filename);

        $this->files->put($path, $this->json->encode($report));

        return $path;
    }

    public function writeText(string $relative, string $contents): string
    {
        $this->ensureDirectories();
        $path = $this->path($relative);
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $contents);

        return $path;
    }

    public function readText(string $relative, string $default = ''): string
    {
        $path = $this->path($relative);

        if (! $this->files->exists($path)) {
            return $default;
        }

        return $this->files->get($path);
    }

    /**
     * @return array<int, string>
     */
    public function files(string $relative): array
    {
        if ($relative === 'ballots') {
            return collect([
                ...$this->filesInRunDirectory(self::CeremonyDirectories['certification'].'/scan-documents'),
                ...$this->filesInRunDirectory(self::CeremonyDirectories['voting'].'/ballots'),
            ])
                ->sort()
                ->values()
                ->all();
        }

        $path = $this->path($relative);

        if (! $this->files->isDirectory($path)) {
            return [];
        }

        return collect($this->files->files($path))
            ->map(fn ($file): string => $file->getPathname())
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function finalizeRun(string $scenario, array $report): array
    {
        $runPath = $this->activeRunPath();
        $artifacts = collect($this->files->allFiles($runPath))
            ->reject(fn ($file): bool => basename($file->getPathname()) === 'artifact-index.json')
            ->map(fn ($file): array => [
                'relative_path' => str_replace($runPath.'/', '', $file->getPathname()),
                'bytes' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getPathname()),
            ])
            ->sortBy('relative_path')
            ->values()
            ->all();

        $summary = [
            'schema_version' => 'election-run-summary-1',
            'run_id' => basename($runPath),
            'scenario' => $scenario,
            'precinct_id' => $report['precinct_id'] ?? null,
            'passed' => $report['passed'] ?? false,
            'generated_at' => now()->toIso8601String(),
            'artifact_count' => count($artifacts),
            'important_paths' => [
                'start_here' => $runPath.'/'.self::CeremonyDirectories['start'],
                'ballots' => $runPath.'/'.self::CeremonyDirectories['voting'],
                'counting_and_tally' => $runPath.'/'.self::CeremonyDirectories['counting'],
                'election_return' => $runPath.'/'.self::CeremonyDirectories['returns'],
                'transmission' => $runPath.'/'.self::CeremonyDirectories['exports'].'/transmission',
                'custody' => $runPath.'/'.self::CeremonyDirectories['exports'].'/custody',
                'exports_and_verification' => $runPath.'/'.self::CeremonyDirectories['exports'],
                'journal' => $runPath.'/'.self::CeremonyDirectories['journal'],
            ],
        ];
        $summary['summary_hash'] = $this->json->hash($summary);

        $index = [
            'schema_version' => 'election-run-artifact-index-1',
            'run_id' => basename($runPath),
            'scenario' => $scenario,
            'precinct_id' => $report['precinct_id'] ?? null,
            'generated_at' => $summary['generated_at'],
            'run_path' => $runPath,
            'artifacts' => $artifacts,
            'artifact_count' => count($artifacts),
            'total_bytes' => collect($artifacts)->sum(fn (array $artifact): int => (int) $artifact['bytes']),
        ];
        $index['index_hash'] = $this->json->hash($index);

        $summaryPath = $runPath.'/run-summary.json';
        $summaryTextPath = $runPath.'/run-summary.txt';
        $indexPath = $runPath.'/artifact-index.json';

        $this->files->put($summaryPath, $this->json->encode($summary));
        $this->files->put($summaryTextPath, $this->summaryText($summary));
        $this->files->put($indexPath, $this->json->encode($index));

        return [
            'run_id' => basename($runPath),
            'run_path' => $runPath,
            'start_here_path' => $runPath.'/'.self::CeremonyDirectories['start'],
            'summary_report_path' => $summaryPath,
            'summary_report_text_path' => $summaryTextPath,
            'artifact_index_path' => $indexPath,
            'artifact_count' => $index['artifact_count'],
            'artifact_total_bytes' => $index['total_bytes'],
            'artifact_index_hash' => $index['index_hash'],
        ];
    }

    private function operatorRelativePath(string $relative): string
    {
        $relative = ltrim($relative, '/');

        foreach ($this->sourceDataMappings() as $prefix => $target) {
            if ($relative === $prefix || str_starts_with($relative, $prefix.'/')) {
                return $target.substr($relative, strlen($prefix));
            }
        }

        return 'runs/'.basename($this->activeRunPath()).'/'.$this->runRelativePath($relative);
    }

    /**
     * @return array<string, string>
     */
    private function sourceDataMappings(): array
    {
        return [
            'imports/pop' => 'source-data/pop/imports',
            'registries/pop-2025-nle' => 'source-data/pop/registries/pop-2025-nle',
            'imports/clc' => 'source-data/clc/imports',
            'registries/clc-2025-nle' => 'source-data/clc/registries/clc-2025-nle',
            'packages/imported' => 'source-data/imported-packages',
        ];
    }

    private function runRelativePath(string $relative): string
    {
        $basename = basename($relative);

        return match (true) {
            $relative === 'registries/sample.json' => self::CeremonyDirectories['preparation'].'/sample-registries.json',
            $relative === 'packages/active-package.json' => self::CeremonyDirectories['preparation'].'/active-package.json',
            $relative === 'runtime/active-precinct.json' => self::CeremonyDirectories['preparation'].'/active-precinct.json',
            $relative === 'runtime/electoral-board-baseline.json' => self::CeremonyDirectories['preparation'].'/electoral-board-baseline.json',
            $relative === 'runtime/lifecycle.json' => self::CeremonyDirectories['start'].'/lifecycle.json',
            $relative === 'runtime/tally.json' => self::CeremonyDirectories['counting'].'/tally.json',
            $relative === 'runtime/tally-sheet.txt' => self::CeremonyDirectories['counting'].'/tally-sheet.txt',
            $relative === 'runtime/tally-sheet.pdf' => self::CeremonyDirectories['counting'].'/tally-sheet.pdf',
            str_starts_with($relative, 'runtime/spoiled-') => self::CeremonyDirectories['voting'].'/spoiled/'.$basename,
            $relative === 'runtime/officer-registry.json' => self::CeremonyDirectories['preparation'].'/officer-registry.json',
            $relative === 'journals' => self::CeremonyDirectories['journal'],
            str_starts_with($relative, 'journals/') => self::CeremonyDirectories['journal'].'/'.$basename,
            $relative === 'certification' => self::CeremonyDirectories['certification'],
            str_starts_with($relative, 'certification/') => self::CeremonyDirectories['certification'].'/'.$basename,
            $relative === 'attestations' => self::CeremonyDirectories['opening'].'/attestations',
            str_starts_with($relative, 'attestations/') => self::CeremonyDirectories['opening'].'/attestations/'.$basename,
            $relative === 'attestation-signatures' => self::CeremonyDirectories['opening'].'/signatures',
            str_starts_with($relative, 'attestation-signatures/') => self::CeremonyDirectories['opening'].'/signatures/'.$basename,
            str_starts_with($relative, 'ballots/cert-') => self::CeremonyDirectories['certification'].'/scan-documents/'.$basename,
            $relative === 'ballots' => self::CeremonyDirectories['voting'].'/ballots',
            str_starts_with($relative, 'ballots/') => self::CeremonyDirectories['voting'].'/ballots/'.$basename,
            $relative === 'print-jobs' => self::CeremonyDirectories['voting'].'/print-jobs',
            str_starts_with($relative, 'print-jobs/') => self::CeremonyDirectories['voting'].'/print-jobs/'.$basename,
            $relative === 'counting/accepted' => self::CeremonyDirectories['counting'].'/accepted',
            str_starts_with($relative, 'counting/accepted/') => self::CeremonyDirectories['counting'].'/accepted/'.$basename,
            $relative === 'counting/rejected' => self::CeremonyDirectories['counting'].'/rejected',
            str_starts_with($relative, 'counting/rejected/') => self::CeremonyDirectories['counting'].'/rejected/'.$basename,
            $relative === 'returns' => self::CeremonyDirectories['returns'],
            str_starts_with($relative, 'returns/') => self::CeremonyDirectories['returns'].'/'.$basename,
            $relative === 'diagnostics' => self::CeremonyDirectories['exports'],
            str_starts_with($relative, 'diagnostics/uploaded-archives/') => self::CeremonyDirectories['exports'].'/uploaded-archives/'.$basename,
            str_starts_with($relative, 'diagnostics/') => self::CeremonyDirectories['exports'].'/'.$basename,
            $relative === 'transmission' => self::CeremonyDirectories['exports'].'/transmission',
            str_starts_with($relative, 'transmission/') => self::CeremonyDirectories['exports'].'/transmission/'.$basename,
            $relative === 'custody' => self::CeremonyDirectories['exports'].'/custody',
            str_starts_with($relative, 'custody/') => self::CeremonyDirectories['exports'].'/custody/'.$basename,
            str_starts_with($relative, 'removable-media/') => self::CeremonyDirectories['exports'].'/removable-media/'.substr($relative, strlen('removable-media/')),
            $relative === 'removable-media' => self::CeremonyDirectories['exports'].'/removable-media',
            $relative === 'scenarios' => self::CeremonyDirectories['start'],
            str_starts_with($relative, 'scenarios/') => self::CeremonyDirectories['start'].'/'.$basename,
            $relative === 'precinct-candidates' => self::CeremonyDirectories['preparation'].'/candidate-previews',
            str_starts_with($relative, 'precinct-candidates/') => self::CeremonyDirectories['preparation'].'/candidate-previews/'.$basename,
            default => self::CeremonyDirectories['start'].'/'.$relative,
        };
    }

    /**
     * @return array<int, string>
     */
    private function filesInRunDirectory(string $relative): array
    {
        $path = $this->runPath($relative);

        if (! $this->files->isDirectory($path)) {
            return [];
        }

        return collect($this->files->files($path))
            ->map(fn ($file): string => $file->getPathname())
            ->all();
    }

    private function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value));
        $slug = trim($slug, '-');

        return $slug === '' ? 'unknown' : $slug;
    }

    private function runId(string $timestamp, string $precinctId, string $scenario): string
    {
        return $this->slug($timestamp).'-'.$this->slug($precinctId).'-'.$this->slug($scenario);
    }

    private function rootReadme(): string
    {
        return implode(PHP_EOL, [
            'Alternative Election System Evidence Storage',
            '',
            'Open LATEST_RUN.txt to find the newest run folder.',
            'Each run folder contains numbered ceremony folders so files sort in election-flow order.',
            'source-data contains imported POP/CLC source registries used by runs.',
            '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function runReadme(array $context): string
    {
        return implode(PHP_EOL, [
            'Alternative Election System Run Folder',
            '',
            'Run ID: '.$context['run_id'],
            'Precinct: '.$context['precinct_id'],
            'Scenario: '.$context['scenario'],
            '',
            'Start with 00-start-here, then browse numbered ceremony folders in order.',
            '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function startHereReadme(array $context): string
    {
        return implode(PHP_EOL, [
            'Start Here',
            '',
            'Run ID: '.$context['run_id'],
            'Review run-summary.txt for the plain-language summary.',
            'Review artifact-index.json for file hashes and evidence pointers.',
            '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function summaryText(array $summary): string
    {
        $lines = [
            'ELECTION RUN SUMMARY',
            'Run ID: '.$summary['run_id'],
            'Scenario: '.$summary['scenario'],
            'Precinct: '.($summary['precinct_id'] ?? 'unknown'),
            'Passed: '.(($summary['passed'] ?? false) ? 'yes' : 'no'),
            'Generated: '.$summary['generated_at'],
            'Summary Hash: '.$summary['summary_hash'],
            '',
            'Important folders:',
        ];

        foreach ($summary['important_paths'] as $label => $path) {
            $lines[] = '- '.$label.': '.$path;
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
