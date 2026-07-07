<?php

namespace App\Election\Preparation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class PopWorkbookImporter
{
    public const RegistryVersion = 'pop-2025-nle';

    private const ImporterVersion = 'pop-workbook-importer-1';

    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly Filesystem $files,
        private readonly CanonicalJson $json,
        private readonly ElectionClock $clock,
        private readonly ActivityJournal $journal,
        private readonly XlsxPopSourceAdapter $xlsxAdapter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(string $sourcePath, ?string $profileName = null): array
    {
        try {
            $profile = PopMappingProfiles::get($profileName);

            if (! $this->xlsxAdapter->supports($sourcePath)) {
                throw new RuntimeException("Unsupported POP source file type for [{$sourcePath}].");
            }

            $source = $this->xlsxAdapter->extract($sourcePath, $profile->sourceLabel);
            $profile->validateHeaders($source->headers);

            $copiedSourcePath = $this->storage->path('imports/pop/'.basename($sourcePath));
            $this->files->ensureDirectoryExists(dirname($copiedSourcePath));
            $this->files->copy($sourcePath, $copiedSourcePath);

            $registryRoot = $this->registryRoot();
            $this->files->ensureDirectoryExists($registryRoot);

            $precinctsPath = $registryRoot.'/precincts.jsonl';
            $handle = fopen($precinctsPath, 'wb');

            if ($handle === false) {
                throw new RuntimeException('Unable to open POP precinct registry for writing.');
            }

            $index = [];
            $summary = [];
            $registryHash = hash_init('sha256');
            $rowCount = 0;
            $totalVoters = 0;

            foreach ($source->rows as $rowNumber => $row) {
                $record = $profile->map($source->headers, $row, $rowNumber + 2, $this->json);

                if (isset($index[$record['clustered_precinct']])) {
                    fclose($handle);

                    throw new RuntimeException("Duplicate clustered precinct [{$record['clustered_precinct']}] in POP source.");
                }

                $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
                $offset = ftell($handle);

                if ($offset === false) {
                    fclose($handle);

                    throw new RuntimeException('Unable to determine POP registry write offset.');
                }

                fwrite($handle, $line);
                hash_update($registryHash, $line);

                $index[$record['clustered_precinct']] = [
                    'offset' => $offset,
                    'bytes' => strlen($line),
                    'row_hash' => $record['row_hash'],
                ];

                $locationKey = implode('|', [
                    $record['region'],
                    $record['province'],
                    $record['city_municipality'],
                ]);
                $summary[$locationKey] ??= [
                    'region' => $record['region'],
                    'province' => $record['province'],
                    'city_municipality' => $record['city_municipality'],
                    'precincts' => 0,
                    'registered_voters' => 0,
                ];
                $summary[$locationKey]['precincts']++;
                $summary[$locationKey]['registered_voters'] += $record['cluster_total'];
                $rowCount++;
                $totalVoters += $record['cluster_total'];
            }

            fclose($handle);
            ksort($index);
            ksort($summary);

            $this->files->put($registryRoot.'/clustered-precinct-index.json', $this->json->encode($index));
            $this->files->put($registryRoot.'/location-summary.json', $this->json->encode([
                'schema_version' => 'pop-location-summary-1',
                'locations' => array_values($summary),
            ]));

            $manifest = [
                'schema_version' => 'pop-registry-manifest-1',
                'imported_at' => $this->clock->now()->toIso8601String(),
                'importer_version' => self::ImporterVersion,
                'registry_version' => self::RegistryVersion,
                'sheet_name' => $source->sourceLabel,
                'headers' => $source->headers,
                'source_type' => $source->sourceType,
                'source_label' => $source->sourceLabel,
                'source_headers' => $source->headers,
                'mapping_profile' => $profile->name,
                'canonical_fields' => PopMappingProfile::canonicalFields(),
                'source' => [
                    'original_path' => $sourcePath,
                    'copied_path' => $copiedSourcePath,
                    'filename' => $source->filename,
                    'bytes' => $source->bytes,
                    'sha256' => $source->sha256,
                ],
                'row_count' => $rowCount,
                'unique_clustered_precinct_count' => count($index),
                'total_registered_voters' => $totalVoters,
                'registry_hash' => hash_final($registryHash),
                'precincts_path' => $precinctsPath,
                'index_path' => $registryRoot.'/clustered-precinct-index.json',
                'location_summary_path' => $registryRoot.'/location-summary.json',
            ];
            $manifest['manifest_hash'] = $this->json->hash($manifest);
            $manifest['artifact_path'] = $this->storage->writeJson('registries/'.self::RegistryVersion.'/manifest.json', $manifest);

            $this->journal->record('pop.imported', [
                'registry_version' => self::RegistryVersion,
                'row_count' => $rowCount,
                'registry_hash' => $manifest['registry_hash'],
            ]);

            return $manifest;
        } catch (\Throwable $exception) {
            $this->journal->record('pop.import_failed', [
                'source_path' => $sourcePath,
                'reason' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function registryRoot(): string
    {
        return $this->storage->path('registries/'.self::RegistryVersion);
    }
}
