<?php

use App\Election\Core\CanonicalJson;
use App\Election\Diagnostics\CloudEvidenceMirror;
use App\Election\Lifecycle\ElectionRunType;
use App\Election\Support\ElectionOperationLock;
use App\Election\Support\ElectionRunScopeLock;
use App\Election\Support\ElectionStorage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

test('a run is mirrored to private object storage with a verified manifest', function (): void {
    Storage::fake('election_evidence');
    config()->set('election.cloud_evidence.enabled', true);
    config()->set('election.cloud_evidence.disk', 'election_evidence');
    config()->set('election.cloud_evidence.prefix', 'review-evidence');
    config()->set('election.voting_lock_driver', 'local');

    $storage = app(ElectionStorage::class);
    $storage->selectRunType(ElectionRunType::AutomatedTest);
    $storage->reset(ElectionRunType::AutomatedTest);
    $run = $storage->startRun(
        'cloud-evidence-test',
        '39010001',
        '20260508-080000',
        ElectionRunType::AutomatedTest,
        'automated-test',
    );
    $storage->writeJson('runtime/lifecycle.json', ['stage' => 'provision']);
    file_put_contents($run['run_path'].'/cloud-evidence-manifest.json', 'source evidence');
    $storage->lockActiveRun();
    $storage->finalizeRun('cloud-evidence-test', ['passed' => true]);

    $report = app(CloudEvidenceMirror::class)->mirror($run['run_path']);

    expect($report)
        ->passed->toBeTrue()
        ->run_id->toBe($run['run_id'])
        ->artifact_count->toBeGreaterThan(0)
        ->manifest_hash->toBeString();

    Storage::disk('election_evidence')
        ->assertExists($report['manifest_path'])
        ->assertExists($report['remote_root'].'/00-start-here/lifecycle.json')
        ->assertExists($report['remote_root'].'/cloud-evidence-manifest.json');
    expect(Storage::disk('election_evidence')->get($report['remote_root'].'/cloud-evidence-manifest.json'))->toBe('source evidence')
        ->and($report['manifest_path'])->not->toBe($report['remote_root'].'/cloud-evidence-manifest.json');

    $manifest = json_decode(
        Storage::disk('election_evidence')->get($report['manifest_path']),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['artifact_count'])->toBe($report['artifact_count'])
        ->and($manifest['manifest_hash'])->toBe($report['manifest_hash']);
});

test('re-mirroring preserves earlier snapshots and rejects later unindexed changes', function (): void {
    Storage::fake('election_evidence');
    config()->set('election.cloud_evidence.enabled', true);
    config()->set('election.cloud_evidence.disk', 'election_evidence');
    config()->set('election.cloud_evidence.prefix', 'review-evidence');
    config()->set('election.voting_lock_driver', 'local');

    $storage = app(ElectionStorage::class);
    $storage->selectRunType(ElectionRunType::AutomatedTest);
    $storage->reset(ElectionRunType::AutomatedTest);
    $run = $storage->startRun(
        'cloud-evidence-repeat-test',
        '39010001',
        '20260508-080500',
        ElectionRunType::AutomatedTest,
        'automated-test',
    );
    $obsoleteLocalPath = $run['run_path'].'/obsolete-evidence.json';
    app(Filesystem::class)->put($obsoleteLocalPath, '{}');
    $storage->lockActiveRun();
    $storage->finalizeRun('cloud-evidence-repeat-test', ['passed' => true]);

    $mirror = app(CloudEvidenceMirror::class);
    $firstReport = $mirror->mirror($run['run_path']);
    $obsoleteRemotePath = $firstReport['remote_root'].'/obsolete-evidence.json';

    Storage::disk('election_evidence')->assertExists($obsoleteRemotePath);

    $secondReport = $mirror->mirror($run['run_path']);

    Storage::disk('election_evidence')
        ->assertExists($obsoleteRemotePath);
    expect($secondReport['remote_root'])->not->toBe($firstReport['remote_root']);

    expect(Storage::disk('election_evidence')->allFiles($secondReport['remote_root']))
        ->toHaveCount($secondReport['artifact_count']);

    app(Filesystem::class)->delete($obsoleteLocalPath);
    expect(fn () => $mirror->mirror($run['run_path']))->toThrow(RuntimeException::class, 'does not match its index');
    Storage::disk('election_evidence')->assertExists($obsoleteRemotePath)->assertExists($secondReport['manifest_path']);
});

