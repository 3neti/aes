<?php

use App\Election\Core\CanonicalJson;
use App\Election\Scenarios\BrowserWalkthroughControl;
use App\Election\Support\ElectionOperationLock;
use App\Election\Support\ElectionRunScopeLock;
use App\Election\Support\ElectionStorage;
use App\Exceptions\ElectionOperationBusy;
use App\Models\ScannerScanEvent;
use App\Models\SimulationRound;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;

test('run scope lock serializes different demo scopes of the same base root and survives holder termination', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $base = trim((string) config('election.storage.directory'), '/');
    $directory = storage_path('app/private/election-operation-locks');
    if (! is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
    $path = $directory.'/run-scope-'.hash('sha256', storage_path('app/'.$base)).'.lock';
    $child = new Process([PHP_BINARY, '-r', '$h=fopen($argv[1], "c"); flock($h, LOCK_EX); echo "ready\\n"; flush(); sleep(30);', $path]);
    $child->start();
    $child->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ready'));
    $lock = app(ElectionRunScopeLock::class);
    $ran = false;

    try {
        config()->set('election.storage.directory', $base.'/public-simulations/ROUND-A/PRECINCT-A');
        expect(fn () => $lock->execute(function () use (&$ran): void {
            $ran = true;
        }, 0))->toThrow(LockTimeoutException::class);
        expect($ran)->toBeFalse();
        config()->set('election.storage.directory', $base.'-other/public-simulations/ROUND-B/PRECINCT-B');
        expect($lock->execute(fn (): string => 'different root', 0))->toBe('different root');
    } finally {
        $child->stop();
    }

    config()->set('election.storage.directory', $base.'/public-simulations/ROUND-B/PRECINCT-B');
    expect($lock->execute(fn (): string => 'released', 0))->toBe('released');
});

test('run scope lock supports nested scopes and releases on exceptions', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $base = trim((string) config('election.storage.directory'), '/');
    $lock = app(ElectionRunScopeLock::class);

    expect(fn () => $lock->execute(function () use ($lock, $base): void {
        config()->set('election.storage.directory', $base.'/public-simulations/ROUND-A/PRECINCT-A');
        $lock->execute(fn () => throw new RuntimeException('operation failed'), 0);
    }, 0))->toThrow(RuntimeException::class, 'operation failed');
    expect($lock->execute(fn (): string => 'released', 0))->toBe('released');
});

test('run scope lock refuses a different backend and invalid roots', function (): void {
    $lock = app(ElectionRunScopeLock::class);
    config()->set('election.voting_lock_driver', 'cache');
    expect(fn () => $lock->execute(fn () => null, 0))->toThrow(RuntimeException::class, 'single-host local');
    config()->set('election.voting_lock_driver', 'local');
    config()->set('election.storage.directory', 'election/../other');
    expect(fn () => $lock->execute(fn () => null, 0))->toThrow(RuntimeException::class, 'Invalid election storage');
});

test('another fiber cannot bypass a held run scope lock', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $lock = app(ElectionRunScopeLock::class);

    $lock->execute(function () use ($lock): void {
        $fiber = new Fiber(fn () => $lock->execute(fn () => 'unexpected', 0));
        expect(fn () => $fiber->start())->toThrow(LockTimeoutException::class);
    }, 0);
});

test('a caught nested failure does not release its outer run scope lock', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $lock = app(ElectionRunScopeLock::class);

    $lock->execute(function () use ($lock): void {
        try {
            $lock->execute(fn () => throw new RuntimeException('nested failure'), 0);
        } catch (RuntimeException) {
            $fiber = new Fiber(fn () => $lock->execute(fn () => 'unexpected', 0));
            expect(fn () => $fiber->start())->toThrow(LockTimeoutException::class);
        }
    }, 0);
    expect($lock->execute(fn (): string => 'released', 0))->toBe('released');
});

