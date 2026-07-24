<?php

namespace App\Election\Diagnostics;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use JsonException;

final class EvidenceBundleArchiveVerifier
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
    public function verify(?string $archivePath = null): array
    {
        $path = $this->resolveArchivePath($archivePath);

        if ($path === null || ! $this->files->exists($path)) {
            return $this->failedReport($path, [
                $this->mismatch('archive_missing', 'The evidence bundle archive was not found.', $path ?? 'evidence-bundle.tar'),
            ]);
        }

        $entries = $this->readTar($path);
        $archiveId = $this->archiveId($entries);
        $mismatches = [];

        if ($archiveId === null) {
            $mismatches[] = $this->mismatch('archive_index_missing', 'The archive does not contain a recognizable evidence bundle root.', 'archive-index.json');
        }

        $manifest = $archiveId === null ? null : $this->jsonEntry($path, $entries, $archiveId.'/evidence-manifest.json', 'manifest_invalid', $mismatches);
        $index = $archiveId === null ? null : $this->jsonEntry($path, $entries, $archiveId.'/archive-index.json', 'archive_index_invalid', $mismatches);

        if (is_array($manifest)) {
            $mismatches = [
                ...$mismatches,
                ...$this->manifestMismatches($manifest),
                ...$this->artifactMismatches($path, $entries, $archiveId, $manifest),
            ];
        }

        if (is_array($index) && is_array($manifest)) {
            $mismatches = [
                ...$mismatches,
                ...$this->indexMismatches($entries, $archiveId, $index, $manifest),
            ];
        }

        return [
            'schema_version' => 'evidence-bundle-archive-verification-1',
            'archive_path' => $path,
            'archive_id' => $archiveId,
            'archive_sha256' => hash_file('sha256', $path),
            'passed' => $mismatches === [],
            'checked_files' => is_array($manifest) ? $this->manifestFileCount($manifest) : 0,
            'mismatches' => $mismatches,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function writeReport(?string $archivePath = null, array $context = []): array
    {
        $report = [
            ...$this->verify($archivePath),
            'archive_source' => $context['archive_source'] ?? 'local-download',
            'verified_at' => $this->clock->now()->toIso8601String(),
            ...$context,
        ];
        $report['verification_hash'] = $this->json->hash($report);
        $report['artifact_path'] = $this->storage->writeJson('diagnostics/evidence-bundle-archive-verification.json', $report);

        $this->journal->record($report['passed'] ? 'evidence_bundle.archive_verification_passed' : 'evidence_bundle.archive_verification_failed', [
            'archive_source' => $report['archive_source'],
            'archive_id' => $report['archive_id'],
            'archive_sha256' => $report['archive_sha256'],
            'checked_files' => $report['checked_files'],
            'mismatch_count' => count($report['mismatches']),
            'verification_hash' => $report['verification_hash'],
        ]);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyUploadedArchive(UploadedFile $archive): array
    {
        return $this->verifyUploadedArchiveContents(
            $archive->getContent(),
            $archive->getClientOriginalName(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyUploadedArchiveContents(string $contents, string $originalName): array
    {
        $uploadedAt = $this->clock->now();
        $archiveHash = hash('sha256', $contents);
        $artifact = sprintf(
            'uploaded-evidence-bundle-%s-%s.tar',
            $uploadedAt->format('Ymd-His'),
            substr($archiveHash, 0, 12),
        );
        $path = $this->storage->path('diagnostics/uploaded-archives/'.$artifact);

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $contents);

        return $this->writeReport($path, [
            'archive_source' => 'operator-upload',
            'uploaded_archive_artifact' => $artifact,
            'uploaded_archive_original_name' => basename($originalName),
            'uploaded_archive_sha256' => $archiveHash,
            'uploaded_at' => $uploadedAt->toIso8601String(),
        ]);
    }

    private function resolveArchivePath(?string $archivePath): ?string
    {
        if (is_string($archivePath) && trim($archivePath) !== '') {
            return rtrim($archivePath, '/');
        }

        $report = $this->storage->readJson('diagnostics/evidence-bundle-archive.json');
        $artifact = (string) ($report['archive_artifact'] ?? '');

        if ($artifact === '' || $artifact !== basename($artifact)) {
            return null;
        }

        return $this->storage->path('diagnostics/'.$artifact);
    }

    /**
     * @return array<string, array{offset: int, size: int}>
     */
    private function readTar(string $path): array
    {
        $archive = fopen($path, 'rb');

        if ($archive === false) {
            return [];
        }

        $entries = [];

        try {
            while (($header = fread($archive, 512)) !== false && strlen($header) === 512) {
                if ($header === str_repeat("\0", 512)) {
                    break;
                }

                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                $size = octdec(trim(rtrim(substr($header, 124, 12), "\0")));
                $pathName = $prefix === '' ? $name : $prefix.'/'.$name;
                $dataOffset = ftell($archive);

                if ($dataOffset === false) {
                    break;
                }

                $entries[$pathName] = [
                    'offset' => $dataOffset,
                    'size' => $size,
                ];
                $nextHeader = $dataOffset + $size + ((512 - ($size % 512)) % 512);

                if (fseek($archive, $nextHeader) !== 0) {
                    break;
                }
            }
        } finally {
            fclose($archive);
        }

        ksort($entries);

        return $entries;
    }

    /**
     * @param  array<string, array{offset: int, size: int}>  $entries
     */
    private function archiveId(array $entries): ?string
    {
        foreach (array_keys($entries) as $name) {
            if (str_ends_with($name, '/archive-index.json')) {
                return explode('/', $name, 2)[0] ?? null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array{offset: int, size: int}>  $entries
     * @param  array<int, array<string, mixed>>  $mismatches
     * @return array<string, mixed>|null
     */
    private function jsonEntry(string $archivePath, array $entries, string $path, string $invalidType, array &$mismatches): ?array
    {
        if (! array_key_exists($path, $entries)) {
            $mismatches[] = $this->mismatch(str_replace('_invalid', '_missing', $invalidType), 'A required archive JSON file is missing.', $path);

            return null;
        }

        try {
            return json_decode($this->entryContents($archivePath, $entries[$path]), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $mismatches[] = $this->mismatch($invalidType, 'A required archive JSON file is invalid.', $path);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, array<string, mixed>>
     */
    private function manifestMismatches(array $manifest): array
    {
        $expected = $manifest['manifest_hash'] ?? null;
        $hashable = $manifest;
        unset($hashable['manifest_hash']);
        $actual = $this->json->hash($hashable);

        if ($expected === $actual) {
            return [];
        }

        return [
            $this->mismatch('manifest_hash_mismatch', 'The archive manifest hash does not match its contents.', 'evidence-manifest.json', $expected, $actual),
        ];
    }

    /**
     * @param  array<string, array{offset: int, size: int}>  $entries
     * @param  array<string, mixed>  $manifest
     * @return array<int, array<string, mixed>>
     */
    private function artifactMismatches(string $archivePath, array $entries, ?string $archiveId, array $manifest): array
    {
        if ($archiveId === null) {
            return [];
        }

        return collect($manifest['categories'] ?? [])
            ->flatMap(fn (array $category): array => $category['files'] ?? [])
            ->flatMap(function (array $file) use ($archivePath, $entries, $archiveId): array {
                $relativePath = (string) ($file['relative_path'] ?? '');
                $entryPath = $archiveId.'/artifacts/'.$relativePath;

                if ($relativePath === '' || ! array_key_exists($entryPath, $entries)) {
                    return [
                        $this->mismatch('artifact_missing', 'A manifest artifact is missing from the archive.', $entryPath),
                    ];
                }

                $mismatches = [];
                $actualBytes = $entries[$entryPath]['size'];
                $actualHash = $this->entryHash($archivePath, $entries[$entryPath]);

                if (($file['bytes'] ?? null) !== $actualBytes) {
                    $mismatches[] = $this->mismatch('artifact_size_mismatch', 'An archived artifact byte count does not match the manifest.', $entryPath, $file['bytes'] ?? null, $actualBytes);
                }

                if (($file['sha256'] ?? null) !== $actualHash) {
                    $mismatches[] = $this->mismatch('artifact_hash_mismatch', 'An archived artifact hash does not match the manifest.', $entryPath, $file['sha256'] ?? null, $actualHash);
                }

                return $mismatches;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array{offset: int, size: int}>  $entries
     * @param  array<string, mixed>  $index
     * @param  array<string, mixed>  $manifest
     * @return array<int, array<string, mixed>>
     */
    private function indexMismatches(array $entries, ?string $archiveId, array $index, array $manifest): array
    {
        $mismatches = [];

        if (($index['archive_id'] ?? null) !== $archiveId) {
            $mismatches[] = $this->mismatch('archive_id_mismatch', 'The archive index ID does not match the archive root.', 'archive-index.json', $archiveId, $index['archive_id'] ?? null);
        }

        if (($index['manifest_hash'] ?? null) !== ($manifest['manifest_hash'] ?? null)) {
            $mismatches[] = $this->mismatch('archive_manifest_hash_mismatch', 'The archive index manifest hash does not match the manifest.', 'archive-index.json', $manifest['manifest_hash'] ?? null, $index['manifest_hash'] ?? null);
        }

        if (($index['entry_count'] ?? null) !== count($entries)) {
            $mismatches[] = $this->mismatch('archive_entry_count_mismatch', 'The archive index entry count does not match the TAR entries.', 'archive-index.json', $index['entry_count'] ?? null, count($entries));
        }

        return $mismatches;
    }

    /**
     * @param  array{offset: int, size: int}  $entry
     */
    private function entryContents(string $archivePath, array $entry): string
    {
        $archive = fopen($archivePath, 'rb');

        if ($archive === false || fseek($archive, $entry['offset']) !== 0) {
            if (is_resource($archive)) {
                fclose($archive);
            }

            return '';
        }

        try {
            $remaining = $entry['size'];
            $contents = '';

            while ($remaining > 0) {
                $chunk = fread($archive, min(1024 * 1024, $remaining));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $contents .= $chunk;
                $remaining -= strlen($chunk);
            }

            return $contents;
        } finally {
            fclose($archive);
        }
    }

    /**
     * @param  array{offset: int, size: int}  $entry
     */
    private function entryHash(string $archivePath, array $entry): string
    {
        $archive = fopen($archivePath, 'rb');
        $hash = hash_init('sha256');

        if ($archive === false || fseek($archive, $entry['offset']) !== 0) {
            if (is_resource($archive)) {
                fclose($archive);
            }

            return hash_final($hash);
        }

        try {
            $remaining = $entry['size'];

            while ($remaining > 0) {
                $chunk = fread($archive, min(1024 * 1024, $remaining));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                hash_update($hash, $chunk);
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($archive);
        }

        return hash_final($hash);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function manifestFileCount(array $manifest): int
    {
        return collect($manifest['categories'] ?? [])
            ->sum(fn (array $category): int => count($category['files'] ?? []));
    }

    /**
     * @param  array<int, array<string, mixed>>  $mismatches
     * @return array<string, mixed>
     */
    private function failedReport(?string $path, array $mismatches): array
    {
        return [
            'schema_version' => 'evidence-bundle-archive-verification-1',
            'archive_path' => $path,
            'archive_id' => null,
            'archive_sha256' => $path !== null && $this->files->exists($path) ? hash_file('sha256', $path) : null,
            'passed' => false,
            'checked_files' => 0,
            'mismatches' => $mismatches,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mismatch(string $type, string $message, string $path, mixed $expected = null, mixed $actual = null): array
    {
        return [
            'type' => $type,
            'message' => $message,
            'path' => $path,
            'expected' => $expected,
            'actual' => $actual,
        ];
    }
}