test('the mirror command reports the remote evidence manifest', function (): void {
    Storage::fake('election_evidence');
    config()->set('election.cloud_evidence.enabled', true);
    config()->set('election.voting_lock_driver', 'local');

    $storage = app(ElectionStorage::class);
    $storage->selectRunType(ElectionRunType::AutomatedTest);
    $storage->reset(ElectionRunType::AutomatedTest);
    $run = $storage->startRun(
        'cloud-evidence-command-test',
        '39010001',
        '20260508-081000',
        ElectionRunType::AutomatedTest,
        'automated-test',
    );
    $storage->lockActiveRun();
    $storage->finalizeRun('cloud-evidence-command-test', ['passed' => true]);

    $this->artisan('election:evidence-mirror', ['run' => $run['run_path']])
        ->expectsOutputToContain('Mirrored election run')
        ->expectsOutputToContain('Manifest:')
        ->assertSuccessful();
});

test('mirroring refuses unfinished, invalid, or linked evidence without uploading', function (string $problem): void {
    Storage::fake('election_evidence');
    config()->set('election.cloud_evidence.enabled', true);
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $storage->selectRunType(ElectionRunType::AutomatedTest);
    $storage->reset(ElectionRunType::AutomatedTest);
    $run = $storage->startRun('mirror-rejection', '39010001', '20260508-081500', ElectionRunType::AutomatedTest, 'automated-test');
    if ($problem !== 'open') {
        $storage->lockActiveRun();
        $storage->finalizeRun('mirror-rejection', ['passed' => true]);
    }
    if ($problem === 'unindexed') {
        file_put_contents($run['run_path'].'/unexpected.json', '{}');
    } elseif ($problem === 'index') {
        file_put_contents($run['run_path'].'/artifact-index.json', '{');
    } elseif ($problem === 'symlink') {
        symlink('run-context.json', $run['run_path'].'/linked.json');
    } elseif (in_array($problem, ['traversal', 'duplicate', 'total'], true)) {
        $indexPath = $run['run_path'].'/artifact-index.json';
        $index = json_decode(file_get_contents($indexPath), true, flags: JSON_THROW_ON_ERROR);
        if ($problem === 'traversal') {
            $index['artifacts'][0]['relative_path'] = '../outside';
        } elseif ($problem === 'duplicate') {
            $index['artifacts'][] = $index['artifacts'][0];
            $index['artifact_count']++;
        } else {
            $index['total_bytes']++;
        }
        unset($index['index_hash']);
        $index['index_hash'] = app(CanonicalJson::class)->hash($index);
        file_put_contents($indexPath, app(CanonicalJson::class)->encode($index));
    }

    expect(fn () => app(CloudEvidenceMirror::class)->mirror($run['run_path']))->toThrow(RuntimeException::class);
    expect(Storage::disk('election_evidence')->allFiles())->toBeEmpty();
})->with(['open', 'unindexed', 'index', 'symlink', 'traversal', 'duplicate', 'total']);

test('mirroring without an active run does not create one', function (): void {
    Storage::fake('election_evidence');
    config()->set('election.cloud_evidence.enabled', true);
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $storage->reset();

    expect(fn () => app(CloudEvidenceMirror::class)->mirror())
        ->toThrow(RuntimeException::class, 'no existing run');
    expect($storage->currentRun())->toBe([]);
    expect(Storage::disk('election_evidence')->allFiles())->toBeEmpty();
});

test('mirroring rejects the nonlocal profile and election-day runs', function (): void {
    Storage::fake('election_evidence');
    config()->set('election.cloud_evidence.enabled', true);
    config()->set('election.voting_lock_driver', 'cache');
    expect(fn () => app(CloudEvidenceMirror::class)->mirror())
        ->toThrow(RuntimeException::class, 'requires the single-host local lock profile');

    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $storage->reset();
    $storage->selectRunType(ElectionRunType::ElectionDay);
    $run = $storage->startRun('mirror-election-day', '39010001', '20260508-081900', ElectionRunType::ElectionDay);
    $storage->lockActiveRun();
    $storage->finalizeRun('mirror-election-day', ['passed' => true]);
    expect(fn () => app(CloudEvidenceMirror::class)->mirror($run['run_path']))
        ->toThrow(RuntimeException::class, 'Only a locked demo or automated-test run');
    expect(Storage::disk('election_evidence')->allFiles())->toBeEmpty();
});