test('a guarded command restores its election scope after success and failure', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $lock = app(ElectionRunScopeLock::class);
    $original = [
        'election.storage.directory' => config('election.storage.directory'),
        'election.runtime.run_type' => config('election.runtime.run_type'),
        'election.pop.clustered_precinct' => config('election.pop.clustered_precinct'),
        'election.pop.district' => config('election.pop.district'),
        'election.officers' => config('election.officers'),
    ];
    $changeScope = function () use ($original): void {
        config()->set([
            'election.storage.directory' => $original['election.storage.directory'].'/public-simulations/ROUND-A/PRECINCT-A',
            'election.runtime.run_type' => 'rehearsal',
            'election.pop.clustered_precinct' => 'another-precinct',
            'election.pop.district' => 'another-district',
            'election.officers' => [],
        ]);
    };

    $lock->executeCommand($changeScope, 0);
    foreach ($original as $key => $value) {
        expect(config($key))->toBe($value);
    }
    expect(fn () => $lock->executeCommand(function () use ($changeScope): void {
        $changeScope();
        throw new RuntimeException('command failed');
    }, 0))->toThrow(RuntimeException::class, 'command failed');
    foreach ($original as $key => $value) {
        expect(config($key))->toBe($value);
    }
    expect($lock->executeCommand(fn (): string => 'next command', 0))->toBe('next command');
});

test('election HTTP requests acquire the base run lock before invoking the route', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $root = app(ElectionStorage::class)->root();
    $directory = storage_path('app/private/election-operation-locks');
    if (! is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
    $path = $directory.'/run-scope-'.hash('sha256', $root).'.lock';
    $child = new Process([PHP_BINARY, '-r', '$h=fopen($argv[1], "c"); flock($h, LOCK_EX); echo "ready\\n"; flush(); sleep(30);', $path]);
    $child->start();
    $child->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ready'));
    $ran = false;
    Route::get('/election/test-run-scope', function () use (&$ran): string {
        $ran = true;

        return 'executed';
    });

    try {
        $this->getJson('/election/test-run-scope')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '5')
            ->assertJsonPath('message', 'The election system is busy. Please wait a moment and try again.');
        expect($ran)->toBeFalse();
    } finally {
        $child->stop();
    }

    $this->get('/election/test-run-scope')->assertSuccessful()->assertSeeText('executed');
    expect($ran)->toBeTrue();
});

test('an inner election HTTP timeout is not reported as failure to acquire the run lock', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    Route::get('/election/test-inner-scope-timeout', fn () => throw new LockTimeoutException);
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/election/test-inner-scope-timeout'))->toThrow(LockTimeoutException::class);
});

test('an election HTTP request retains the base run lock after applying a demo scope', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $base = trim((string) config('election.storage.directory'), '/');
    $lockPath = storage_path('app/private/election-operation-locks/run-scope-'.hash('sha256', app(ElectionStorage::class)->root()).'.lock');
    Route::get('/election/test-scoped-lock', function () use ($base, $lockPath): string {
        config()->set('election.storage.directory', $base.'/public-simulations/ROUND-A/PRECINCT-A');
        $child = new Process([PHP_BINARY, '-r', '$h=fopen($argv[1], "c"); echo flock($h, LOCK_EX | LOCK_NB) ? "unlocked" : "locked";', $lockPath]);
        $child->mustRun();

        return $child->getOutput();
    });

    $this->get('/election/test-scoped-lock')->assertSuccessful()->assertSeeText('locked');
});

