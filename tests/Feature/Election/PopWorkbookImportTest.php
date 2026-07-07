<?php

use App\Election\Preparation\PopMappingProfiles;
use App\Election\Preparation\PopPrecinctRegistry;
use App\Election\Preparation\PopWorkbookImporter;
use App\Election\Support\ElectionStorage;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
});

test('pop workbook import writes deterministic registry artifacts from the 2025 nle workbook', function (): void {
    $path = popWorkbookPath();

    if (! file_exists($path)) {
        $this->markTestSkipped("POP workbook fixture is not available at {$path}.");
    }

    $this->artisan('election:pop-import', ['path' => $path])
        ->expectsOutput('POP workbook imported.')
        ->expectsOutput('Mapping profile: comelec-pop-2025-nle')
        ->expectsOutput('Rows: 93629')
        ->expectsOutput('Unique clustered precincts: 93629')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $manifest = $storage->readJson('registries/pop-2025-nle/manifest.json');

    expect($manifest['row_count'])->toBe(93629)
        ->and($manifest['unique_clustered_precinct_count'])->toBe(93629)
        ->and($manifest['headers'])->toBe([
            'REGION',
            'PROVINCE',
            'CITY_MUNICIPALITY',
            'BARANGAY',
            'CLUSTERED_PRECINCT',
            'PRECINCT_CLUSTER',
            'CLUSTERTOTAL',
            'POLLING_PLACE',
        ])
        ->and($manifest['source_type'])->toBe('xlsx')
        ->and($manifest['source_label'])->toBe('FINAL_Clustered.POP_NLE_2025')
        ->and($manifest['source_headers'])->toBe($manifest['headers'])
        ->and($manifest['mapping_profile'])->toBe(PopMappingProfiles::Default)
        ->and($manifest['canonical_fields'])->toBe([
            'region',
            'province',
            'city_municipality',
            'barangay',
            'clustered_precinct',
            'precinct_cluster',
            'cluster_total',
            'polling_place',
        ])
        ->and($manifest['total_registered_voters'])->toBe(69773653)
        ->and($manifest['source']['copied_path'])->toBeReadableFile()
        ->and($manifest['precincts_path'])->toBeReadableFile()
        ->and($manifest['index_path'])->toBeReadableFile()
        ->and($manifest['location_summary_path'])->toBeReadableFile();

    $firstHash = $manifest['registry_hash'];

    $this->artisan('election:pop-import', ['path' => $path])
        ->assertSuccessful();

    expect($storage->readJson('registries/pop-2025-nle/manifest.json')['registry_hash'])->toBe($firstHash);
});

test('pop registry lookup and package activation use clustered precinct records', function (): void {
    $path = popWorkbookPath();

    if (! file_exists($path)) {
        $this->markTestSkipped("POP workbook fixture is not available at {$path}.");
    }

    app(PopWorkbookImporter::class)->import($path);

    $this->artisan('election:pop-lookup', ['clustered_precinct' => '7010001'])
        ->expectsOutput('Clustered precinct 7010001')
        ->expectsOutput('Region: BARMM')
        ->expectsOutput('Province: BASILAN')
        ->expectsOutput('City/Municipality: CITY OF ISABELA')
        ->expectsOutput('Barangay: ISABELA PROPER')
        ->expectsOutput('Polling place: ISABELA PROPER BARANGAY HALL')
        ->assertSuccessful();

    $record = app(PopPrecinctRegistry::class)->find('7010001');

    expect($record['region'])->toBe('BARMM')
        ->and($record['province'])->toBe('BASILAN')
        ->and($record['city_municipality'])->toBe('CITY OF ISABELA')
        ->and($record['barangay'])->toBe('ISABELA PROPER')
        ->and($record['precinct_cluster'])->toBe('0001A, 0002A, 0003A')
        ->and($record['cluster_total'])->toBe(521)
        ->and($record['polling_place'])->toBe('ISABELA PROPER BARANGAY HALL');

    $this->artisan('election:pop-activate', ['clustered_precinct' => '7010001'])
        ->expectsOutput('Imported POP precinct package 7010001 written.')
        ->assertSuccessful();

    $package = app(ElectionStorage::class)->readJson('packages/imported/7010001.json');

    expect($package['precinct_id'])->toBe('7010001')
        ->and($package['election_id'])->toBe('2025NLE-POP')
        ->and($package['ballot_style_id'])->toBe('unassigned')
        ->and($package['location']['polling_place'])->toBe('ISABELA PROPER BARANGAY HALL')
        ->and($package['source']['row_hash'])->toBe($record['row_hash']);
});

test('pop workbook import rejects invalid headers and journals the failure', function (): void {
    $path = makePopWorkbook(['WRONG', 'HEADER'], []);

    $this->artisan('election:pop-import', ['path' => $path])
        ->expectsOutputToContain('POP workbook headers do not match')
        ->assertFailed();
});

