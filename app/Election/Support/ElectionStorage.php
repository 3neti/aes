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
        'preparation' => '01-precinct-package-and-configuration',
        'certification' => '02-final-testing-and-sealing',
        'opening' => '03-opening-of-polls',
        'voting' => '04-voting',
        'closing' => '05-closing-of-polls',
        'counting' => '06-counting-and-tally',
        'returns' => '07-election-return',
        'transmission' => '08-transmission-or-official-handoff',
        'final_backup' => '09-final-backup',
        'custody' => '10-custody-turnover',
        'precinct_closing' => '11-close-precinct',
        'audit' => '12-audit-and-reconciliation',
        'journal' => '13-journal',
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

        $markdownReadme = $this->root().'/README.md';

        if (! $this->files->exists($markdownReadme)) {
            $this->files->put($markdownReadme, $this->rootReadmeMarkdown());
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
        $this->files->put($runPath.'/README.md', $this->runReadmeMarkdown($context));
        foreach (self::CeremonyDirectories as $key => $directory) {
            $this->files->put($runPath.'/'.$directory.'/README.md', $this->ceremonyDirectoryReadmeMarkdown($key, $directory, $context));
        }

        $this->files->put($runPath.'/'.self::CeremonyDirectories['start'].'/README.txt', $this->startHereReadme($context));
        $this->files->put($runPath.'/'.self::CeremonyDirectories['start'].'/README.md', $this->startHereReadmeMarkdown($context));
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
                'precinct_package_and_configuration' => $runPath.'/'.self::CeremonyDirectories['preparation'],
                'final_testing_and_sealing' => $runPath.'/'.self::CeremonyDirectories['certification'],
                'opening_of_polls' => $runPath.'/'.self::CeremonyDirectories['opening'],
                'ballots' => $runPath.'/'.self::CeremonyDirectories['voting'],
                'closing_of_polls' => $runPath.'/'.self::CeremonyDirectories['closing'],
                'counting_and_tally' => $runPath.'/'.self::CeremonyDirectories['counting'],
                'election_return' => $runPath.'/'.self::CeremonyDirectories['returns'],
                'transmission_or_official_handoff' => $runPath.'/'.self::CeremonyDirectories['transmission'],
                'final_backup' => $runPath.'/'.self::CeremonyDirectories['final_backup'],
                'custody_turnover' => $runPath.'/'.self::CeremonyDirectories['custody'],
                'close_precinct' => $runPath.'/'.self::CeremonyDirectories['precinct_closing'],
                'audit_and_reconciliation' => $runPath.'/'.self::CeremonyDirectories['audit'],
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
            $relative === 'opening' => self::CeremonyDirectories['opening'],
            str_starts_with($relative, 'opening/') => self::CeremonyDirectories['opening'].'/'.$basename,
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
            $relative === 'diagnostics' => self::CeremonyDirectories['audit'],
            str_starts_with($relative, 'diagnostics/uploaded-archives/') => self::CeremonyDirectories['audit'].'/uploaded-archives/'.$basename,
            str_starts_with($relative, 'diagnostics/') => self::CeremonyDirectories['audit'].'/'.$basename,
            $relative === 'transmission' => self::CeremonyDirectories['transmission'],
            str_starts_with($relative, 'transmission/final-backup') => self::CeremonyDirectories['final_backup'].'/'.$basename,
            str_starts_with($relative, 'transmission/backup-') => self::CeremonyDirectories['final_backup'].'/'.$basename,
            str_starts_with($relative, 'transmission/') => self::CeremonyDirectories['transmission'].'/'.$basename,
            $relative === 'custody' => self::CeremonyDirectories['custody'],
            str_starts_with($relative, 'custody/') => self::CeremonyDirectories['custody'].'/'.$basename,
            str_starts_with($relative, 'removable-media/') => self::CeremonyDirectories['audit'].'/removable-media/'.substr($relative, strlen('removable-media/')),
            $relative === 'removable-media' => self::CeremonyDirectories['audit'].'/removable-media',
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

    private function rootReadmeMarkdown(): string
    {
        return implode(PHP_EOL, [
            '# Alternative Election System Evidence Storage',
            '',
            'Open `LATEST_RUN.txt` to find the newest run folder.',
            '',
            'Each run folder is organized in numbered ceremony order so election workers can browse the evidence bundle as a legal sequence. Source imports used by the runs live under `source-data` and are separated from run evidence.',
            '',
            'Typical folders:',
            '',
            '- `runs/`: generated lifecycle scenario and operator evidence bundles.',
            '- `source-data/pop/`: imported Project of Precincts source copies and registries.',
            '- `source-data/clc/`: imported Certified List of Candidates source copies and registries.',
            '- `source-data/imported-packages/`: package skeletons created from imported precinct records.',
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
    private function runReadmeMarkdown(array $context): string
    {
        return implode(PHP_EOL, [
            '# Alternative Election System Run Folder',
            '',
            '- Run ID: `'.$context['run_id'].'`',
            '- Precinct: `'.$context['precinct_id'].'`',
            '- Scenario: `'.$context['scenario'].'`',
            '',
            'Browse the folders in number order. The names follow the election ceremony sequence in the General Instructions. Paper ballots and printed artifacts remain the legal source of truth; files in this bundle are supporting evidence and reproducibility records.',
            '',
            '## Directory Guide',
            '',
            '| Folder | Purpose | Common files |',
            '| --- | --- | --- |',
            '| `00-start-here` | Entry point for the run. | Scenario report, lifecycle state, quick README. |',
            '| `01-precinct-package-and-configuration` | Precinct package, ballot style, candidates, EB/officer setup. | Active package, active precinct, ballot definition, candidate preview, officer registry. |',
            '| `02-final-testing-and-sealing` | Device certification, final testing, zero/known-result evidence. | Device certification report, certification ballots, FTS reports. |',
            '| `03-opening-of-polls` | Poll opening and officer attestations. | Opening reports, attestations, signature artifacts. |',
            '| `04-voting` | Ballot finalization and printing evidence. | Ballot payloads, QR artifacts, printable ballots, print jobs, spoiled ballots, special polling intake. |',
            '| `05-closing-of-polls` | Close polls ceremony evidence. | Closing reports and unused/spoiled ballot summaries when present. |',
            '| `06-counting-and-tally` | Accepted/rejected ballot payloads and tally. | Accepted records, rejected records, tally JSON, tally sheet text/PDF. |',
            '| `07-election-return` | Election Return generation. | Election Return JSON, text, PDF, attestations. |',
            '| `08-transmission-or-official-handoff` | Transmission as official handoff. | Transmission report, delivery package, manual handoff verifications, delivery receipt. |',
            '| `09-final-backup` | Final backup after transmission/handoff evidence. | Final backup report and backup manifests. |',
            '| `10-custody-turnover` | Chain-of-custody and turnover evidence. | Custody record, custody turnover report, custody PDFs. |',
            '| `11-close-precinct` | Final precinct closure checkpoint. | Closure reports when generated. |',
            '| `12-audit-and-reconciliation` | Audit, evidence manifests, exports, and verification. | Evidence manifests, archive reports, removable-media reports, verification reports. |',
            '| `13-journal` | Append-only event history. | Activity journal entries and summaries. |',
            '',
            '## Index Files',
            '',
            '- `run-summary.json` and `run-summary.txt` summarize the run and important folders.',
            '- `artifact-index.json` lists every evidence file with size and SHA-256 hash.',
            '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function ceremonyDirectoryReadmeMarkdown(string $key, string $directory, array $context): string
    {
        $guide = $this->ceremonyDirectoryGuide()[$key] ?? [
            'title' => $directory,
            'purpose' => 'Supporting evidence for this ceremony step.',
            'common_files' => 'Evidence files produced by the scenario or operator workflow.',
            'review' => 'Confirm files listed here appear in `artifact-index.json`.',
        ];

        return implode(PHP_EOL, [
            '# '.$guide['title'],
            '',
            '- Folder: `'.$directory.'`',
            '- Run ID: `'.$context['run_id'].'`',
            '- Precinct: `'.$context['precinct_id'].'`',
            '',
            '## Purpose',
            '',
            $guide['purpose'],
            '',
            '## Common Evidence',
            '',
            $guide['common_files'],
            '',
            '## Review',
            '',
            $guide['review'],
            '',
        ]);
    }

    /**
     * @return array<string, array{title: string, purpose: string, common_files: string, review: string}>
     */
    private function ceremonyDirectoryGuide(): array
    {
        return [
            'start' => [
                'title' => 'Start Here',
                'purpose' => 'Entry point for the run evidence bundle.',
                'common_files' => 'Scenario report, lifecycle state, and quick-start notes.',
                'review' => 'Read `run-summary.txt`, then use `artifact-index.json` to verify hashes.',
            ],
            'preparation' => [
                'title' => 'Precinct Package and Configuration',
                'purpose' => 'Records the precinct package, ballot style, candidate set, and operator/officer setup used by the appliance.',
                'common_files' => 'Active package, active precinct configuration, ballot definition, candidate previews, officer registry, and electoral board baseline records.',
                'review' => 'Confirm the precinct, ballot style, candidate source hashes, and officer registry are correct before reviewing later ceremonies.',
            ],
            'certification' => [
                'title' => 'Final Testing and Sealing',
                'purpose' => 'Contains final testing, device certification, zero/known-result checks, and sealing evidence.',
                'common_files' => 'Device certification reports, certification QR scan documents, final testing reports, zero reports, and sealing attestations.',
                'review' => 'Confirm certification passed before any opening, voting, or counting evidence is accepted as an election run.',
            ],
            'opening' => [
                'title' => 'Opening of Polls',
                'purpose' => 'Records the ceremony that opens polls and captures officer attestations.',
                'common_files' => 'Opening reports, attestation JSON files, and officer signature PNG files.',
                'review' => 'Confirm required officers attested before voting evidence begins.',
            ],
            'voting' => [
                'title' => 'Voting',
                'purpose' => 'Contains ballot finalization, ballot printing, spoilage, and special polling intake evidence.',
                'common_files' => 'Ballot payload JSON files, QR artifacts, printable ballot text/PDF files, print jobs, spoiled ballot records, and special polling intake records.',
                'review' => 'Remember that paper ballots remain the legal source of truth; these files support reproducibility and audit.',
            ],
            'closing' => [
                'title' => 'Closing of Polls',
                'purpose' => 'Records the ceremony that closes polls and prepares the precinct for counting.',
                'common_files' => 'Close-polls reports and closing attestations when generated.',
                'review' => 'Confirm voting ended before counting and tally evidence begins.',
            ],
            'counting' => [
                'title' => 'Counting and Tally',
                'purpose' => 'Contains accepted/rejected ballot payload records and the resulting tally evidence.',
                'common_files' => 'Accepted ballot records, rejected ballot records, tally JSON, tally sheet text, and tally sheet PDF.',
                'review' => 'Check accepted and rejected counts against the tally sheet and Election Return.',
            ],
            'returns' => [
                'title' => 'Election Return',
                'purpose' => 'Contains generated Election Return artifacts and related attestations.',
                'common_files' => 'Election Return JSON, text, PDF, return attestations, and signature artifacts when present.',
                'review' => 'Confirm the Election Return hash and vote totals match the tally evidence.',
            ],
            'transmission' => [
                'title' => 'Transmission or Official Handoff',
                'purpose' => 'Records transmission as an official handoff of election artifacts.',
                'common_files' => 'Transmission report, delivery package, officer verification, recipient verification, and delivery receipt.',
                'review' => 'Confirm the handoff package references the correct Election Return and recipient verification evidence.',
            ],
            'final_backup' => [
                'title' => 'Final Backup',
                'purpose' => 'Contains final backup evidence produced after transmission or handoff.',
                'common_files' => 'Final backup report, backup text/PDF artifacts, and backup manifests when generated.',
                'review' => 'Confirm final backup hashes are recorded before custody turnover.',
            ],
            'custody' => [
                'title' => 'Custody Turnover',
                'purpose' => 'Records chain-of-custody and turnover evidence for election artifacts.',
                'common_files' => 'Custody record JSON/text/PDF and custody turnover report JSON/text/PDF.',
                'review' => 'Confirm custody records reference transmission, delivery receipt, final backup, and recipient evidence.',
            ],
            'precinct_closing' => [
                'title' => 'Close Precinct',
                'purpose' => 'Final precinct closure checkpoint after required reports, backup, and custody steps.',
                'common_files' => 'Close-precinct reports when generated.',
                'review' => 'Confirm no required ceremony remains pending before treating the run as closed.',
            ],
            'audit' => [
                'title' => 'Audit and Reconciliation',
                'purpose' => 'Contains audit, reconciliation, evidence manifest, export, archive, and verification records.',
                'common_files' => 'Evidence manifest, downloadable archive report, archive verification report, removable-media export reports, and reconciliation reports.',
                'review' => 'Use these files with `artifact-index.json` to verify evidence completeness and hashes.',
            ],
            'journal' => [
                'title' => 'Journal',
                'purpose' => 'Contains the append-only event history for the run.',
                'common_files' => 'Activity journal files and journal summaries.',
                'review' => 'Check journal sequence and hashes when reconstructing the ceremony timeline.',
            ],
        ];
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
     * @param  array<string, mixed>  $context
     */
    private function startHereReadmeMarkdown(array $context): string
    {
        return implode(PHP_EOL, [
            '# Start Here',
            '',
            '- Run ID: `'.$context['run_id'].'`',
            '- Precinct: `'.$context['precinct_id'].'`',
            '- Scenario: `'.$context['scenario'].'`',
            '',
            'Read `run-summary.txt` first for a plain-language summary, then use `artifact-index.json` to verify file hashes. Continue through the numbered ceremony folders in order.',
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
