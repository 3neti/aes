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

    public function path(string $relative): string
    {
        return $this->root().'/'.ltrim($relative, '/');
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
}