test('pop workbook import maps renamed and reordered headers with an explicit profile', function (): void {
    $path = makePopWorkbook([
        'POLLING_PLACE_NAME',
        'REGISTERED_VOTERS',
        'PRECINCTS_INCLUDED',
        'CLUSTERED_PRECINCT_ID',
        'BARANGAY_NAME',
        'CITY_OR_MUNICIPALITY',
        'PROVINCE_NAME',
        'REGION_NAME',
    ], [[
        'ALT POLLING PLACE',
        '321',
        '0001A, 0002A',
        '9990001',
        'ALT BARANGAY',
        'ALT CITY',
        'ALT PROVINCE',
        'ALT REGION',
    ]]);

    $this->artisan('election:pop-import', [
        'path' => $path,
        '--profile' => PopMappingProfiles::RenamedReorderedDemo,
    ])
        ->expectsOutput('Mapping profile: comelec-pop-renamed-reordered-demo')
        ->expectsOutput('Rows: 1')
        ->assertSuccessful();

    $record = app(PopPrecinctRegistry::class)->find('9990001');
    $manifest = app(ElectionStorage::class)->readJson('registries/pop-2025-nle/manifest.json');

    expect($record['region'])->toBe('ALT REGION')
        ->and($record['province'])->toBe('ALT PROVINCE')
        ->and($record['city_municipality'])->toBe('ALT CITY')
        ->and($record['barangay'])->toBe('ALT BARANGAY')
        ->and($record['clustered_precinct'])->toBe('9990001')
        ->and($record['precinct_cluster'])->toBe('0001A, 0002A')
        ->and($record['cluster_total'])->toBe(321)
        ->and($record['polling_place'])->toBe('ALT POLLING PLACE')
        ->and($manifest['mapping_profile'])->toBe(PopMappingProfiles::RenamedReorderedDemo);
});

test('pop workbook import maps alternate strict 2025 nle headers with an explicit profile', function (): void {
    $headers = alternatePopHeaders();
    $path = makePopWorkbook($headers, [[
        'ALT REGION',
        'ALT PROVINCE',
        'ALT CITY',
        'ALT BARANGAY',
        '9990002',
        '0003A, 0004A',
        '654',
        'ALT POLLING PLACE',
    ]]);

    $this->artisan('election:pop-import', [
        'path' => $path,
        '--profile' => PopMappingProfiles::Alternate2025Nle,
    ])
        ->expectsOutput('Mapping profile: comelec-pop-2025-nle-alt')
        ->expectsOutput('Rows: 1')
        ->assertSuccessful();

    $record = app(PopPrecinctRegistry::class)->find('9990002');
    $manifest = app(ElectionStorage::class)->readJson('registries/pop-2025-nle/manifest.json');

    expect($record['region'])->toBe('ALT REGION')
        ->and($record['province'])->toBe('ALT PROVINCE')
        ->and($record['city_municipality'])->toBe('ALT CITY')
        ->and($record['barangay'])->toBe('ALT BARANGAY')
        ->and($record['clustered_precinct'])->toBe('9990002')
        ->and($record['precinct_cluster'])->toBe('0003A, 0004A')
        ->and($record['cluster_total'])->toBe(654)
        ->and($record['polling_place'])->toBe('ALT POLLING PLACE')
        ->and($manifest['mapping_profile'])->toBe(PopMappingProfiles::Alternate2025Nle)
        ->and($manifest['source_headers'])->toBe($headers)
        ->and($manifest['canonical_fields'])->toBe([
            'region',
            'province',
            'city_municipality',
            'barangay',
            'clustered_precinct',
            'precinct_cluster',
            'cluster_total',
            'polling_place',
        ]);
});

test('pop workbook import rejects reordered alternate headers under the strict alternate profile', function (): void {
    $path = makePopWorkbook([
        'POLLING_PLACE_NAME',
        'REGISTERED_VOTERS',
        'PRECINCTS_INCLUDED',
        'CLUSTERED_PRECINCT_ID',
        'BARANGAY_NAME',
        'CITY_OR_MUNICIPALITY',
        'PROVINCE_NAME',
        'REGION_NAME',
    ], []);

    $this->artisan('election:pop-import', [
        'path' => $path,
        '--profile' => PopMappingProfiles::Alternate2025Nle,
    ])
        ->expectsOutputToContain('POP workbook headers do not match')
        ->assertFailed();
});

test('pop workbook import rejects renamed headers under the default profile', function (): void {
    $path = makePopWorkbook([
        'POLLING_PLACE_NAME',
        'REGISTERED_VOTERS',
        'PRECINCTS_INCLUDED',
        'CLUSTERED_PRECINCT_ID',
        'BARANGAY_NAME',
        'CITY_OR_MUNICIPALITY',
        'PROVINCE_NAME',
        'REGION_NAME',
    ], []);

    $this->artisan('election:pop-import', ['path' => $path])
        ->expectsOutputToContain('POP workbook headers do not match')
        ->assertFailed();
});