test('a scanner command waits for the shared run lock for each scan', function (string $command): void {
    config()->set('election.voting_lock_driver', 'local');
    app(ElectionStorage::class)->reset();
    $directory = storage_path('app/private/election-operation-locks');
    if (! is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
    $path = $directory.'/run-scope-'.hash('sha256', app(ElectionStorage::class)->root()).'.lock';
    $child = new Process([PHP_BINARY, '-r', '$h=fopen($argv[1], "c"); flock($h, LOCK_EX); echo "ready\\n"; flush(); sleep(30);', $path]);
    $child->start();
    $child->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ready'));

    try {
        expect(Artisan::call($command, ['--payload' => ['invalid-scan']]))->toBe(1)
            ->and(Artisan::output())->toContain('Scan was not recorded');
        expect(ScannerScanEvent::query()->count())->toBe(0);
    } finally {
        $child->stop();
    }

    Artisan::call($command, ['--payload' => ['invalid-scan']]);
    expect(ScannerScanEvent::query()->count())->toBe(1);
})->with([
    'precinct' => 'election:precinct-ballot-scanner-ingest',
    'canvassing' => 'election:canvassing-scanner-ingest',
]);

test('run and round commands acquire the shared run lock before starting', function (string $command, array $arguments): void {
    config()->set('election.voting_lock_driver', 'local');
    $directory = storage_path('app/private/election-operation-locks');
    if (! is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
    $path = $directory.'/run-scope-'.hash('sha256', app(ElectionStorage::class)->root()).'.lock';
    $child = new Process([PHP_BINARY, '-r', '$h=fopen($argv[1], "c"); flock($h, LOCK_EX); echo "ready\\n"; flush(); sleep(30);', $path]);
    $child->start();
    $child->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ready'));

    try {
        expect(fn () => Artisan::call($command, $arguments))->toThrow(LockTimeoutException::class);
    } finally {
        $child->stop();
    }
})->with([
    'reset' => ['election:public-simulation:reset', ['round' => 'missing-round']],
    'archive' => ['election:public-simulation:archive', ['round' => 'missing-round']],
    'scenario' => ['election:scenario', ['scenario' => 'full-demo']],
    'field rehearsal' => ['election:public-simulation:field-rehearsal', ['round' => 'missing-round', 'precinct' => 'missing-precinct']],
    'usability simulation' => ['election:public-simulation:facilitated-usability-simulation', []],
    'improvement backlog' => ['election:public-simulation:improvement-backlog', ['round' => 'missing-round', 'precinct' => 'missing-precinct']],
    'usability session kit' => ['election:public-simulation:usability-session-kit', ['round' => 'missing-round', 'precinct' => 'missing-precinct']],
    'observation review' => ['election:public-simulation:observation-review', ['round' => 'missing-round', 'precinct' => 'missing-precinct']],
    'review kit' => ['election:public-simulation:review-kit', ['round' => 'missing-round']],
    'retention review' => ['election:public-simulation:retention-review', ['round' => 'missing-round']],
    'recovery inspection' => ['election:recover', []],
    'precinct package activation' => ['election:pop-activate', ['clustered_precinct' => 'missing-precinct']],
    'candidate import' => ['election:clc-import', ['source' => '/missing-source']],
    'precinct import' => ['election:pop-import', ['path' => '/missing-workbook']],
    'candidate report' => ['election:precinct-candidates', ['clustered_precinct' => 'missing-precinct', '--write-report' => true]],
]);

test('browser walkthrough setup waits for the shared run lock', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $directory = storage_path('app/private/election-operation-locks');
    if (! is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
    $path = $directory.'/run-scope-'.hash('sha256', $storage->root()).'.lock';
    $controlPath = $storage->root().'/browser-walkthrough/control.json';
    $before = is_file($controlPath) ? file_get_contents($controlPath) : null;
    $child = new Process([PHP_BINARY, '-r', '$h=fopen($argv[1], "c"); flock($h, LOCK_EX); echo "ready\\n"; flush(); sleep(30);', $path]);
    $child->start();
    $child->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ready'));

    try {
        expect(Artisan::call('election:browser-walkthrough', [
            'scenario' => 'full-election',
            '--base-url' => 'http://127.0.0.1:8000',
        ]))->toBe(1);
        expect(is_file($controlPath) ? file_get_contents($controlPath) : null)->toBe($before);
    } finally {
        $child->stop();
    }
});

test('direct walkthrough completion waits for the shared run lock', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $storage->reset();
    $control = app(BrowserWalkthroughControl::class);
    $walkthrough = $control->begin('full-election', '39010001');
    $path = storage_path('app/private/election-operation-locks/run-scope-'.hash('sha256', $storage->root()).'.lock');
    $child = new Process([PHP_BINARY, '-r', '$h=fopen($argv[1], "c"); flock($h, LOCK_EX); echo "ready\\n"; flush(); sleep(30);', $path]);
    $child->start();
    $child->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ready'));

    try {
        expect(fn () => $control->complete($walkthrough['token'], 'failed'))->toThrow(LockTimeoutException::class);
        expect($control->read()['status'])->toBe('active');
    } finally {
        $child->stop();
    }

    expect($control->complete($walkthrough['token'], 'failed')['status'])->toBe('failed');
});

test('an active walkthrough reserves the run for its verified callbacks', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $storage->reset();
    $control = app(BrowserWalkthroughControl::class);
    $walkthrough = $control->begin('full-election', '39010001');
    $baseDirectory = (string) config('election.storage.directory');
    $ran = false;
    Route::middleware('web')->get('/election/test-walkthrough-reservation', function () use (&$ran): string {
        $ran = true;

        return 'walkthrough callback';
    });

    try {
        $this->getJson('/election/test-walkthrough-reservation')->assertStatus(503);
        expect($ran)->toBeFalse();
        $this->withHeader(BrowserWalkthroughControl::Header, $walkthrough['token'])
            ->get('/election/test-walkthrough-reservation')->assertSuccessful()->assertSeeText('walkthrough callback');
        expect($ran)->toBeTrue();

        config()->set('election.storage.directory', $baseDirectory.'/public-simulations/ROUND-A/PRECINCT-A');
        expect($control->read()['run_id'])->toBe($walkthrough['run_id']);
        expect(fn () => Artisan::call('election:public-simulation:reset', ['round' => 'missing-round']))
            ->toThrow(RuntimeException::class, 'active browser walkthrough reserves');
        expect(Artisan::call('election:precinct-ballot-scanner-ingest', ['--payload' => ['invalid-scan']]))->toBe(1)
            ->and(Artisan::output())->toContain('Scan was not recorded')
            ->and(ScannerScanEvent::query()->count())->toBe(0);
        $roundsBefore = SimulationRound::query()->count();
        expect(Artisan::call('election:browser-walkthrough', [
            'scenario' => 'public-simulation',
            '--base-url' => 'http://127.0.0.1:8000',
        ]))->toBe(1)
            ->and(SimulationRound::query()->count())->toBe($roundsBefore);
    } finally {
        config()->set('election.storage.directory', $baseDirectory);
        $control->complete($walkthrough['token'], 'failed');
    }
});

