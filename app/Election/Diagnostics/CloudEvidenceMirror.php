<?php

namespace App\Election\Diagnostics;

use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;

final class CloudEvidenceMirror
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly Filesystem $files,
        private readonly FilesystemManager $filesystems,
        private readonly CanonicalJson $json,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function mirror(?string $runPath = null): array
    {
        if (! config('election.cloud_evidence.enabled', false)) {
            throw new RuntimeException('Cloud evidence mirroring is not enabled.');
        }

        $runPath ??= $this->storage->activeRunPath();
        $runPath = realpath($runPath) ?: $runPath;
        $runsRoot = realpath($this->storage->scenarioArtifactsRoot()) ?: $this->storage->scenarioArtifactsRoot();

        if (! $this->files->isDirectory($runPath) || ! str_starts_with($runPath.'/', rtrim($runsRoot, '/').'/')) {
            throw new RuntimeException('Only an election run directory may be mirrored.');
        }

        $diskName = (string) config('election.cloud_evidence.disk', 'election_evidence');
        $disk = $this->filesystems->disk($diskName);
        $remoteRoot = $this->remoteRoot(basename($runPath));
        $artifacts = [];

        foreach (collect($this->files->allFiles($runPath))->sortBy->getPathname() as $file) {
            $relativePath = str_replace($runPath.'/', '', $file->getPathname());
            $remotePath = $remoteRoot.'/'.$relativePath;
            $stream = fopen($file->getPathname(), 'rb');

            if (! is_resource($stream)) {
                throw new RuntimeException("Unable to read local election evidence [{$relativePath}].");
            }

            try {
                if (! $disk->put($remotePath, $stream)) {
                    throw new RuntimeException("Unable to mirror election evidence [{$relativePath}].");
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $localHash = hash_file('sha256', $file->getPathname());

            if (! is_string($localHash)) {
                throw new RuntimeException("Unable to hash local election evidence [{$relativePath}].");
            }
            $remoteHash = $this->hashRemoteFile($disk, $remotePath);

            if (! hash_equals($localHash, $remoteHash)) {
                throw new RuntimeException("Mirrored election evidence failed verification [{$relativePath}].");
            }

            $artifacts[] = [
                'relative_path' => $relativePath,
                'bytes' => $file->getSize(),
                'sha256' => $localHash,
            ];
        }

        $manifest = [
            'schema_version' => 'cloud-election-evidence-manifest-1',
            'run_id' => basename($runPath),
            'disk' => $diskName,
            'remote_root' => $remoteRoot,
            'mirrored_at' => now()->toIso8601String(),
            'artifact_count' => count($artifacts),
            'total_bytes' => collect($artifacts)->sum('bytes'),
            'artifacts' => $artifacts,
        ];
        $manifest['manifest_hash'] = $this->json->hash($manifest);
        $manifestPath = $remoteRoot.'/cloud-evidence-manifest.json';
        $currentRemotePaths = collect($artifacts)
            ->pluck('relative_path')
            ->map(fn (string $relativePath): string => $remoteRoot.'/'.$relativePath)
            ->push($manifestPath);
        $staleRemotePaths = collect($disk->allFiles($remoteRoot))
            ->diff($currentRemotePaths)
            ->values();

        if ($staleRemotePaths->isNotEmpty() && ! $disk->delete($staleRemotePaths->all())) {
            throw new RuntimeException('Unable to remove stale cloud election evidence.');
        }

        if (! $disk->put($manifestPath, $this->json->encode($manifest))) {
            throw new RuntimeException('Unable to write the cloud evidence manifest.');
        }

        return [
            ...$manifest,
            'manifest_path' => $manifestPath,
            'passed' => true,
        ];
    }

    private function remoteRoot(string $runId): string
    {
        $prefix = trim((string) config('election.cloud_evidence.prefix', 'review-evidence'), '/');

        return ($prefix === '' ? '' : $prefix.'/').'runs/'.$runId;
    }

    private function hashRemoteFile(FilesystemAdapter $disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read mirrored election evidence [{$path}].");
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
