<?php

namespace App\Election\Diagnostics;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;

final class EvidenceBundleArchiveBuilder
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
    public function build(): array
    {
        $manifest = $this->diagnostics->writeEvidenceManifest();
        $builtAt = $this->clock->now();
        $archiveId = 'evidence-bundle-'.$builtAt->format('Ymd-His');
        $archiveRelativePath = 'diagnostics/'.$archiveId.'.tar';
        $archivePath = $this->storage->path($archiveRelativePath);
        $entries = $this->archiveEntries($manifest, $archiveId);

        $this->files->ensureDirectoryExists(dirname($archivePath));
        $this->files->put($archivePath, $this->tar($entries));

        $report = [
            'schema_version' => 'evidence-bundle-archive-report-1',
            'archive_id' => $archiveId,
            'archive_artifact' => basename($archivePath),
            'archive_path' => $archivePath,
            'archive_bytes' => filesize($archivePath),
            'archive_sha256' => hash_file('sha256', $archivePath),
            'built_at' => $builtAt->toIso8601String(),
            'entry_count' => count($entries),
            'manifest_hash' => $manifest['manifest_hash'],
        ];
        $report['archive_report_hash'] = $this->json->hash($report);
        $report['artifact_path'] = $this->storage->writeJson('diagnostics/evidence-bundle-archive.json', $report);

        $this->journal->record('evidence_bundle.archive_built', [
            'archive_id' => $archiveId,
            'archive_sha256' => $report['archive_sha256'],
            'entry_count' => $report['entry_count'],
            'manifest_hash' => $report['manifest_hash'],
        ]);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, array{name: string, contents: string}>
     */
    private function archiveEntries(array $manifest, string $archiveId): array
    {
        $entries = [
            [
                'name' => $archiveId.'/evidence-manifest.json',
                'contents' => $this->files->get($manifest['artifact_path']),
            ],
        ];

        foreach ($manifest['categories'] ?? [] as $category) {
            foreach ($category['files'] ?? [] as $file) {
                $relativePath = (string) $file['relative_path'];
                $sourcePath = $this->storage->runPath($relativePath);

                if (! $this->files->exists($sourcePath)) {
                    continue;
                }

                $entries[] = [
                    'name' => $archiveId.'/artifacts/'.$relativePath,
                    'contents' => $this->files->get($sourcePath),
                ];
            }
        }

        $entries[] = [
            'name' => $archiveId.'/archive-index.json',
            'contents' => $this->json->encode([
                'archive_id' => $archiveId,
                'entry_count' => count($entries) + 1,
                'manifest_hash' => $manifest['manifest_hash'],
            ]),
        ];

        return $entries;
    }

    /**
     * @param  array<int, array{name: string, contents: string}>  $entries
     */
    private function tar(array $entries): string
    {
        $tar = '';

        foreach ($entries as $entry) {
            $tar .= $this->tarHeader($entry['name'], strlen($entry['contents']));
            $tar .= $entry['contents'];
            $tar .= str_repeat("\0", (512 - (strlen($entry['contents']) % 512)) % 512);
        }

        return $tar.str_repeat("\0", 1024);
    }

    private function tarHeader(string $name, int $size): string
    {
        [$nameField, $prefixField] = $this->splitTarName($name);
        $header = str_pad($nameField, 100, "\0");
        $header .= sprintf('%07o', 0644)."\0";
        $header .= sprintf('%07o', 0)."\0";
        $header .= sprintf('%07o', 0)."\0";
        $header .= sprintf('%011o', $size)."\0";
        $header .= sprintf('%011o', 0)."\0";
        $header .= '        ';
        $header .= '0';
        $header .= str_repeat("\0", 100);
        $header .= "ustar\0";
        $header .= '00';
        $header .= str_pad('', 32, "\0");
        $header .= str_pad('', 32, "\0");
        $header .= str_pad('', 8, "\0");
        $header .= str_pad('', 8, "\0");
        $header .= str_pad($prefixField, 155, "\0");
        $header .= str_repeat("\0", 12);

        $checksum = array_sum(array_map('ord', str_split($header)));
        $checksumField = str_pad(sprintf('%06o', $checksum), 6, '0', STR_PAD_LEFT)."\0 ";

        return substr_replace($header, $checksumField, 148, 8);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitTarName(string $name): array
    {
        if (strlen($name) <= 100) {
            return [$name, ''];
        }

        $parts = explode('/', $name);
        $basename = array_pop($parts);
        $prefix = implode('/', $parts);

        if (strlen($basename) > 100 || strlen($prefix) > 155) {
            throw new \RuntimeException("Archive entry path is too long: {$name}");
        }

        return [$basename, $prefix];
    }
}