test('an expired walkthrough reservation remains blocked until explicitly resolved', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $storage->reset();
    $control = app(BrowserWalkthroughControl::class);
    $walkthrough = $control->begin('full-election', '39010001');
    $record = $control->read();
    unset($record['control_hash']);
    $record['expires_at'] = '2000-01-01T00:00:00+00:00';
    $record['control_hash'] = app(CanonicalJson::class)->hash($record);
    $path = app(ElectionRunScopeLock::class)->baseRoot().'/browser-walkthrough/control.json';
    file_put_contents($path, app(CanonicalJson::class)->encode($record));
    $before = file_get_contents($path);
    Route::middleware('web')->get('/election/test-expired-walkthrough', fn (): string => 'unexpected');

    try {
        $this->get('/election/test-expired-walkthrough')->assertStatus(503);
        $this->withHeader(BrowserWalkthroughControl::Header, $walkthrough['token'])
            ->get('/election/test-expired-walkthrough')->assertForbidden();
        expect(fn () => app(ElectionRunScopeLock::class)->executeCommand(fn () => null, 0))
            ->toThrow(RuntimeException::class, 'active browser walkthrough reserves');
        expect(file_get_contents($path))->toBe($before);
    } finally {
        $storage->reset();
    }
});

test('an unreadable walkthrough reservation returns an actionable busy response without changing evidence', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $storage->reset();
    app(BrowserWalkthroughControl::class)->begin('full-election', '39010001');
    $path = app(ElectionRunScopeLock::class)->baseRoot().'/browser-walkthrough/control.json';
    file_put_contents($path, '{');
    $ran = false;
    Route::get('/election/test-corrupt-reservation', function () use (&$ran): string {
        $ran = true;

        return 'unexpected';
    });

    try {
        $this->get('/election/test-corrupt-reservation')->assertStatus(503)
            ->assertSeeText('The browser walkthrough reservation cannot be verified.');
        expect($ran)->toBeFalse()
            ->and(file_get_contents($path))->toBe('{');
    } finally {
        $storage->reset();
    }
});

