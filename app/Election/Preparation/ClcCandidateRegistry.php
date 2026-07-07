<?php

namespace App\Election\Preparation;

use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class ClcCandidateRegistry
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return $this->storage->readJson('registries/'.ClcCandidateImporter::registryVersion().'/manifest.json');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function candidates(): array
    {
        $manifest = $this->manifest();
        $path = (string) ($manifest['candidates_path'] ?? '');

        if ($path === '' || ! $this->files->exists($path)) {
            throw new RuntimeException('CLC candidate registry has not been imported.');
        }

        return collect(explode("\n", trim($this->files->get($path))))
            ->filter()
            ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR))
            ->values()
            ->all();
    }
}