test('pop workbook import rejects profiles with missing mapped source fields', function (): void {
    $path = makePopWorkbook([
        'POLLING_PLACE_NAME',
        'PRECINCTS_INCLUDED',
        'CLUSTERED_PRECINCT_ID',
        'BARANGAY_NAME',
        'CITY_OR_MUNICIPALITY',
        'PROVINCE_NAME',
        'REGION_NAME',
    ], []);

    $this->artisan('election:pop-import', [
        'path' => $path,
        '--profile' => PopMappingProfiles::RenamedReorderedDemo,
    ])
        ->expectsOutputToContain('Missing required POP source header [REGISTERED_VOTERS]')
        ->assertFailed();
});

test('pop workbook import rejects duplicate source headers', function (): void {
    $path = makePopWorkbook([
        'REGION',
        'REGION',
        'CITY_MUNICIPALITY',
        'BARANGAY',
        'CLUSTERED_PRECINCT',
        'PRECINCT_CLUSTER',
        'CLUSTERTOTAL',
        'POLLING_PLACE',
    ], []);

    $this->artisan('election:pop-import', ['path' => $path])
        ->expectsOutputToContain('POP source headers contain duplicates')
        ->assertFailed();
});

test('pop workbook import rejects duplicate clustered precinct ids', function (): void {
    $path = makePopWorkbook([
        'REGION',
        'PROVINCE',
        'CITY_MUNICIPALITY',
        'BARANGAY',
        'CLUSTERED_PRECINCT',
        'PRECINCT_CLUSTER',
        'CLUSTERTOTAL',
        'POLLING_PLACE',
    ], [
        ['REGION A', 'PROVINCE A', 'CITY A', 'BARANGAY A', '1001', '0001A', '10', 'PLACE A'],
        ['REGION B', 'PROVINCE B', 'CITY B', 'BARANGAY B', '1001', '0002A', '11', 'PLACE B'],
    ]);

    $this->artisan('election:pop-import', ['path' => $path])
        ->expectsOutputToContain('Duplicate clustered precinct [1001]')
        ->assertFailed();
});

test('pop import demo scenario imports workbook and writes a package skeleton', function (): void {
    $path = popWorkbookPath();

    if (! file_exists($path)) {
        $this->markTestSkipped("POP workbook fixture is not available at {$path}.");
    }

    $this->artisan('election:scenario pop-import-demo')
        ->expectsOutput('Scenario pop-import-demo passed.')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $report = app(ElectionStorage::class)->readJson('scenarios/pop-import-demo-report.json');

    expect($report['passed'])->toBeTrue()
        ->and($report['row_count'])->toBe(93629)
        ->and($report['unique_clustered_precinct_count'])->toBe(93629)
        ->and($report['precinct_id'])->toBe('7010001')
        ->and($report['manifest_path'])->toBeReadableFile()
        ->and($report['package_path'])->toBeReadableFile();
});

function popWorkbookPath(): string
{
    return '/Users/rli/Documents/COMELEC/POP/2025NLE_POP.xlsx';
}

/**
 * @return array<int, string>
 */
function alternatePopHeaders(): array
{
    return [
        'REGION_NAME',
        'PROVINCE_NAME',
        'CITY_OR_MUNICIPALITY',
        'BARANGAY_NAME',
        'CLUSTERED_PRECINCT_ID',
        'PRECINCTS_INCLUDED',
        'REGISTERED_VOTERS',
        'POLLING_PLACE_NAME',
    ];
}

/**
 * @param  array<int, string>  $headers
 * @param  array<int, array<int, string>>  $rows
 */
function makePopWorkbook(array $headers, array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'pop-workbook-').'.xlsx';
    @unlink($path);

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="FINAL_Clustered.POP_NLE_2025" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>
XML);
    $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML);
    $sheetRows = [makePopWorkbookRow(1, $headers)];

    foreach ($rows as $index => $row) {
        $sheetRows[] = makePopWorkbookRow($index + 2, $row);
    }

    $sheetData = implode(PHP_EOL, $sheetRows);

    $zip->addFromString('xl/worksheets/sheet1.xml', <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>
{$sheetData}
    </sheetData>
</worksheet>
XML);
    $zip->close();

    return $path;
}

/**
 * @param  array<int, string>  $values
 */
function makePopWorkbookRow(int $rowNumber, array $values): string
{
    $cells = array_map(
        fn (int $index, string $value): string => sprintf(
            '<c r="%s%d" t="str"><v>%s</v></c>',
            popWorkbookColumnName($index + 1),
            $rowNumber,
            htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8'),
        ),
        array_keys($values),
        $values,
    );

    return sprintf('        <row r="%d">%s</row>', $rowNumber, implode('', $cells));
}

function popWorkbookColumnName(int $column): string
{
    $name = '';

    while ($column > 0) {
        $column--;
        $name = chr(65 + ($column % 26)).$name;
        $column = intdiv($column, 26);
    }

    return $name;
}
