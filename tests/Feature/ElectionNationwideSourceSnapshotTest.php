<?php

use App\Election\Bundle\NationwideSourceSnapshotValidator;

test('frozen source snapshot detects altered PDFs and POP inputs', function (): void {
    $root = sys_get_temp_dir().'/national-source-test-'.bin2hex(random_bytes(6));
    mkdir($root.'/raw/NCR', 0700, true);
    $pdf = $root.'/raw/NCR/MANILA.pdf';
    copy(resource_path('election/clc/MANILA.pdf'), $pdf);
    $pop = $root.'/pop.xlsx';
    file_put_contents($pop, 'frozen-pop');
    $url = 'https://www.comelec.gov.ph/php-tpls-attachments/2025NLE/COC_2025NLE/CLC2025_NCR/MANILA.pdf';
    $catalogPath = $root.'/catalog.json';
    file_put_contents($catalogPath, json_encode([
        'schema_version' => 'comelec-2025-clc-source-catalog-1',
        'pdf_count' => 1,
        'sources' => [['url' => $url, 'region' => 'NCR']],
    ], JSON_THROW_ON_ERROR));
    $manifestPath = $root.'/downloads.json';
    file_put_contents($manifestPath, json_encode([
        'schema_version' => 'comelec-2025-clc-download-manifest-1',
        'source_catalog_sha256' => hash_file('sha256', $catalogPath),
        'source_count' => 1,
        'downloaded_count' => 1,
        'failure_count' => 0,
        'failures' => [],
        'files' => [['url' => $url, 'region' => 'NCR', 'bytes' => filesize($pdf), 'sha256' => hash_file('sha256', $pdf)]],
    ], JSON_THROW_ON_ERROR));

    $validator = app(NationwideSourceSnapshotValidator::class);
    $expectedPopHash = hash_file('sha256', $pop);
    $valid = $validator->validate($catalogPath, $manifestPath, $root.'/raw', $pop, $expectedPopHash);

    expect($valid['valid'])->toBeTrue()
        ->and($valid['verified_source_count'])->toBe(1);

    $wrongCatalog = $validator->validate($catalogPath, $manifestPath, $root.'/raw', $pop, $expectedPopHash, str_repeat('0', 64));

    expect(array_column($wrongCatalog['issues'], 'code'))->toContain('frozen_catalog_hash_mismatch');

    file_put_contents($pdf, 'altered');
    file_put_contents($pop, 'altered');
    $invalid = $validator->validate($catalogPath, $manifestPath, $root.'/raw', $pop, $expectedPopHash);

    expect($invalid['valid'])->toBeFalse()
        ->and(array_column($invalid['issues'], 'code'))->toContain('download_integrity_failed', 'pop_hash_mismatch');
});
