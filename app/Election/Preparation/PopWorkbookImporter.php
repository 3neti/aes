<?php

namespace App\Election\Preparation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use XMLReader;
use ZipArchive;

final class PopWorkbookImporter
{
    public const RegistryVersion = 'pop-2025-nle';

    private const SheetName = 'FINAL_Clustered.POP_NLE_2025';

    private const ImporterVersion = 'pop-workbook-importer-1';

    private const ExpectedHeaders = [
        'REGION',
        'PROVINCE',
        'CITY_MUNICIPALITY',
        'BARANGAY',
        'CLUSTERED_PRECINCT',
        'PRECINCT_CLUSTER',
        'CLUSTERTOTAL',
        'POLLING_PLACE',
    ];

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
    public function import(string $sourcePath): array
    {
        try {
            if (! $this->files->exists($sourcePath)) {
                throw new RuntimeException("POP workbook not found at [{$sourcePath}].");
            }

            $zip = $this->openWorkbook($sourcePath);
            $sheetPath = $this->sheetPath($zip);
            $sharedStrings = $this->sharedStrings($zip);
            $rows = $this->rows($zip, $sheetPath, $sharedStrings);
            $headers = array_shift($rows);

            if ($headers !== self::ExpectedHeaders) {
                throw new RuntimeException('POP workbook headers do not match the expected 2025 NLE POP format.');
            }

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

            foreach ($rows as $rowNumber => $row) {
                $record = $this->record($row, $rowNumber + 2);
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
                'sheet_name' => self::SheetName,
                'headers' => self::ExpectedHeaders,
                'source' => [
                    'original_path' => $sourcePath,
                    'copied_path' => $copiedSourcePath,
                    'filename' => basename($sourcePath),
                    'bytes' => filesize($sourcePath),
                    'sha256' => hash_file('sha256', $sourcePath),
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

    private function openWorkbook(string $sourcePath): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($sourcePath) !== true) {
            throw new RuntimeException("Unable to open POP workbook [{$sourcePath}].");
        }

        return $zip;
    }

    private function sheetPath(ZipArchive $zip): string
    {
        $workbook = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $relationships = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));

        if ($workbook === false || $relationships === false) {
            throw new RuntimeException('POP workbook is missing workbook metadata.');
        }

        $workbook->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheet = collect($workbook->xpath('//main:sheet') ?: [])
            ->first(fn (\SimpleXMLElement $sheet): bool => (string) $sheet['name'] === self::SheetName);

        if (! $sheet instanceof \SimpleXMLElement) {
            throw new RuntimeException('POP workbook sheet [FINAL_Clustered.POP_NLE_2025] was not found.');
        }

        $relationshipId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $relationships->registerXPathNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $relationship = collect($relationships->xpath('//rel:Relationship') ?: [])
            ->first(fn (\SimpleXMLElement $relationship): bool => (string) $relationship['Id'] === $relationshipId);

        if (! $relationship instanceof \SimpleXMLElement) {
            throw new RuntimeException('POP workbook sheet relationship was not found.');
        }

        return 'xl/'.ltrim((string) $relationship['Target'], '/');
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $reader = new XMLReader;
        $reader->XML($xml);
        $strings = [];

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') {
                continue;
            }

            $node = simplexml_load_string($reader->readOuterXML());
            $text = '';

            foreach ($node?->xpath('.//*[local-name()="t"]') ?: [] as $part) {
                $text .= (string) $part;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array<int, array<int, string>>
     */
    private function rows(ZipArchive $zip, string $sheetPath, array $sharedStrings): array
    {
        $xml = $zip->getFromName($sheetPath);

        if ($xml === false) {
            throw new RuntimeException("POP workbook sheet XML [{$sheetPath}] was not found.");
        }

        $reader = new XMLReader;
        $reader->XML($xml);
        $rows = [];

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }

            $row = [];
            $node = simplexml_load_string($reader->readOuterXML());

            foreach ($node?->xpath('.//*[local-name()="c"]') ?: [] as $cell) {
                $reference = (string) $cell['r'];
                $column = $this->columnIndex($reference);
                $type = (string) $cell['t'];
                $value = (string) (($cell->xpath('./*[local-name()="v"]')[0] ?? null) ?: '');

                if ($type === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                }

                $row[$column] = $value;
            }

            $rows[] = array_map(fn (int $column): string => $row[$column] ?? '', range(1, 8));
        }

        return $rows;
    }

    private function columnIndex(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference)) ?: '';
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + ord($letter) - 64;
        }

        return $index;
    }

    /**
     * @param  array<int, string>  $row
     * @return array<string, mixed>
     */
    private function record(array $row, int $sourceRow): array
    {
        $record = [
            'schema_version' => 'pop-precinct-row-1',
            'region' => trim($row[0] ?? ''),
            'province' => trim($row[1] ?? ''),
            'city_municipality' => trim($row[2] ?? ''),
            'barangay' => trim($row[3] ?? ''),
            'clustered_precinct' => trim($row[4] ?? ''),
            'precinct_cluster' => trim($row[5] ?? ''),
            'cluster_total' => (int) trim($row[6] ?? '0'),
            'polling_place' => trim($row[7] ?? ''),
            'source_row' => $sourceRow,
        ];

        if ($record['clustered_precinct'] === '') {
            throw new RuntimeException("POP workbook row [{$sourceRow}] is missing CLUSTERED_PRECINCT.");
        }

        $record['row_hash'] = $this->json->hash($record);

        return $record;
    }
}
