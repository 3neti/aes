<?php

namespace App\Console\Commands;

use App\Election\Core\CanonicalJson;
use App\Election\Diagnostics\EvidenceBundleArchiveBuilder;
use App\Election\Diagnostics\EvidenceBundleArchiveVerifier;
use App\Election\Lifecycle\ElectionRunType;
use App\Election\PublicSimulation\PublicSimulationService;
use App\Election\Scenarios\BrowserWalkthroughControl;
use App\Election\Scenarios\BrowserWalkthroughEvidenceReport;
use App\Election\Scenarios\BrowserWalkthroughRecorder;
use App\Election\Support\ElectionStorage;
use App\Models\SimulationPrecinct;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

#[Signature('election:browser-walkthrough
    {scenario : full-election or public-simulation}
    {--ballots=3 : Number of voter ballots to cast}
    {--headed : Show the browser while the walkthrough runs}
    {--slow-mo=150 : Delay browser actions by this many milliseconds}
    {--base-url= : Local appliance URL; defaults to APP_URL}')]
#[Description('Run and record a browser walkthrough in an isolated rehearsal evidence folder.')]
final class ElectionBrowserWalkthroughCommand extends Command
{
    public function handle(
        BrowserWalkthroughControl $control,
        BrowserWalkthroughRecorder $recorder,
        BrowserWalkthroughEvidenceReport $evidenceReport,
        EvidenceBundleArchiveBuilder $archiveBuilder,
        EvidenceBundleArchiveVerifier $archiveVerifier,
        ElectionStorage $storage,
        PublicSimulationService $publicSimulations,
        CanonicalJson $json,
        Filesystem $files,
    ): int {
        $scenario = (string) $this->argument('scenario');
        $ballots = filter_var($this->option('ballots'), FILTER_VALIDATE_INT);
        $slowMotion = filter_var($this->option('slow-mo'), FILTER_VALIDATE_INT);
        $baseUrl = rtrim((string) ($this->option('base-url') ?: config('app.url')), '/');

        if (! in_array($scenario, ['full-election', 'public-simulation'], true)) {
            $this->error("Unsupported browser walkthrough [{$scenario}].");

            return self::INVALID;
        }

        if (! is_int($ballots) || $ballots < 1 || $ballots > 50) {
            $this->error('The ballot count must be between 1 and 50.');

            return self::INVALID;
        }

        if (! is_int($slowMotion) || $slowMotion < 0 || $slowMotion > 2000) {
            $this->error('The slow motion delay must be between 0 and 2000 milliseconds.');

            return self::INVALID;
        }

        if (! $this->isLocalUrl($baseUrl)) {
            $this->error('Browser walkthroughs may target only a local appliance URL.');

            return self::INVALID;
        }

        $previousRunType = config('election.runtime.run_type');
        $context = $this->scenarioContext($scenario, $publicSimulations);

        try {
            $walkthrough = $control->begin(
                $scenario,
                $context['precinct_id'],
            );
            $artifactDirectory = $walkthrough['run_path'].'/12-audit-and-reconciliation/browser-recordings';
            $files->ensureDirectoryExists($artifactDirectory);

            $result = $recorder->record(
                $scenario,
                $baseUrl,
                (string) $walkthrough['token'],
                $artifactDirectory,
                $ballots,
                (bool) $this->option('headed'),
                $slowMotion,
                $context['environment'],
            );
            $storage->selectRunType(ElectionRunType::Rehearsal);

            $report = [
                'schema_version' => 'browser-walkthrough-report-1',
                'scenario' => $scenario,
                'passed' => ($result['passed'] ?? false) === true,
                'run_id' => $walkthrough['run_id'],
                'run_type' => ElectionRunType::Rehearsal->value,
                'precinct_id' => $context['precinct_id'],
                'context' => $context['report'],
                'base_url' => $baseUrl,
                'ballots_requested' => $ballots,
                'walkthrough_id' => $walkthrough['walkthrough_id'],
                'statistics' => $result['statistics'] ?? [],
                'artifacts' => $result['artifacts'] ?? [],
                'error' => $result['error'] ?? null,
                'process_exit_code' => $result['process_exit_code'] ?? null,
                'process_error_output' => $result['process_error_output'] ?? '',
            ];
            $report['report_hash'] = $json->hash($report);
            $reportPath = $artifactDirectory.'/browser-walkthrough-report.json';
            $files->put($reportPath, $json->encode($report));

            $postProcessing = [];
            $postProcessingError = null;

            try {
                $postProcessing = $evidenceReport->write(
                    $walkthrough['run_path'],
                    $artifactDirectory,
                    $report,
                );
                $archive = $archiveBuilder->build();
                $verification = $archiveVerifier->writeReport(
                    $archive['archive_path'],
                    ['archive_source' => 'browser-walkthrough-finalization'],
                );
                $postProcessing = [
                    ...$postProcessing,
                    'archive_path' => $archive['archive_path'],
                    'archive_sha256' => $archive['archive_sha256'],
                    'archive_entry_count' => $archive['entry_count'],
                    'archive_verification_path' => $verification['artifact_path'],
                    'archive_verification_hash' => $verification['verification_hash'],
                    'archive_verified' => ($verification['passed'] ?? false) === true,
                    'archive_checked_files' => $verification['checked_files'] ?? 0,
                ];

                if (! $postProcessing['archive_verified']) {
                    $postProcessingError = 'The post-recording evidence archive failed verification.';
                }
            } catch (Throwable $exception) {
                $postProcessingError = $exception->getMessage();
            }

            $report['passed'] = $report['passed'] && $postProcessingError === null;
            $report['statistics']['post_recording_archive_verified'] = $postProcessing['archive_verified'] ?? false;
            $report['statistics']['post_recording_archive_checked_files'] = $postProcessing['archive_checked_files'] ?? 0;
            $report['post_recording'] = $postProcessing;

            if ($postProcessingError !== null) {
                $report['error'] = trim(implode(' ', array_filter([
                    $report['error'],
                    $postProcessingError,
                ])));
            }

            $completion = [
                'schema_version' => 'browser-walkthrough-completion-1',
                'run_id' => $walkthrough['run_id'],
                'walkthrough_id' => $walkthrough['walkthrough_id'],
                'recording_report' => $this->artifact('Browser walkthrough report', $reportPath),
                'recording_passed' => ($result['passed'] ?? false) === true,
                'post_recording' => $postProcessing,
                'passed' => $report['passed'],
                'error' => $report['error'],
                'completed_at' => now()->toIso8601String(),
            ];
            $completion['completion_hash'] = $json->hash($completion);
            $completionPath = $artifactDirectory.'/browser-walkthrough-completion.json';
            $files->put($completionPath, $json->encode($completion));

            foreach ($this->postRecordingArtifacts($postProcessing, $completionPath) as $artifact) {
                $report['artifacts'][] = $artifact;
            }

            $control->complete(
                (string) $walkthrough['token'],
                $report['passed'] ? 'passed' : 'failed',
            );

            $storage->lockActiveRun();
            $finalized = $storage->finalizeRun($scenario, $report);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            config()->set('election.runtime.run_type', $previousRunType);
        }

        $this->line('Browser walkthrough '.($report['passed'] ? 'passed.' : 'failed.'));
        $this->line("Run ID: {$finalized['run_id']}");
        $this->line("Run Folder: {$finalized['run_path']}");
        $this->line("Report: {$reportPath}");

        foreach ($report['artifacts'] as $artifact) {
            if (is_array($artifact) && isset($artifact['label'], $artifact['path'])) {
                $this->line("{$artifact['label']}: {$artifact['path']}");
            }
        }

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{precinct_id: string, environment: array<string, string>, report: array<string, mixed>}
     */
    private function scenarioContext(string $scenario, PublicSimulationService $publicSimulations): array
    {
        if ($scenario === 'full-election') {
            return [
                'precinct_id' => (string) config('election.pop.clustered_precinct', '39010001'),
                'environment' => [],
                'report' => [],
            ];
        }

        $round = $publicSimulations->createWalkthroughRound()->load('precincts');
        $precinct = $round->precincts
            ->sortBy('code')
            ->first(fn (SimulationPrecinct $precinct): bool => $precinct->status === 'ready');

        if (! $precinct instanceof SimulationPrecinct) {
            throw new RuntimeException('No ready public simulation precinct is available for a browser walkthrough. Reset the public simulation round or publish an unfinished precinct first.');
        }

        return [
            'precinct_id' => $precinct->clustered_precinct,
            'environment' => [
                'ELECTION_WALKTHROUGH_PUBLIC_ROUND' => $round->code,
                'ELECTION_WALKTHROUGH_PUBLIC_PRECINCT' => $precinct->code,
                'ELECTION_WALKTHROUGH_PUBLIC_OFFICER_CODE' => $precinct->officer_code,
                'ELECTION_WALKTHROUGH_PUBLIC_OFFICER_PIN' => '123456',
            ],
            'report' => [
                'round_code' => $round->code,
                'precinct_code' => $precinct->code,
                'precinct_label' => $precinct->label,
                'officer_name' => $precinct->officer_name,
            ],
        ];
    }

    private function isLocalUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && ($host === 'localhost'
                || $host === '127.0.0.1'
                || $host === '::1'
                || str_ends_with($host, '.test'));
    }

    /**
     * @param  array<string, mixed>  $postProcessing
     * @return array<int, array<string, mixed>>
     */
    private function postRecordingArtifacts(array $postProcessing, string $completionPath): array
    {
        $paths = [
            'Browser lifecycle report' => $postProcessing['lifecycle_report_path'] ?? null,
            'Browser lifecycle text report' => $postProcessing['lifecycle_report_text_path'] ?? null,
            'Browser artifact index' => $postProcessing['browser_artifact_index_path'] ?? null,
            'Final evidence archive' => $postProcessing['archive_path'] ?? null,
            'Final evidence archive verification' => $postProcessing['archive_verification_path'] ?? null,
            'Browser walkthrough completion' => $completionPath,
        ];

        return collect($paths)
            ->filter(fn (mixed $path): bool => is_string($path) && file_exists($path))
            ->map(fn (string $path, string $label): array => $this->artifact($label, $path))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(string $label, string $path): array
    {
        return [
            'label' => $label,
            'path' => $path,
            'relative_path' => basename($path),
            'bytes' => filesize($path),
            'sha256' => hash_file('sha256', $path),
        ];
    }
}
