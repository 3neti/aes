<?php

namespace App\Election\Preparation;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use XMLReader;
use ZipArchive;

final readonly class XlsxPopSourceAdapter implements PopSourceAdapter
{
    public function __construct(private Filesystem $files) {}

    public function supports(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xlsx';
    }

    public function extract(string $path, string $sourceLabel): PopSourceData
    {
        if (! $this->files->exists($path)) {
            throw new RuntimeException("POP workbook not found at [{$path}].");
        }

        $zip = $this->openWorkbook($path);
        $sheetPath = $this->sheetPath($zip, $sourceLabel);
        $sharedStrings = $this->sharedStrings($zip);
        $rows = $this->rows($zip, $sheetPath, $sharedStrings);
        $headers = array_shift($rows) ?? [];

        $zip->close();

        return new PopSourceData(
            sourceType: 'xlsx',
            sourceLabel: $sourceLabel,
            headers: $headers,
            rows: $rows,
            originalPath: $path,
            filename: basename($path),
            bytes: (int) filesize($path),
            sha256: (string) hash_file('sha256', $path),
        );
    }

    private function openWorkbook(string $sourcePath): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($sourcePath) !== true) {
            throw new RuntimeException("Unable to open POP workbook [{$sourcePath}].");
        }

        return $zip;
    }

    private function sheetPath(ZipArchive $zip, string $sourceLabel): string
    {
        $workbook = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $relationships = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));

        if ($workbook === false || $relationships === false) {
            throw new RuntimeException('POP workbook is missing workbook metadata.');
        }

        $workbook->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheet = collect($workbook->xpath('//main:sheet') ?: [])
            ->first(fn (\SimpleXMLElement $sheet): bool => (string) $sheet['name'] === $sourceLabel);

        if (! $sheet instanceof \SimpleXMLElement) {
            throw new RuntimeException("POP workbook sheet [{$sourceLabel}] was not found.");
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
        $columnCount = 0;

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
                $columnCount = max($columnCount, $column);
            }

            $rows[] = array_map(fn (int $column): string => $row[$column] ?? '', range(1, max($columnCount, 1)));
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
}