test('a failed upload preserves prior snapshots and does not hold the run lock', function (): void {
    Storage::fake('election_evidence');
    config()->set('election.cloud_evidence.enabled', true);
    config()->set('election.cloud_evidence.disk', 'election_evidence');
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $storage->selectRunType(ElectionRunType::AutomatedTest);
    $storage->reset(ElectionRunType::AutomatedTest);
    $run = $storage->startRun('mirror-upload-failure', '39010001', '20260508-082000', ElectionRunType::AutomatedTest, 'automated-test');
    $storage->lockActiveRun();
    $storage->finalizeRun('mirror-upload-failure', ['passed' => true]);
    $mirror = app(CloudEvidenceMirror::class);
    $previous = $mirror->mirror($run['run_path']);
    $disk = Storage::disk('election_evidence');
    $previousFiles = $disk->allFiles();
    $failingDisk = Mockery::mock($disk)->makePartial();
    $lockAvailable = false;
    $uploadCalls = 0;
    $lockDirectory = storage_path('app/private/election-operation-locks');
    if (! is_dir($lockDirectory)) {
        mkdir($lockDirectory, 0770, true);
    }
    $lockPath = $lockDirectory.'/run-scope-'.hash('sha256', $storage->root()).'.lock';
    $failingDisk->shouldReceive('put')->twice()->andReturnUsing(function (string $path, mixed $contents) use ($disk, $lockPath, &$lockAvailable, &$uploadCalls): bool {
        $uploadCalls++;
        if ($uploadCalls > 1) {
            return false;
        }
        $handle = fopen($lockPath, 'c');
        $lockAvailable = flock($handle, LOCK_EX | LOCK_NB);
        if ($lockAvailable) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return $disk->put($path, $contents);
    });
    Storage::set('election_evidence', $failingDisk);

    try {
        expect(fn () => $mirror->mirror($run['run_path']))->toThrow(RuntimeException::class, 'Unable to upload');
    } finally {
        Storage::set('election_evidence', $disk);
    }

    expect($lockAvailable)->toBeTrue();
    $disk->assertExists($previous['manifest_path']);
    $files = $disk->allFiles();
    expect(count($files))->toBeGreaterThan(count($previousFiles))
        ->and(collect($files)->filter(fn (string $path): bool => str_ends_with($path, '/cloud-evidence-manifest.json'))->count())->toBe(1);
});

test('a snapshot does not start while another process holds the run lock', function (): void {
    Storage::fake('election_evidence');
    config()->set('election.cloud_evidence.enabled', true);
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $storage->selectRunType(ElectionRunType::AutomatedTest);
    $storage->reset(ElectionRunType::AutomatedTest);
    $run = $storage->startRun('mirror-lock', '39010001', '20260508-082500', ElectionRunType::AutomatedTest, 'automated-test');
    $storage->lockActiveRun();
    $storage->finalizeRun('mirror-lock', ['passed' => true]);
    $lockDirectory = storage_path('app/private/election-operation-locks');
    if (! is_dir($lockDirectory)) {
        mkdir($lockDirectory, 0770, true);
    }
    $lockPath = $lockDirectory.'/run-scope-'.hash('sha256', $storage->root()).'.lock';
    $child = new Process([PHP_BINARY, '-r', '$h=fopen($argv[1], "c"); flock($h, LOCK_EX); echo "ready\\n"; flush(); sleep(30);', $lockPath]);
    $child->start();
    $child->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ready'));

    try {
        expect(fn () => app(CloudEvidenceMirror::class)->mirror($run['run_path']))->toThrow(LockTimeoutException::class);
        expect(Storage::disk('election_evidence')->allFiles())->toBeEmpty();
    } finally {
        $child->stop();
    }
});

