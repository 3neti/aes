<?php

namespace App\Election\Diagnostics;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;

final class RemovableMediaExporter
{
    public function __construct(
        private readonly DiagnosticsService $diagnostics,
        private readonly ElectionStorage $storage,
        private readonly Filesystem $files,
        private readonly CanonicalJson $json,
        private readonly ElectionClock $clock,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $manifest = $this->diagnostics->writeEvidenceManifest();
        $exportedAt = $this->clock->now();
        $exportId = 'evidence-export-'.$exportedAt->format('Ymd-His');
        $targetRoot = $this->targetRoot().'/'.$exportId;

        $this->files->ensureDirectoryExists($targetRoot.'/artifacts');
        $this->files->copy($manifest['artifact_path'], $targetRoot.'/evidence-manifest.json');

        $copiedFiles = $this->copyManifestArtifacts($manifest, $targetRoot);

        $report = [
            'schema_version' => 'removable-media-export-report-1',
            'export_id' => $exportId,
            'exported_at' => $exportedAt->toIso8601String(),
            'target_path' => $targetRoot,
            'manifest_hash' => $manifest['manifest_hash'],
            'manifest_artifact' => 'evidence-manifest.json',
            'artifact_count' => count($copiedFiles),
            'copied_files' => $copiedFiles,
        ];
        $report['export_hash'] = $this->json->hash($report);

        $reportPath = $targetRoot.'/export-report.json';
        $this->files->put($reportPath, $this->json->encode($report));

        $this->journal->record('evidence_bundle.exported', [
            'export_id' => $exportId,
            'target_path' => $targetRoot,
            'manifest_hash' => $manifest['manifest_hash'],
            'export_hash' => $report['export_hash'],
            'artifact_count' => $report['artifact_count'],
        ]);

        return [
            ...$report,
            'artifact_path' => $reportPath,
        ];
    }

    private function targetRoot(): string
    {
        $configuredPath = trim((string) config('election.removable_media.path', ''));

        if ($configuredPath !== '') {
            return rtrim($configuredPath, '/');
        }

        return $this->storage->path('removable-media');
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, array<string, mixed>>
     */
    private function copyManifestArtifacts(array $manifest, string $targetRoot): array
    {
        return collect($manifest['categories'] ?? [])
            ->flatMap(fn (array $category): array => $category['files'] ?? [])
            ->map(function (array $file) use ($targetRoot): array {
                $relativePath = (string) $file['relative_path'];
                $sourcePath = $this->storage->path($relativePath);
                $targetPath = $targetRoot.'/artifacts/'.$relativePath;

                $this->files->ensureDirectoryExists(dirname($targetPath));
                $this->files->copy($sourcePath, $targetPath);

                return [
                    'source' => $relativePath,
                    'target' => 'artifacts/'.$relativePath,
                    'bytes' => $file['bytes'],
                    'sha256' => $file['sha256'],
                ];
            })
            ->values()
            ->all();
    }
}
