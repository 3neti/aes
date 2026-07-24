<?php

namespace App\Console\Commands;

use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\ElectionRunType;
use App\Election\Scenarios\BrowserWalkthroughControl;
use App\Election\Scenarios\BrowserWalkthroughRecorder;
use App\Election\Support\ElectionStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

#[Signature('election:browser-walkthrough
    {scenario : full-election}
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
        ElectionStorage $storage,
        CanonicalJson $json,
        Filesystem $files,
    ): int {
        $scenario = (string) $this->argument('scenario');
        $ballots = filter_var($this->option('ballots'), FILTER_VALIDATE_INT);
        $slowMotion = filter_var($this->option('slow-mo'), FILTER_VALIDATE_INT);
        $baseUrl = rtrim((string) ($this->option('base-url') ?: config('app.url')), '/');

        if ($scenario !== 'full-election') {
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

        try {
            $walkthrough = $control->begin(
                $scenario,
                (string) config('election.pop.clustered_precinct', '39010001'),
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
            );

            $report = [
                'schema_version' => 'browser-walkthrough-report-1',
                'scenario' => $scenario,
                'passed' => ($result['passed'] ?? false) === true,
                'run_id' => $walkthrough['run_id'],
                'run_type' => ElectionRunType::Rehearsal->value,
                'precinct_id' => config('election.pop.clustered_precinct', '39010001'),
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

            $control->complete(
                (string) $walkthrough['token'],
                $report['passed'] ? 'passed' : 'failed',
            );

            $storage->selectRunType(ElectionRunType::Rehearsal);
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
}