test('a snapshot cleanup failure is reported for operator attention', function (): void {
    Storage::fake('election_evidence');
    config()->set('election.cloud_evidence.enabled', true);
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $storage->selectRunType(ElectionRunType::AutomatedTest);
    $storage->reset(ElectionRunType::AutomatedTest);
    $run = $storage->startRun('mirror-cleanup', '39010001', '20260508-083000', ElectionRunType::AutomatedTest, 'automated-test');
    $storage->lockActiveRun();
    $storage->finalizeRun('mirror-cleanup', ['passed' => true]);
    $root = storage_path('app/private/election-mirror-snapshots');
    $before = glob($root.'/*') ?: [];
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('deleteDirectory')->once()->andReturnFalse();
    $mirror = new CloudEvidenceMirror($storage, $files, app(FilesystemManager::class), app(CanonicalJson::class), app(ElectionRunScopeLock::class));

    try {
        expect(fn () => $mirror->mirror($run['run_path']))->toThrow(RuntimeException::class, 'Operator cleanup is required');
    } finally {
        foreach (array_diff(glob($root.'/*') ?: [], $before) as $path) {
            app(Filesystem::class)->deleteDirectory($path);
        }
    }
});

test('concurrent publishers create distinct verified versions', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $storage->selectRunType(ElectionRunType::AutomatedTest);
    $storage->reset(ElectionRunType::AutomatedTest);
    $run = $storage->startRun('mirror-concurrent', '39010001', '20260508-083500', ElectionRunType::AutomatedTest, 'automated-test');
    $storage->lockActiveRun();
    $storage->finalizeRun('mirror-concurrent', ['passed' => true]);
    $testRoot = storage_path('app/private/mirror-test-'.bin2hex(random_bytes(12)));
    mkdir($testRoot, 0700, true);
    $barrier = $testRoot.'/start';
    $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config()->set([
            'election.storage.directory' => 'election-testing',
            'election.voting_lock_driver' => 'local',
            'election.cloud_evidence.enabled' => true,
            'election.cloud_evidence.disk' => 'concurrent_test',
            'filesystems.disks.concurrent_test' => ['driver' => 'local', 'root' => $argv[2], 'throw' => true],
        ]);
        file_put_contents($argv[4], 'ready');
        do { clearstatcache(true, $argv[3]); if (is_file($argv[3])) { break; } usleep(10000); } while (true);
        $result = app(App\Election\Diagnostics\CloudEvidenceMirror::class)->mirror($argv[1]);
        echo json_encode(['root' => $result['remote_root'], 'manifest' => $result['manifest_path']], JSON_THROW_ON_ERROR);
        PHP;
    $environment = ['APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key')];
    $firstReady = $testRoot.'/first-ready';
    $secondReady = $testRoot.'/second-ready';
    $first = new Process([PHP_BINARY, '-r', $code, $run['run_path'], $testRoot.'/remote', $barrier, $firstReady], base_path(), $environment);
    $second = new Process([PHP_BINARY, '-r', $code, $run['run_path'], $testRoot.'/remote', $barrier, $secondReady], base_path(), $environment);
    $first->setTimeout(15);
    $second->setTimeout(15);

    try {
        $first->start();
        $second->start();
        $deadline = microtime(true) + 10;
        do {
            clearstatcache();
            if (is_file($firstReady) && is_file($secondReady)) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline && $first->isRunning() && $second->isRunning());
        expect(is_file($firstReady) && is_file($secondReady))->toBeTrue();
        file_put_contents($barrier, 'start');
        $first->wait();
        $second->wait();
        expect($first->isSuccessful())->toBeTrue()
            ->and($second->isSuccessful())->toBeTrue();
        $firstResult = json_decode(trim($first->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $secondResult = json_decode(trim($second->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        expect($firstResult['root'])->not->toBe($secondResult['root'])
            ->and($testRoot.'/remote/'.$firstResult['manifest'])->toBeFile()
            ->and($testRoot.'/remote/'.$secondResult['manifest'])->toBeFile();
    } finally {
        $first->stop();
        $second->stop();
        app(Filesystem::class)->deleteDirectory($testRoot);
    }
});

test('election operation locks execute through the configured shared cache', function (): void {
    config()->set('cache.default', 'array');

    $result = app(ElectionOperationLock::class)->execute(
        'run:39010001:issue-ballot:000001',
        fn (): string => 'completed',
    );

    expect($result)->toBe('completed');
});
