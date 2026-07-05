<?php

namespace App\Election\Diagnostics;

use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;
use JsonException;

final class RemovableMediaExportVerifier
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly Filesystem $files,
        private readonly CanonicalJson $json,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function verify(?string $exportPath = null): array
    {
        $path = $this->resolveExportPath($exportPath);

        if ($path === null) {
            return $this->failedReport(null, [
                $this->mismatch('export_missing', 'No staged removable-media export was found.', 'export-report.json'),
            ]);
        }

        $reportPath = $path.'/export-report.json';
        $manifestPath = $path.'/evidence-manifest.json';

        if (! $this->files->exists($reportPath)) {
            return $this->failedReport($path, [
                $this->mismatch('report_missing', 'The export report is missing.', 'export-report.json'),
            ]);
        }

        try {
            $report = json_decode($this->files->get($reportPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->failedReport($path, [
                $this->mismatch('report_invalid', 'The export report is not valid JSON.', 'export-report.json'),
            ]);
        }

        $mismatches = [
            ...$this->reportHashMismatches($report),
            ...$this->manifestMismatches($manifestPath, $report),
            ...$this->copiedFileMismatches($path, $report),
        ];

        return [
            'schema_version' => 'removable-media-export-verification-1',
            'export_path' => $path,
            'export_id' => $report['export_id'] ?? null,
            'passed' => $mismatches === [],
            'checked_files' => count($report['copied_files'] ?? []),
            'mismatches' => $mismatches,
        ];
    }

    private function resolveExportPath(?string $exportPath): ?string
    {
        if (is_string($exportPath) && trim($exportPath) !== '') {
            return rtrim($exportPath, '/');
        }

        $root = $this->removableMediaRoot();

        if (! $this->files->isDirectory($root)) {
            return null;
        }

        return collect($this->files->directories($root))
            ->filter(fn (string $directory): bool => $this->files->exists($directory.'/export-report.json'))
            ->sort()
            ->last();
    }

    private function removableMediaRoot(): string
    {
        $configuredPath = trim((string) config('election.removable_media.path', ''));

        if ($configuredPath !== '') {
            return rtrim($configuredPath, '/');
        }

        return $this->storage->path('removable-media');
    }

    /**
     * @param  array<int, array<string, mixed>>  $mismatches
     * @return array<string, mixed>
     */
    private function failedReport(?string $path, array $mismatches): array
    {
        return [
            'schema_version' => 'removable-media-export-verification-1',
            'export_path' => $path,
            'export_id' => null,
            'passed' => false,
            'checked_files' => 0,
            'mismatches' => $mismatches,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, array<string, mixed>>
     */
    private function reportHashMismatches(array $report): array
    {
        $expected = $report['export_hash'] ?? null;
        $hashable = $report;
        unset($hashable['export_hash']);
        $actual = $this->json->hash($hashable);

        if ($expected === $actual) {
            return [];
        }

        return [
            $this->mismatch('report_hash_mismatch', 'The export report hash does not match its contents.', 'export-report.json', $expected, $actual),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, array<string, mixed>>
     */
    private function manifestMismatches(string $manifestPath, array $report): array
    {
        if (! $this->files->exists($manifestPath)) {
            return [
                $this->mismatch('manifest_missing', 'The evidence manifest is missing.', 'evidence-manifest.json'),
            ];
        }

        try {
            $manifest = json_decode($this->files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [
                $this->mismatch('manifest_invalid', 'The evidence manifest is not valid JSON.', 'evidence-manifest.json'),
            ];
        }

        $expectedHash = $report['manifest_hash'] ?? null;
        $hashable = $manifest;
        unset($hashable['manifest_hash']);
        $actualManifestHash = $this->json->hash($hashable);

        $mismatches = [];

        if (($manifest['manifest_hash'] ?? null) !== $actualManifestHash) {
            $mismatches[] = $this->mismatch(
                'manifest_hash_mismatch',
                'The evidence manifest hash does not match its contents.',
                'evidence-manifest.json',
                $manifest['manifest_hash'] ?? null,
                $actualManifestHash,
            );
        }

        if ($expectedHash !== ($manifest['manifest_hash'] ?? null)) {
            $mismatches[] = $this->mismatch(
                'export_manifest_hash_mismatch',
                'The export report manifest hash does not match the manifest.',
                'evidence-manifest.json',
                $expectedHash,
                $manifest['manifest_hash'] ?? null,
            );
        }

        return $mismatches;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, array<string, mixed>>
     */
    private function copiedFileMismatches(string $exportPath, array $report): array
    {
        return collect($report['copied_files'] ?? [])
            ->flatMap(function (array $file) use ($exportPath): array {
                $target = (string) ($file['target'] ?? '');
                $path = $exportPath.'/'.$target;

                if ($target === '' || str_starts_with($target, '/') || str_contains($target, '..')) {
                    return [
                        $this->mismatch('artifact_target_invalid', 'A referenced artifact target path is invalid.', $target),
                    ];
                }

                if ($target === '' || ! $this->files->exists($path)) {
                    return [
                        $this->mismatch('artifact_missing', 'A referenced artifact is missing.', $target),
                    ];
                }

                $mismatches = [];
                $actualBytes = filesize($path);
                $actualHash = hash_file('sha256', $path);

                if (($file['bytes'] ?? null) !== $actualBytes) {
                    $mismatches[] = $this->mismatch(
                        'artifact_size_mismatch',
                        'A referenced artifact byte count does not match.',
                        $target,
                        $file['bytes'] ?? null,
                        $actualBytes,
                    );
                }

                if (($file['sha256'] ?? null) !== $actualHash) {
                    $mismatches[] = $this->mismatch(
                        'artifact_hash_mismatch',
                        'A referenced artifact hash does not match.',
                        $target,
                        $file['sha256'] ?? null,
                        $actualHash,
                    );
                }

                return $mismatches;
            })
            ->values()
            ->all();
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