test('a legacy scoped walkthrough reservation blocks new work without deleting evidence', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $storage->reset();
    $legacyDirectory = $storage->root().'/public-simulations/ROUND-A/PRECINCT-A/browser-walkthrough';
    mkdir($legacyDirectory, 0700, true);
    $legacyPath = $legacyDirectory.'/control.json';
    file_put_contents($legacyPath, 'legacy evidence');
    $ran = false;
    Route::get('/election/test-legacy-reservation', function () use (&$ran): string {
        $ran = true;

        return 'unexpected';
    });

    try {
        $this->get('/election/test-legacy-reservation')->assertStatus(503);
        expect(fn () => app(ElectionRunScopeLock::class)->executeCommand(fn () => null, 0))
            ->toThrow(RuntimeException::class, 'legacy scoped walkthrough reservation');
        expect($ran)->toBeFalse()
            ->and(file_get_contents($legacyPath))->toBe('legacy evidence');
    } finally {
        $storage->reset();
    }
});

test('a walkthrough callback and competing command coordinate across processes', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $storage->reset();
    $control = app(BrowserWalkthroughControl::class);
    $walkthrough = $control->begin('full-election', '39010001');
    $testDirectory = storage_path('app/private/walkthrough-lock-test-'.bin2hex(random_bytes(12)));
    mkdir($testDirectory, 0700, true);
    $ready = $testDirectory.'/ready';
    $release = $testDirectory.'/release';
    $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $kernel->bootstrap();
        Illuminate\Support\Facades\Route::middleware('web')->get('/election/test-process-callback', function () {
            file_put_contents(getenv('WAES_TEST_READY'), 'ready');
            $deadline = microtime(true) + 15;
            do {
                clearstatcache(true, getenv('WAES_TEST_RELEASE'));
                if (is_file(getenv('WAES_TEST_RELEASE'))) { return 'callback-complete'; }
                usleep(10000);
            } while (microtime(true) < $deadline);
            return response('callback-timeout', 500);
        });
        $request = Illuminate\Http\Request::create('/election/test-process-callback', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $request->headers->set(App\Election\Scenarios\BrowserWalkthroughControl::Header, getenv('WAES_TEST_TOKEN'));
        $response = $kernel->handle($request);
        echo $response->getStatusCode().':'.$response->getContent();
        $kernel->terminate($request, $response);
        PHP;
    $environment = [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'ELECTION_STORAGE_DIRECTORY' => 'election-testing',
        'ELECTION_VOTING_LOCK_DRIVER' => 'local',
        'SESSION_DRIVER' => 'array',
        'CACHE_STORE' => 'array',
        'WAES_TEST_READY' => $ready,
        'WAES_TEST_RELEASE' => $release,
        'WAES_TEST_TOKEN' => $walkthrough['token'],
    ];
    $callback = new Process([PHP_BINARY, '-r', $code], base_path(), $environment);
    $callback->setTimeout(20);
    $reset = new Process([PHP_BINARY, 'artisan', 'election:public-simulation:reset', 'missing-round', '--no-interaction'], base_path(), $environment);
    $reset->setTimeout(10);

    try {
        $callback->start();
        $deadline = microtime(true) + 10;
        do {
            clearstatcache(true, $ready);
            if (is_file($ready)) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline && $callback->isRunning());
        expect(is_file($ready))->toBeTrue();
        $reset->run();
        expect($reset->isSuccessful())->toBeFalse();
        file_put_contents($release, 'release');
        $callback->wait();
        expect($callback->isSuccessful())->toBeTrue()
            ->and($callback->getOutput())->toBe('200:callback-complete');
        $after = new Process([PHP_BINARY, 'artisan', 'election:public-simulation:reset', 'missing-round', '--no-interaction'], base_path(), $environment);
        $after->run();
        expect($after->isSuccessful())->toBeFalse()
            ->and($after->getOutput().$after->getErrorOutput())->toContain('active browser walkthrough reserves');
        expect($control->read()['run_id'])->toBe($walkthrough['run_id']);
    } finally {
        $callback->stop();
        $reset->stop();
        $control->complete($walkthrough['token'], 'failed');
        app(Filesystem::class)->deleteDirectory($testDirectory);
    }
});

