<?php

use App\Election\Bundle\DistrictReconciliationService;
use App\Election\Scenarios\BrowserWalkthroughControl;
use App\Election\Support\ElectionStorage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    config()->set('election.storage.directory', 'election-testing/district-'.bin2hex(random_bytes(6)));
});

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

test('district reconciliation preserves existing output entries', function (string $name, string $kind): void {
    $output = sys_get_temp_dir().'/aes-district-existing-'.bin2hex(random_bytes(6));
    mkdir($output);
    $path = $output.'/'.$name;
    if ($kind === 'file') {
        file_put_contents($path, 'existing evidence');
    } elseif ($kind === 'symlink') {
        symlink($output.'/missing-target', $path);
    } else {
        mkdir($path);
    }

    expect(fn () => app(DistrictReconciliationService::class)->reconcile([], 'hash', resource_path('election/pop/2025-district-evidence.json'), $output))
        ->toThrow(RuntimeException::class, 'must not contain reconciliation outputs');
    expect($kind === 'file' ? file_get_contents($path) === 'existing evidence' : ($kind === 'symlink' ? is_link($path) : is_dir($path)))->toBeTrue()
        ->and(file_exists($output.'/missing-target'))->toBeFalse();
})->with([
    ['district-crosswalk.csv', 'file'],
    ['district-reconciliation.json', 'file'],
    ['correction-ledger.json', 'file'],
    ['district-crosswalk.csv', 'symlink'],
    ['correction-ledger.json', 'directory'],
]);

test('district reconciliation rejects protected storage and symlink aliases', function (): void {
    $storage = app(ElectionStorage::class);
    $storage->ensureDirectories();
    $target = $storage->root().'/runs';
    $alias = sys_get_temp_dir().'/aes-district-alias-'.bin2hex(random_bytes(6));
    symlink($target, $alias);

    try {
        foreach ([$target, $alias] as $output) {
            expect(fn () => app(DistrictReconciliationService::class)->reconcile([], 'hash', resource_path('election/pop/2025-district-evidence.json'), $output))
                ->toThrow(RuntimeException::class, 'cannot be written inside election storage');
        }
        expect($target.'/district-crosswalk.csv')->not->toBeFile();
    } finally {
        unlink($alias);
    }
});

test('a competing reconciliation cannot replace an in-progress output set', function (): void {
    config()->set('election.voting_lock_driver', 'cache');
    $output = sys_get_temp_dir().'/aes-district-competing-'.bin2hex(random_bytes(6));
    mkdir($output);
    $service = app(DistrictReconciliationService::class);
    $evidence = resource_path('election/pop/2025-district-evidence.json');
    $precincts = (function () use ($service, $evidence, $output): Generator {
        expect(fn () => $service->reconcile([], 'other', $evidence, $output))
            ->toThrow(RuntimeException::class, 'must not contain reconciliation outputs');
        yield ['clustered_precinct' => '24020196', 'province' => 'DAVAO DEL SUR', 'city_municipality' => 'CITY OF DAVAO', 'barangay' => 'BUCANA'];
    })();

    $report = $service->reconcile($precincts, 'first', $evidence, $output);
    expect($report['precinct_count'])->toBe(1)
        ->and(json_decode((string) file_get_contents($output.'/district-reconciliation.json'), true)['pop_sha256'])->toBe('first');
});

test('failed reconciliation does not remove a replacement owned by another writer', function (): void {
    $output = sys_get_temp_dir().'/aes-district-replaced-'.bin2hex(random_bytes(6));
    mkdir($output);
    $precincts = (function () use ($output): Generator {
        rename($output.'/district-reconciliation.json', $output.'/original-report');
        file_put_contents($output.'/district-reconciliation.json', 'other writer');
        throw new RuntimeException('simulated failure');
        yield [];
    })();

    expect(fn () => app(DistrictReconciliationService::class)->reconcile($precincts, 'hash', resource_path('election/pop/2025-district-evidence.json'), $output))
        ->toThrow(RuntimeException::class, 'simulated failure');
    expect(file_get_contents($output.'/district-reconciliation.json'))->toBe('other writer')
        ->and(file_exists($output.'/district-crosswalk.csv'))->toBeFalse()
        ->and(file_exists($output.'/correction-ledger.json'))->toBeFalse();
});

test('district reconciliation refuses an active walkthrough before creating outputs', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $output = sys_get_temp_dir().'/aes-district-reserved-'.bin2hex(random_bytes(6));
    mkdir($output);
    $walkthrough = app(BrowserWalkthroughControl::class)->begin('full-election', '39010001');

    expect(fn () => app(DistrictReconciliationService::class)->reconcile([], 'hash', resource_path('election/pop/2025-district-evidence.json'), $output))
        ->toThrow(RuntimeException::class, 'active browser walkthrough reserves');
    expect(scandir($output))->toBe(['.', '..'])
        ->and(app(BrowserWalkthroughControl::class)->read()['run_id'])->toBe($walkthrough['run_id']);
});

test('district reconciliation waits for a competing run lock before creating outputs', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $output = sys_get_temp_dir().'/aes-district-lock-'.bin2hex(random_bytes(6));
    mkdir($output);
    $lockDirectory = storage_path('app/private/election-operation-locks');
    if (! is_dir($lockDirectory)) {
        mkdir($lockDirectory, 0770, true);
    }
    $lockPath = $lockDirectory.'/run-scope-'.hash('sha256', app(ElectionStorage::class)->root()).'.lock';
    $holder = new Process([PHP_BINARY, '-r', '$h=fopen($argv[1], "c"); flock($h, LOCK_EX); echo "ready\\n"; flush(); sleep(30);', $lockPath]);
    $holder->start();
    $holder->waitUntil(fn (string $type, string $text): bool => str_contains($text, 'ready'));

    try {
        expect(fn () => app(DistrictReconciliationService::class)->reconcile([], 'hash', resource_path('election/pop/2025-district-evidence.json'), $output))
            ->toThrow(LockTimeoutException::class);
        expect(scandir($output))->toBe(['.', '..']);
    } finally {
        $holder->stop();
    }
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
