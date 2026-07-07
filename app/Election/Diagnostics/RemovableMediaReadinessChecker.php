<?php

namespace App\Election\Diagnostics;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class RemovableMediaReadinessChecker
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly Filesystem $files,
        private readonly CanonicalJson $json,
        private readonly ElectionClock $clock,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function check(): array
    {
        $targetRoot = $this->targetRoot();
        $checks = [
            $this->configuredPathCheck(),
            $this->directoryCheck($targetRoot),
            $this->writableCheck($targetRoot),
            $this->probeWriteCheck($targetRoot),
        ];

        $report = [
            'schema_version' => 'removable-media-readiness-report-1',
            'checked_at' => $this->clock->now()->toIso8601String(),
            'target_path' => $targetRoot,
            'configured' => $this->configuredPath() !== '',
            'ready' => collect($checks)->every(fn (array $check): bool => $check['passed'] === true),
            'checks' => $checks,
        ];
        $report['status'] = $this->status($report);
        $report['status_label'] = $this->statusLabel($report['status']);
        $report['readiness_hash'] = $this->json->hash($report);
        $report['artifact_path'] = $this->storage->writeJson('diagnostics/removable-media-readiness.json', $report);

        $this->journal->record($report['ready'] ? 'removable_media.readiness_passed' : 'removable_media.readiness_failed', [
            'configured' => $report['configured'],
            'readiness_hash' => $report['readiness_hash'],
            'target_path' => $targetRoot,
        ]);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function status(array $report): string
    {
        if (($report['ready'] ?? false) === true) {
            return ($report['configured'] ?? false) ? 'ready' : 'simulated_ready';
        }

        $checks = collect($report['checks'] ?? []);

        if ($checks->firstWhere('name', 'directory_available')['passed'] === false) {
            return 'missing';
        }

        if ($checks->firstWhere('name', 'writable')['passed'] === false) {
            return 'not_writable';
        }

        if ($checks->firstWhere('name', 'probe_write_delete')['passed'] === false) {
            return 'probe_failed';
        }

        return 'not_ready';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'ready' => 'Ready',
            'simulated_ready' => 'Simulated Local Target Ready',
            'missing' => 'Target Missing',
            'not_writable' => 'Target Not Writable',
            'probe_failed' => 'Probe Write Failed',
            default => 'Not Ready',
        };
    }

    private function targetRoot(): string
    {
        $configuredPath = $this->configuredPath();

        if ($configuredPath !== '') {
            return rtrim($configuredPath, '/');
        }

        return $this->storage->path('removable-media');
    }

    private function configuredPath(): string
    {
        return trim((string) config('election.removable_media.path', ''));
    }

    /**
     * @return array{name: string, passed: bool, message: string}
     */
    private function configuredPathCheck(): array
    {
        if ($this->configuredPath() !== '') {
            return [
                'name' => 'configured_path',
                'passed' => true,
                'message' => 'A removable media target path is configured.',
            ];
        }

        return [
            'name' => 'configured_path',
            'passed' => true,
            'message' => 'Using the local simulated removable media staging path.',
        ];
    }

    /**
     * @return array{name: string, passed: bool, message: string}
     */
    private function directoryCheck(string $targetRoot): array
    {
        if ($this->configuredPath() === '') {
            $this->files->ensureDirectoryExists($targetRoot);
        }

        return [
            'name' => 'directory_available',
            'passed' => $this->files->isDirectory($targetRoot),
            'message' => $this->files->isDirectory($targetRoot)
                ? 'The target path is available as a directory.'
                : 'The target path is not available as a directory.',
        ];
    }

    /**
     * @return array{name: string, passed: bool, message: string}
     */
    private function writableCheck(string $targetRoot): array
    {
        return [
            'name' => 'writable',
            'passed' => is_writable($targetRoot),
            'message' => is_writable($targetRoot)
                ? 'The target path is writable by the appliance process.'
                : 'The target path is not writable by the appliance process.',
        ];
    }

    /**
     * @return array{name: string, passed: bool, message: string}
     */
    private function probeWriteCheck(string $targetRoot): array
    {
        $probePath = $targetRoot.'/.aes-readiness-probe';

        try {
            $this->files->put($probePath, $this->clock->now()->toIso8601String());
            $written = $this->files->exists($probePath);
            $this->files->delete($probePath);

            return [
                'name' => 'probe_write_delete',
                'passed' => $written && ! $this->files->exists($probePath),
                'message' => $written
                    ? 'The appliance can write and remove a readiness probe file.'
                    : 'The appliance could not write a readiness probe file.',
            ];
        } catch (Throwable $exception) {
            return [
                'name' => 'probe_write_delete',
                'passed' => false,
                'message' => 'Probe write failed: '.$exception->getMessage(),
            ];
        }
    }
}