test('local voting lock excludes another process and is released on termination', function (): void {
    $directory = storage_path('app/private/election-operation-locks');
    if (! is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
    $path = $directory.'/'.hash('sha256', app(ElectionStorage::class)->root().'|public-simulation-voting').'.lock';
    $child = new Process([PHP_BINARY, '-r', '$h=fopen($argv[1], "c"); flock($h, LOCK_EX); echo "ready\\n"; flush(); sleep(30);', $path]);
    $child->start();
    $child->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ready'));
    $ran = false;

    try {
        try {
            app(ElectionOperationLock::class)->executeLocalVoting(function () use (&$ran): void {
                $ran = true;
            }, 0);
            $this->fail('A held lock must time out.');
        } catch (LockTimeoutException) {
            expect($ran)->toBeFalse();
        }
    } finally {
        $child->stop();
    }

    expect(app(ElectionOperationLock::class)->executeLocalVoting(fn (): string => 'released', 0))->toBe('released');
});

test('nested election locks use the process held lock in the local profile', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $directory = storage_path('app/private/election-operation-locks');
    if (! is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
    $path = $directory.'/'.hash('sha256', app(ElectionStorage::class)->root().'|sealed-ballot-box').'.lock';
    $handle = fopen($path, 'c');
    expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();

    try {
        expect(fn () => app(ElectionOperationLock::class)->execute('sealed-ballot-box', fn () => null, leaseSeconds: 1, waitSeconds: 0))
            ->toThrow(LockTimeoutException::class);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    expect(app(ElectionOperationLock::class)->execute('sealed-ballot-box', fn (): string => 'released', waitSeconds: 0))->toBe('released');
});

test('a busy voting gate returns a retryable json response', function (): void {
    Route::post('/test-voting-busy', function (): void {
        throw new ElectionOperationBusy('The election system is busy. Please wait a moment and try again.');
    });

    $this->postJson('/test-voting-busy')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '5')
        ->assertJsonPath('message', 'The election system is busy. Please wait a moment and try again.');
});

test('a timing log failure cannot change a completed voting operation into a failure', function (): void {
    Log::shouldReceive('log')->twice()->andThrow(new RuntimeException('logging unavailable'));
    $lock = app(ElectionOperationLock::class);

    expect($lock->executeLocalVoting(fn (): string => 'completed', 0))->toBe('completed')
        ->and($lock->executeLocalVoting(fn (): string => 'released', 0))->toBe('released');
});

test('a timing log failure does not replace the operation exception', function (): void {
    Log::shouldReceive('log')->once()->andThrow(new RuntimeException('logging unavailable'));

    expect(fn () => app(ElectionOperationLock::class)->executeLocalVoting(
        fn () => throw new RuntimeException('operation failed'),
        0,
    ))->toThrow(RuntimeException::class, 'operation failed');
});

test('a timeout log failure does not hide the lock timeout', function (): void {
    $directory = storage_path('app/private/election-operation-locks');
    if (! is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
    $path = $directory.'/'.hash('sha256', app(ElectionStorage::class)->root().'|public-simulation-voting').'.lock';
    $handle = fopen($path, 'c');
    expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();
    Log::shouldReceive('log')->once()->andThrow(new RuntimeException('logging unavailable'));

    try {
        expect(fn () => app(ElectionOperationLock::class)->executeLocalVoting(fn () => null, 0))
            ->toThrow(LockTimeoutException::class);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
});

test('a local voting lock remains held beyond the old sixty second lease', function (): void {
    $directory = storage_path('app/private/election-operation-locks');
    if (! is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
    $path = $directory.'/'.hash('sha256', app(ElectionStorage::class)->root().'|public-simulation-voting').'.lock';
    $child = new Process([PHP_BINARY, '-r', '$h=fopen($argv[1], "c"); flock($h, LOCK_EX); echo "ready\\n"; flush(); sleep(75);', $path]);
    $child->start();
    $child->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ready'));

    try {
        sleep(61);
        expect(fn () => app(ElectionOperationLock::class)->executeLocalVoting(fn () => null, 0))
            ->toThrow(LockTimeoutException::class);
    } finally {
        $child->stop();
    }
})->group('slow');
