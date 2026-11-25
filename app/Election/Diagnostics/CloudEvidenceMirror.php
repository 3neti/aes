<?php

namespace App\Election\Diagnostics;

use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\ElectionRunType;
use App\Election\Support\ElectionRunScopeLock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class CloudEvidenceMirror
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly Filesystem $files,
        private readonly FilesystemManager $filesystems,
        private readonly CanonicalJson $json,
        private readonly ElectionRunScopeLock $runLock,
    ) {}

    /** @return array<string, mixed> */
    public function mirror(?string $runPath = null): array
    {
        if (! config('election.cloud_evidence.enabled', false)) {
            throw new RuntimeException('Cloud evidence mirroring is not enabled.');
        }
        if (config('election.voting_lock_driver') !== 'local') {
            throw new RuntimeException('Demo evidence mirroring requires the single-host local lock profile.');
        }

        $snapshotId = bin2hex(random_bytes(16));
        $snapshotRoot = storage_path('app/private/election-mirror-snapshots');
        if (! is_dir($snapshotRoot) && ! @mkdir($snapshotRoot, 0700, true) && ! is_dir($snapshotRoot)) {
            throw new RuntimeException('Unable to prepare the private evidence snapshot directory.');
        }
        clearstatcache(true, $snapshotRoot);
        $permissions = fileperms($snapshotRoot);
        if (is_link($snapshotRoot) || $permissions === false || ($permissions & 0077) !== 0) {
            throw new RuntimeException('The evidence snapshot directory is not private.');
        }
        $snapshotPath = $snapshotRoot.'/'.$snapshotId;
        $createdSnapshot = false;
        $failure = null;

        try {
            $sourceRun = $this->runLock->executeCommand(function () use ($runPath, $snapshotPath, &$createdSnapshot): string {
                $sourceRun = $this->validatedRunPath($runPath);
                if (! @mkdir($snapshotPath, 0700)) {
                    throw new RuntimeException('Unable to create the private evidence snapshot.');
                }
                $createdSnapshot = true;
                $this->copySnapshot($sourceRun, $snapshotPath);
                $this->verifySnapshot($snapshotPath, $sourceRun);

                return $sourceRun;
            });

            return $this->publishSnapshot($snapshotPath, basename($sourceRun), $snapshotId);
        } catch (Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            if ($createdSnapshot && is_dir($snapshotPath) && ! $this->files->deleteDirectory($snapshotPath)) {
                throw new RuntimeException('Unable to remove the private evidence snapshot. Operator cleanup is required.', previous: $failure);
            }
        }
    }

    private function validatedRunPath(?string $requested): string
    {
        if ($requested === null) {
            $run = $this->storage->currentRun();
            $requested = $run['run_path'] ?? null;
        }
        if (! is_string($requested) || $requested === '') {
            throw new RuntimeException('There is no existing run to mirror.');
        }
        $root = realpath($this->storage->scenarioArtifactsRoot());
        $path = realpath($requested);
        if ($root === false || $path === false || dirname($path) !== $root || ! is_dir($path) || is_link($requested)) {
            throw new RuntimeException('Only an election run directory may be mirrored.');
        }

        return $path;
    }

    private function copySnapshot(string $source, string $target): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $relative = substr($path, strlen($source) + 1);
            $destination = $target.'/'.$relative;
            if ($entry->isLink()) {
                throw new RuntimeException('Election evidence snapshots cannot include symbolic links.');
            }
            if ($entry->isDir()) {
                if (! @mkdir($destination, 0700) && ! is_dir($destination)) {
                    throw new RuntimeException('Unable to create an evidence snapshot directory.');
                }

                continue;
            }
            if (! $entry->isFile()) {
                throw new RuntimeException('Election evidence snapshots require regular files.');
            }
            $before = hash_file('sha256', $path);
            if (! is_string($before) || ! @copy($path, $destination)) {
                throw new RuntimeException('Unable to copy election evidence into the snapshot.');
            }
            $after = hash_file('sha256', $path);
            $copied = hash_file('sha256', $destination);
            if (! is_string($after) || ! is_string($copied) || ! hash_equals($before, $after) || ! hash_equals($before, $copied)) {
                throw new RuntimeException('Election evidence changed while the snapshot was copied.');
            }
        }
    }

    private function verifySnapshot(string $snapshot, string $sourceRun): void
    {
        $context = $this->snapshotJson($snapshot.'/run-context.json');
        if (($context['run_id'] ?? null) !== basename($sourceRun)
            || ($context['run_path'] ?? null) !== $sourceRun
            || ($context['status'] ?? null) !== 'locked'
            || ! in_array($context['run_type'] ?? null, [ElectionRunType::Rehearsal->value, ElectionRunType::AutomatedTest->value], true)) {
            throw new RuntimeException('Only a locked demo or automated-test run may be mirrored.');
        }
        $index = $this->snapshotJson($snapshot.'/artifact-index.json');
        $recordedHash = $index['index_hash'] ?? null;
        unset($index['index_hash']);
        if (($index['schema_version'] ?? null) !== 'election-run-artifact-index-1'
            || ($index['run_id'] ?? null) !== basename($sourceRun)
            || ($index['run_path'] ?? null) !== $sourceRun
            || ! is_string($recordedHash) || ! hash_equals($recordedHash, $this->json->hash($index))
            || ! is_array($index['artifacts'] ?? null) || ! array_is_list($index['artifacts'])
            || ($index['artifact_count'] ?? null) !== count($index['artifacts'])) {
            throw new RuntimeException('The finalized evidence index is invalid.');
        }
        $indexed = [];
        $totalBytes = 0;
        foreach ($index['artifacts'] as $artifact) {
            if (! is_array($artifact)) {
                throw new RuntimeException('The finalized evidence index contains an invalid artifact.');
            }
            $relative = $artifact['relative_path'] ?? null;
            if (! is_string($relative) || $relative === '' || str_starts_with($relative, '/')
                || in_array('..', explode('/', $relative), true) || in_array('.', explode('/', $relative), true)
                || in_array('', explode('/', $relative), true) || isset($indexed[$relative])
                || ! is_int($artifact['bytes'] ?? null) || $artifact['bytes'] < 0) {
                throw new RuntimeException('The finalized evidence index contains an invalid path.');
            }
            $path = $snapshot.'/'.$relative;
            $hash = is_file($path) ? hash_file('sha256', $path) : false;
            if (! is_string($hash) || ! is_string($artifact['sha256'] ?? null)
                || ! hash_equals($artifact['sha256'], $hash) || filesize($path) !== ($artifact['bytes'] ?? null)) {
                throw new RuntimeException('The finalized evidence does not match its index.');
            }
            $indexed[$relative] = true;
            $totalBytes += $artifact['bytes'];
        }
        if (($index['total_bytes'] ?? null) !== $totalBytes || ! is_file($snapshot.'/run-summary.txt')) {
            throw new RuntimeException('The finalized evidence index is inconsistent.');
        }
        foreach ($this->files->allFiles($snapshot) as $file) {
            $relative = substr($file->getPathname(), strlen($snapshot) + 1);
            if (! isset($indexed[$relative]) && ! in_array($relative, ['artifact-index.json', 'run-summary.json', 'run-summary.txt'], true)) {
                throw new RuntimeException('The finalized run contains unindexed evidence.');
            }
        }
        $summary = $this->snapshotJson($snapshot.'/run-summary.json');
        $summaryHash = $summary['summary_hash'] ?? null;
        unset($summary['summary_hash']);
        if (($summary['schema_version'] ?? null) !== 'election-run-summary-1'
            || ($summary['run_id'] ?? null) !== basename($sourceRun)
            || ! is_string($summaryHash) || ! hash_equals($summaryHash, $this->json->hash($summary))) {
            throw new RuntimeException('The finalized run summary is invalid.');
        }
    }

    /** @return array<string, mixed> */
    private function snapshotJson(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Finalized election evidence is missing or unreadable.');
        }
        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Finalized election evidence is invalid.', previous: $exception);
        }
        if (! is_array($data)) {
            throw new RuntimeException('Finalized election evidence is invalid.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function publishSnapshot(string $snapshot, string $runId, string $snapshotId): array
    {
        $diskName = (string) config('election.cloud_evidence.disk', 'election_evidence');
        $disk = $this->filesystems->disk($diskName);
        $prefix = trim((string) config('election.cloud_evidence.prefix', 'review-evidence'), '/');
        $versionRoot = ($prefix === '' ? '' : $prefix.'/').'runs/'.$runId.'/snapshots/'.$snapshotId;
        $remoteRoot = $versionRoot.'/artifacts';
        if ($disk->allFiles($versionRoot) !== []) {
            throw new RuntimeException('The evidence snapshot destination is already in use.');
        }
        $artifacts = [];
        foreach (collect($this->files->allFiles($snapshot))->sortBy->getPathname() as $file) {
            $relative = substr($file->getPathname(), strlen($snapshot) + 1);
            $localHash = hash_file('sha256', $file->getPathname());
            $stream = fopen($file->getPathname(), 'rb');
            if (! is_string($localHash) || ! is_resource($stream)) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                throw new RuntimeException('Unable to read an evidence snapshot file.');
            }
            try {
                if (! $disk->put($remoteRoot.'/'.$relative, $stream)) {
                    throw new RuntimeException('Unable to upload an evidence snapshot file.');
                }
            } finally {
                fclose($stream);
            }
            $after = hash_file('sha256', $file->getPathname());
            if (! is_string($after) || ! hash_equals($localHash, $after)
                || ! hash_equals($localHash, $this->hashRemoteFile($disk, $remoteRoot.'/'.$relative))) {
                throw new RuntimeException('Mirrored evidence failed verification.');
            }
            $artifacts[] = ['relative_path' => $relative, 'bytes' => $file->getSize(), 'sha256' => $localHash];
        }
        $manifest = [
            'schema_version' => 'cloud-election-evidence-manifest-1',
            'run_id' => $runId,
            'disk' => $diskName,
            'remote_root' => $remoteRoot,
            'snapshot_root' => $versionRoot,
            'mirrored_at' => now()->toIso8601String(),
            'artifact_count' => count($artifacts),
            'total_bytes' => collect($artifacts)->sum('bytes'),
            'artifacts' => $artifacts,
        ];
        $manifest['manifest_hash'] = $this->json->hash($manifest);
        $manifestPath = $versionRoot.'/cloud-evidence-manifest.json';
        $contents = $this->json->encode($manifest);
        if (! $disk->put($manifestPath, $contents)
            || ! hash_equals(hash('sha256', $contents), $this->hashRemoteFile($disk, $manifestPath))) {
            throw new RuntimeException('Unable to write and verify the cloud evidence manifest.');
        }

        return [...$manifest, 'manifest_path' => $manifestPath, 'passed' => true];
    }

    private function hashRemoteFile(FilesystemAdapter $disk, string $path): string
    {
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to read mirrored election evidence.');
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
