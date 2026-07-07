<?php

namespace App\Election\Preparation;

use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class PopPrecinctRegistry
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
        return $this->storage->readJson('registries/'.PopWorkbookImporter::RegistryVersion.'/manifest.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function find(string $clusteredPrecinct): array
    {
        $clusteredPrecinct = trim($clusteredPrecinct);
        $manifest = $this->manifest();
        $indexPath = (string) ($manifest['index_path'] ?? '');
        $precinctsPath = (string) ($manifest['precincts_path'] ?? '');

        if ($indexPath === '' || $precinctsPath === '' || ! $this->files->exists($indexPath) || ! $this->files->exists($precinctsPath)) {
            throw new RuntimeException('POP registry has not been imported.');
        }

        $index = json_decode($this->files->get($indexPath), true, flags: JSON_THROW_ON_ERROR);
        $entry = $index[$clusteredPrecinct] ?? null;

        if (! is_array($entry)) {
            throw new RuntimeException("Clustered precinct [{$clusteredPrecinct}] was not found in the POP registry.");
        }

        $handle = fopen($precinctsPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open POP precinct registry.');
        }

        fseek($handle, (int) $entry['offset']);
        $line = fgets($handle);
        fclose($handle);

        if (! is_string($line)) {
            throw new RuntimeException("Unable to read clustered precinct [{$clusteredPrecinct}] from the POP registry.");
        }

        return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }
}
