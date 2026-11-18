<?php

use App\Election\Bundle\DistrictReconciliationService;

test('district reconciliation writes source linked assignments and leaves unmatched precincts unresolved', function (): void {
    $output = sys_get_temp_dir().'/aes-district-test-'.bin2hex(random_bytes(6));
    mkdir($output);
    $precincts = [
        ['clustered_precinct' => '76040001', 'province' => 'NCR - 4TH DISTRICT', 'city_municipality' => 'CITY OF PARAÑAQUE', 'barangay' => 'BACLARAN'],
        ['clustered_precinct' => '76040002', 'province' => 'NCR - 4TH DISTRICT', 'city_municipality' => 'CITY OF PARAÑAQUE', 'barangay' => 'B. F. HOMES'],
        ['clustered_precinct' => '76030001', 'province' => 'NCR - 4TH DISTRICT', 'city_municipality' => 'CITY OF MUNTINLUPA', 'barangay' => 'POBLACION'],
        ['clustered_precinct' => '24020196', 'province' => 'DAVAO DEL SUR', 'city_municipality' => 'CITY OF DAVAO', 'barangay' => 'BUCANA'],
        ['clustered_precinct' => '99999999', 'province' => 'TEST', 'city_municipality' => 'TEST', 'barangay' => 'TEST'],
    ];
    $report = app(DistrictReconciliationService::class)->reconcile(
        $precincts,
        'test-pop-hash',
        resource_path('election/pop/2025-district-evidence.json'),
        $output,
    );
    $rows = array_map('str_getcsv', file($output.'/district-crosswalk.csv', FILE_IGNORE_NEW_LINES));
    $ledger = json_decode((string) file_get_contents($output.'/correction-ledger.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($report['precinct_count'])->toBe(5)
        ->and($report['source_supported_pending_review_count'])->toBe(4)
        ->and($report['unresolved_precinct_count'])->toBe(1)
        ->and($report['national_coverage_ready'])->toBeFalse()
        ->and($rows[1][9])->toBe('1')
        ->and($rows[2][9])->toBe('2')
        ->and($rows[3][9])->toBe('LONE')
        ->and($rows[4][11])->toBe('comelec-davao-city-first-ballot-face-24020196')
        ->and($rows[5][10])->toBe('unresolved')
        ->and($ledger['items'][0]['applied'])->toBeFalse();
});

test('district reconciliation rejects evidence that disagrees with the POP', function (): void {
    $output = sys_get_temp_dir().'/aes-district-conflict-'.bin2hex(random_bytes(6));
    mkdir($output);
    $precincts = [
        ['clustered_precinct' => '24020196', 'province' => 'DAVAO DEL SUR', 'city_municipality' => 'CITY OF DAVAO', 'barangay' => 'WRONG'],
    ];

    expect(fn () => app(DistrictReconciliationService::class)->reconcile(
        $precincts,
        'test-pop-hash',
        resource_path('election/pop/2025-district-evidence.json'),
        $output,
    ))->toThrow(RuntimeException::class, 'Direct evidence location does not match POP precinct');

    expect(file_exists($output.'/district-crosswalk.csv'))->toBeFalse();
});

test('the frozen nationwide POP produces an explicit research coverage report', function (): void {
    $output = sys_get_temp_dir().'/aes-district-national-'.bin2hex(random_bytes(6));
    mkdir($output);

    $this->artisan('election:bundle:reconcile-districts', ['output-directory' => $output])
        ->assertSuccessful();

    $report = json_decode((string) file_get_contents($output.'/district-reconciliation.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($report['precinct_count'])->toBe(93629)
        ->and($report['source_supported_pending_review_count'])->toBe(2891)
        ->and($report['unresolved_precinct_count'])->toBe(90738)
        ->and($report['unreviewed_correction_count'])->toBe(4)
        ->and($report['national_coverage_ready'])->toBeFalse();
});
