<?php

namespace App\Election\Support;

use App\Election\Core\CanonicalJson;
use Illuminate\Filesystem\Filesystem;

final class ElectionStorage
{
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
        return storage_path('app/election-scenario-reports');
    }

    public function scenarioArtifactsRoot(): string
    {
        return storage_path('app/election-scenario-artifacts');
    }

    public function path(string $relative): string
    {
        return $this->root().'/'.ltrim($relative, '/');
    }

    public function scenarioReportPath(string $filename): string
    {
        return $this->scenarioReportsRoot().'/'.ltrim($filename, '/');
    }

    public function scenarioArtifactPath(string $relative): string
    {
        return $this->scenarioArtifactsRoot().'/'.ltrim($relative, '/');
    }

    public function ensureDirectories(): void
    {
        foreach ([
            'registries',
            'packages',
            'runtime',
            'journals',
            'ballots',
            'print-jobs',
            'counting/accepted',
            'counting/rejected',
            'returns',
            'certification',
            'attestations',
            'attestation-signatures',
            'diagnostics',
            'removable-media',
            'scenarios',
        ] as $directory) {
            $this->files->ensureDirectoryExists($this->path($directory));
        }
    }

    public function reset(): void
    {
        if ($this->files->exists($this->root())) {
            $this->files->deleteDirectory($this->root());
        }

        $this->ensureDirectories();
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
        $this->files->ensureDirectoryExists($this->scenarioReportsRoot());

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

    private function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value));
        $slug = trim($slug, '-');

        return $slug === '' ? 'unknown' : $slug;
    }
}
