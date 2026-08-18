<?php

namespace App\Election\Preparation;

use App\Election\Core\CanonicalJson;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use XMLReader;
use ZipArchive;

final readonly class XlsxCandidateWorkbookImporter
{
    public function __construct(
        private Filesystem $files,
        private CanonicalJson $json,
        private BallotPdfPartyReference $partyReference,
    ) {}

    public function supports(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xlsx';
    }

    /**
     * @return array{source_records: array<int, array<string, mixed>>, candidates: array<int, array<string, mixed>>, contests: array<string, array<string, mixed>>}
     */
    public function extract(string $path): array
    {
        if (! $this->files->exists($path)) {
            throw new RuntimeException("Candidate workbook not found at [{$path}].");
        }

        $zip = $this->openWorkbook($path);
        $sheets = $this->sheets($zip);
        $sharedStrings = $this->sharedStrings($zip);
        $configuredSheets = config('election.clc.workbook_sheets', []);
        $activeSheet = (string) config('election.clc.workbook_active_sheet', '');
        $partyReferencePath = (string) config('election.clc.workbook_party_reference_pdf', '');
        $partyMap = $partyReferencePath === '' ? [] : $this->partyReference->parties($partyReferencePath);
        $records = [];
        $contests = [];
        $sourceRecords = [];
        $partyMatchCount = 0;

        foreach ($sheets as $sheetName => $sheetPath) {
            if ($activeSheet !== '' && $sheetName !== $activeSheet) {
                continue;
            }

            if (is_array($configuredSheets) && $configuredSheets !== [] && ! array_key_exists($sheetName, $configuredSheets)) {
                continue;
            }

            $sheetConfig = is_array($configuredSheets) && isset($configuredSheets[$sheetName]) && is_array($configuredSheets[$sheetName])
                ? $configuredSheets[$sheetName]
                : [];
            $rows = $this->rows($zip, $sheetPath, $sharedStrings);
            $headers = array_map(fn (string $header): string => trim($header), array_shift($rows) ?? []);

            if ($headers !== ['Position', 'Name']) {
                throw new RuntimeException("Candidate workbook sheet [{$sheetName}] must have Position and Name headers.");
            }

            $sourceRecords[] = [
                'filename' => basename($path),
                'sheet_name' => $sheetName,
                'original_path' => $path,
                'copied_path' => null,
                'bytes' => filesize($path),
                'sha256' => hash_file('sha256', $path),
                'rows' => count($rows),
            ];

            $ballotNumbers = [];

            foreach ($rows as $rowNumber => $row) {
                $position = $this->normalizeOffice($row[0] ?? '');
                $name = trim((string) ($row[1] ?? ''));

                if ($position === null || $name === '') {
                    continue;
                }

                $ballotNumbers[$position] ??= 0;
                $ballotNumbers[$position]++;

                $geography = $this->geography($position, $sheetConfig);
                $district = $this->district($position, $sheetConfig);
                $scope = $this->scope($position, $district);
                $party = $partyMap[$this->partyReference->key($name)] ?? null;

                if (is_string($party) && $party !== '') {
                    $partyMatchCount++;
                }

                $record = [
                    'schema_version' => 'clc-candidate-1',
                    'election_id' => (string) config('election.clc.workbook_election_id', 'MANILA-FACSIMILE-DEMO'),
                    'source_file' => basename($path),
                    'source_page' => $sheetName,
                    'source_sheet' => $sheetName,
                    'scope' => $scope,
                    'geography' => $geography,
                    'office' => $position,
                    'district' => $district,
                    'ballot_number' => $ballotNumbers[$position],
                    'name_on_ballot' => $name,
                    'sex' => null,
                    'full_name' => $name,
                    'political_party' => $party,
                    'candidate_image' => [
                        'status' => 'placeholder',
                        'type' => null,
                        'uri' => null,
                        'source' => null,
                        'sha256' => null,
                        'alt_text' => "Candidate photo placeholder for {$name}",
                    ],
                ];
                $record['candidate_hash'] = $this->json->hash($record);
                $records[] = $record;

                $contestKey = implode('|', [$scope, $geography, $position, $district ?? '']);
                $contests[$contestKey] ??= [
                    'scope' => $scope,
                    'geography' => $geography,
                    'district' => $district,
                    'office' => $position,
                    'candidate_count' => 0,
                ];
                $contests[$contestKey]['candidate_count']++;
            }
        }

        $zip->close();
        ksort($contests);

        return [
            'source_records' => $sourceRecords,
            'candidates' => $records,
            'contests' => $contests,
            'party_reference' => [
                'path' => $partyReferencePath === '' ? null : $partyReferencePath,
                'matched_candidates' => $partyMatchCount,
                'unmatched_candidates' => max(0, count($records) - $partyMatchCount),
                'reference_party_count' => count($partyMap),
            ],
        ];
    }

    private function openWorkbook(string $sourcePath): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($sourcePath) !== true) {
            throw new RuntimeException("Unable to open candidate workbook [{$sourcePath}].");
        }

        return $zip;
    }

    /**
     * @return array<string, string>
     */
    private function sheets(ZipArchive $zip): array
    {
        $workbook = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $relationships = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));

        if ($workbook === false || $relationships === false) {
            throw new RuntimeException('Candidate workbook is missing workbook metadata.');
        }

        $workbook->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationships->registerXPathNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $relationshipMap = collect($relationships->xpath('//rel:Relationship') ?: [])
            ->mapWithKeys(fn (\SimpleXMLElement $relationship): array => [
                (string) $relationship['Id'] => 'xl/'.ltrim((string) $relationship['Target'], '/'),
            ]);

        return collect($workbook->xpath('//main:sheet') ?: [])
            ->mapWithKeys(function (\SimpleXMLElement $sheet) use ($relationshipMap): array {
                $relationshipId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];

                return [(string) $sheet['name'] => (string) $relationshipMap->get($relationshipId)];
            })
            ->filter()
            ->all();
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
            throw new RuntimeException("Candidate workbook sheet XML [{$sheetPath}] was not found.");
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

                $row[$column] = trim($value);
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

    private function normalizeOffice(string $position): ?string
    {
        return match (strtoupper(trim($position))) {
            'PRESIDENT' => 'PRESIDENT',
            'VICE PRESIDENT', 'VICE-PRESIDENT' => 'VICE PRESIDENT',
            'SENATOR' => 'SENATOR',
            'PARTY LIST', 'PARTY-LIST' => 'PARTY LIST',
            'HOUSE OF REPRESENTATIVES', 'MEMBER, HOUSE OF REPRESENTATIVES' => 'MEMBER, HOUSE OF REPRESENTATIVES',
            'MAYOR' => 'MAYOR',
            'VICE MAYOR', 'VICE-MAYOR' => 'VICE-MAYOR',
            'CITY COUNCIL', 'COUNCILOR', 'MEMBER, SANGGUNIANG PANLUNGSOD' => 'COUNCILOR',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $sheetConfig
     */
    private function geography(string $office, array $sheetConfig): string
    {
        if (in_array($office, ['PRESIDENT', 'VICE PRESIDENT', 'SENATOR', 'PARTY LIST'], true)) {
            return 'PHILIPPINES';
        }

        return (string) ($sheetConfig['geography'] ?? 'NCR - MANILA');
    }

    /**
     * @param  array<string, mixed>  $sheetConfig
     */
    private function district(string $office, array $sheetConfig): ?string
    {
        if (! in_array($office, ['MEMBER, HOUSE OF REPRESENTATIVES', 'COUNCILOR'], true)) {
            return null;
        }

        return (string) ($sheetConfig['district'] ?? 'SECOND DIST');
    }

    private function scope(string $office, ?string $district): string
    {
        if (in_array($office, ['PRESIDENT', 'VICE PRESIDENT', 'SENATOR', 'PARTY LIST'], true)) {
            return 'national';
        }

        return $district === null ? 'municipal' : 'district';
    }
}
