<?php

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
    $path = makeInvalidPopWorkbook();

    $this->artisan('election:pop-import', ['path' => $path])
        ->expectsOutputToContain('POP workbook headers do not match')
        ->assertFailed();
});

function popWorkbookPath(): string
{
    return '/Users/rli/Documents/COMELEC/POP/2025NLE_POP.xlsx';
}

function makeInvalidPopWorkbook(): string
{
    $path = sys_get_temp_dir().'/invalid-pop-workbook.xlsx';
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
    $zip->addFromString('xl/worksheets/sheet1.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>
        <row r="1">
            <c r="A1" t="str"><v>WRONG</v></c>
            <c r="B1" t="str"><v>HEADER</v></c>
        </row>
    </sheetData>
</worksheet>
XML);
    $zip->close();

    return $path;
}
