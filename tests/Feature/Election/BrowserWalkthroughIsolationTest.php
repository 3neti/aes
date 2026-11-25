<?php

use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\ElectionRunType;
use App\Election\Preparation\ActivateConfiguredPrecinct;
use App\Election\Preparation\ActivateSamplePackage;
use App\Election\PublicSimulation\PublicSimulationScope;
use App\Election\Scenarios\BrowserWalkthroughControl;
use App\Election\Scenarios\BrowserWalkthroughRecovery;
use App\Election\Scenarios\ScenarioRunner;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionRunScopeLock;
use App\Election\Support\ElectionStorage;
use App\Http\Middleware\LockElectionRunScope;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();

    Route::middleware('web')->get('/browser-walkthrough-context-test', function (ElectionStorage $storage): array {
        return [
            'configured_run_type' => config('election.runtime.run_type'),
            'run_id' => $storage->currentRun()['run_id'] ?? null,
        ];
    });
});

test('scoped reservation binds a persisted precinct through finalization preflight', function (string $outcome): void {
    config()->set('election.voting_lock_driver', 'local');
    $precinct = SimulationPrecinct::factory()->create(['district' => config('election.pop.district')]);
    $storage = app(ElectionStorage::class);
    $control = app(BrowserWalkthroughControl::class);
    $original = config('election.storage.directory');
    $scoped = $original.'/public-simulations/'.$precinct->round->code.'/'.$precinct->code;
    $start = function (string $timestamp) use ($storage, $precinct, $original, $scoped): array {
        config()->set('election.storage.directory', $scoped);
        try {
            return $storage->startRun('scoped-demo', $precinct->clustered_precinct, $timestamp, ElectionRunType::Rehearsal);
        } finally {
            config()->set('election.storage.directory', $original);
        }
    };

    expect(fn () => $control->begin('public-simulation', $precinct->clustered_precinct, $precinct))
        ->toThrow(RuntimeException::class, 'pointer is missing');
    expect($storage->currentRun(ElectionRunType::Rehearsal))->toBe([]);
    $run = $start('20260508-080000');
    $reservation = $control->begin('public-simulation', $precinct->clustered_precinct, $precinct);
    $verified = $control->authorize($reservation['token']);
    $preflight = $control->preflightScopedFinalization($reservation['token']);
    expect($preflight['base']['run_id'])->toBe($reservation['run_id'])
        ->and($preflight['scoped']['run_id'])->toBe($run['run_id']);
    expect(fn () => $control->complete($reservation['token'], 'passed'))
        ->toThrow(RuntimeException::class, 'requires finalization of both runs');
    foreach ([$reservation['run_path'], $run['run_path']] as $path) {
        $index = $path.'/artifact-index.json';
        file_put_contents($index, 'partial evidence');
        try {
            expect(fn () => $control->preflightScopedFinalization($reservation['token']))
                ->toThrow(RuntimeException::class, 'finalization evidence already exists');
            expect(file_get_contents($index))->toBe('partial evidence');
        } finally {
            unlink($index);
        }
    }
    expect($control->read()['status'])->toBe('active');
    expect($verified['simulation_scope']['round_id'])->toBe($precinct->round->id)
        ->and($verified['simulation_scope']['precinct_id'])->toBe($precinct->id)
        ->and($verified['simulation_scope']['run_path'])->toBe($run['run_path']);

    $scope = app(PublicSimulationScope::class);
    expect(fn () => $scope->apply($precinct))
        ->toThrow(RuntimeException::class, 'does not authorize this public simulation scope');
    expect(config('election.storage.directory'))->toBe($original);

    $otherPrecinct = SimulationPrecinct::factory()->create(['clustered_precinct' => $precinct->clustered_precinct]);
    $request = request();
    $request->headers->set(BrowserWalkthroughControl::Header, $reservation['token']);
    $request->attributes->set(LockElectionRunScope::VerifiedWalkthrough, $verified['control_hash']);
    $previousConfig = config('election');
    try {
        app(ElectionRunScopeLock::class)->execute(function () use ($scope, $precinct, $otherPrecinct, $scoped, $run): void {
            config()->set('election.runtime.run_type', ElectionRunType::Rehearsal->value);
            $activation = app(ActivateConfiguredPrecinct::class);
            expect(fn () => $activation->handle())
                ->toThrow(RuntimeException::class, 'does not authorize this precinct activation scope');
            expect(fn () => $scope->apply($otherPrecinct))
                ->toThrow(RuntimeException::class, 'does not authorize this public simulation scope');
            $scope->apply($precinct);
            expect(config('election.storage.directory'))->toBe($scoped);
            expect($activation->handle()['activation_report']['run_id'])->toBe($run['run_id'])
                ->and(app(ElectionStorage::class)->currentRun(ElectionRunType::Rehearsal))->toEqual($run);
        });
    } finally {
        config()->set('election', $previousConfig);
        $request->headers->remove(BrowserWalkthroughControl::Header);
        $request->attributes->remove(LockElectionRunScope::VerifiedWalkthrough);
    }

    Route::middleware('web')->get('/election/test-scoped-reservation', fn (): string => 'unexpected callback');
    $this->withHeader(BrowserWalkthroughControl::Header, $reservation['token'])
        ->get('/election/test-scoped-reservation')
        ->assertServiceUnavailable();

    if ($outcome === 'replacement') {
        $start('20260508-090000');
        expect(fn () => $control->beginScopedFinalization($reservation['token']))
            ->toThrow(RuntimeException::class, 'scoped walkthrough reservation has changed');
        expect($control->read()['status'])->toBe('active');

        return;
    }

    $pending = $control->beginScopedFinalization($reservation['token']);
    expect($pending['status'])->toBe('finalizing')
        ->and($pending['finalization_intent']['base_run_id'])->toBe($reservation['run_id'])
        ->and($pending['finalization_intent']['scoped_run_id'])->toBe($run['run_id'])
        ->and(app(BrowserWalkthroughControl::class)->read())->toBe($pending)
        ->and($storage->currentRun(ElectionRunType::Rehearsal)['status'])->toBe('open');
    expect(fn () => $control->beginScopedFinalization($reservation['token']))->toThrow(RuntimeException::class);
    expect(fn () => app(ElectionRunScopeLock::class)->executeCommand(fn () => null))->toThrow(RuntimeException::class);
    expect($control->read())->toBe($pending);
    if ($outcome === 'recovery') {
        $conflict = $run['run_path'].'/scoped-walkthrough-evidence.json';
        file_put_contents($conflict, 'conflicting evidence');
        expect(fn () => $control->finishScopedFinalization($reservation['token'], true))
            ->toThrow(RuntimeException::class, 'checkpoint differs');
        expect(file_get_contents($conflict))->toBe('conflicting evidence');
        unlink($conflict);
        $pending['coordinator_pid'] = 99_999_999;
        unset($pending['control_hash']);
        $json = app(CanonicalJson::class);
        $pending['control_hash'] = $json->hash($pending);
        file_put_contents($reservation['control_path'], $json->encode($pending));
        $this->artisan('election:browser-walkthrough:recover', [
            'run' => $reservation['run_id'],
            '--coordinator-stopped' => true,
        ])->expectsOutputToContain('Interrupted demo walkthrough recovered.')->assertSuccessful();
        $completed = $control->read();
        expect($completed['status'])->toBe('failed');
    } elseif ($outcome === 'finish') {
        $completed = $control->finishScopedFinalization($reservation['token'], true);
        expect($completed['status'])->toBe('passed');
    } else {
        return;
    }
    expect($completed['evidence_checkpoints'])->toHaveCount(2);
    foreach ($completed['evidence_checkpoints'] as $path => $digest) {
        expect(hash_file('sha256', $path))->toBe($digest);
    }
})->with(['replacement', 'finalizing', 'finish', 'recovery']);

test('walkthrough authorization requires matching rehearsal pointer and run context', function (string $change): void {
    $control = app(BrowserWalkthroughControl::class);
    $run = $control->begin('full-election', '39010001');
    $path = $run['run_path'].'/run-context.json';
    $contents = file_get_contents($path);
    if ($change === 'link') {
        rename($path, $path.'.original');
        symlink($path.'.original', $path);
    } else {
        $record = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $record['precinct_id'] = 'different-precinct';
        file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));
    }

    try {
        expect(fn () => $control->authorize($run['token']))
            ->toThrow(RuntimeException::class, 'rehearsal pointer has changed');
    } finally {
        if ($change === 'link') {
            unlink($path);
            rename($path.'.original', $path);
        } else {
            file_put_contents($path, $contents);
        }
    }
})->with(['link', 'identity']);

test('a short walkthrough reservation write is reported without claiming success', function (): void {
    $storage = app(ElectionStorage::class);
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('put')->once()->andReturn(1);
    $control = new BrowserWalkthroughControl($storage, app(ElectionClock::class), app(CanonicalJson::class), $files, app(BrowserWalkthroughRecovery::class));

    expect(fn () => $control->begin('full-election', '39010001'))
        ->toThrow(RuntimeException::class, 'Unable to write the browser walkthrough reservation completely');
    expect($storage->currentRun(ElectionRunType::Rehearsal))->not->toBeEmpty();
});

test('walkthrough token binds browser requests to an isolated rehearsal run', function (): void {
    $storage = app(ElectionStorage::class);
    $storage->selectRunType(ElectionRunType::ElectionDay);
    $electionDay = $storage->startRun(
        'operator',
        '39010001',
        '20260511-050000',
        ElectionRunType::ElectionDay,
    );

    $walkthrough = app(BrowserWalkthroughControl::class)->begin('full-election', '39010001');

    $this->withHeader(BrowserWalkthroughControl::Header, $walkthrough['token'])
        ->get('/browser-walkthrough-context-test')
        ->assertSuccessful()
        ->assertJson([
            'configured_run_type' => ElectionRunType::Rehearsal->value,
            'run_id' => $walkthrough['run_id'],
        ]);

    expect($storage->currentRun(ElectionRunType::ElectionDay)['run_id'])->toBe($electionDay['run_id'])
        ->and(config('election.runtime.run_type'))->toBe(ElectionRunType::ElectionDay->value);
});

test('walkthrough middleware rejects invalid tokens and changed rehearsal pointers', function (): void {
    $control = app(BrowserWalkthroughControl::class);
    $storage = app(ElectionStorage::class);
    $walkthrough = $control->begin('full-election', '39010001');

    $this->withHeader(BrowserWalkthroughControl::Header, 'invalid-token')
        ->get('/browser-walkthrough-context-test')
        ->assertForbidden();

    $storage->startRun(
        'another-rehearsal',
        '39010001',
        '20260508-090000',
        ElectionRunType::Rehearsal,
    );

    $this->withHeader(BrowserWalkthroughControl::Header, $walkthrough['token'])
        ->get('/browser-walkthrough-context-test')
        ->assertForbidden();
});

test('walkthrough control refuses concurrent runs and invalidates completed tokens', function (): void {
    $control = app(BrowserWalkthroughControl::class);
    $walkthrough = $control->begin('full-election', '39010001');

    expect(fn () => $control->begin('full-election', '39010001'))
        ->toThrow(RuntimeException::class, 'already active');

    $completed = $control->complete($walkthrough['token'], 'passed');

    expect($completed['status'])->toBe('passed')
        ->and(fn () => $control->authorize($walkthrough['token']))
        ->toThrow(RuntimeException::class);
});

test('direct run switching and sample activation preserve an active walkthrough', function (string $operation): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $walkthrough = app(BrowserWalkthroughControl::class)->begin('full-election', '39010001');
    $pointer = $storage->currentRun(ElectionRunType::Rehearsal);
    $marker = $walkthrough['run_path'].'/preserved-marker.txt';
    file_put_contents($marker, 'preserve');
    $run = fn () => $operation === 'scenario'
        ? app(ScenarioRunner::class)->run('full-demo')
        : app(ActivateSamplePackage::class)->handle();

    expect($run)->toThrow(RuntimeException::class, 'active browser walkthrough reserves');
    expect($storage->currentRun(ElectionRunType::Rehearsal))->toBe($pointer)
        ->and(file_get_contents($marker))->toBe('preserve')
        ->and(config('election.runtime.run_type'))->not->toBe(ElectionRunType::Rehearsal->value);
})->with(['scenario', 'sample']);

test('an authorized walkthrough callback cannot switch its reserved run', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $walkthrough = app(BrowserWalkthroughControl::class)->begin('full-election', '39010001');
    $pointer = $storage->currentRun(ElectionRunType::Rehearsal);
    Route::middleware('web')->get('/election/test-direct-scenario', fn () => app(ScenarioRunner::class)->run('full-demo'));
    $this->withoutExceptionHandling();

    expect(fn () => $this->withHeader(BrowserWalkthroughControl::Header, $walkthrough['token'])->get('/election/test-direct-scenario'))
        ->toThrow(RuntimeException::class, 'active browser walkthrough reserves');
    expect($storage->currentRun(ElectionRunType::Rehearsal))->toBe($pointer)
        ->and($walkthrough['run_path'])->toBeDirectory();
});

test('a matching authorized callback activates the reserved walkthrough precinct', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $precinct = (string) config('election.pop.clustered_precinct');
    $storage = app(ElectionStorage::class);
    $walkthrough = app(BrowserWalkthroughControl::class)->begin('full-election', $precinct);
    Route::middleware('web')->post('/election/test-configured-activation', function (ActivateConfiguredPrecinct $activation): array {
        $result = $activation->handle();

        return ['precinct' => $result['configuration']['precinct_id']];
    });

    $this->withHeader(BrowserWalkthroughControl::Header, $walkthrough['token'])
        ->post('/election/test-configured-activation')
        ->assertSuccessful()
        ->assertJson(['precinct' => $precinct]);

    expect($storage->currentRun(ElectionRunType::Rehearsal)['run_id'])->toBe($walkthrough['run_id'])
        ->and($walkthrough['run_path'].'/01-precinct-package-and-configuration/active-precinct.json')->toBeFile();
});

test('direct configured activation cannot use an active walkthrough reservation', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $walkthrough = app(BrowserWalkthroughControl::class)->begin('full-election', (string) config('election.pop.clustered_precinct'));
    $pointer = $storage->currentRun(ElectionRunType::Rehearsal);

    expect(fn () => app(ActivateConfiguredPrecinct::class)->handle())
        ->toThrow(RuntimeException::class, 'does not authorize this precinct activation scope');
    expect($storage->currentRun(ElectionRunType::Rehearsal))->toBe($pointer)
        ->and($walkthrough['run_path'].'/01-precinct-package-and-configuration/active-precinct.json')->not->toBeFile();
});

test('symbolic links in the walkthrough reservation path fail closed', function (string $link): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $walkthrough = app(BrowserWalkthroughControl::class)->begin('full-election', (string) config('election.pop.clustered_precinct'));
    $pointer = $storage->currentRun(ElectionRunType::Rehearsal);
    $directory = $storage->root().'/browser-walkthrough';
    $path = $link === 'directory' ? $directory : $directory.'/control.json';
    $backup = $path.'.preserved';
    rename($path, $backup);
    symlink($link === 'directory' ? $backup : $directory.'/missing-control', $path);

    try {
        expect(fn () => app(ActivateConfiguredPrecinct::class)->handle())
            ->toThrow(RuntimeException::class, 'reservation path is a symbolic link');
        expect($storage->currentRun(ElectionRunType::Rehearsal))->toBe($pointer)
            ->and($walkthrough['run_path'].'/01-precinct-package-and-configuration/active-precinct.json')->not->toBeFile()
            ->and(file_exists($backup))->toBeTrue();
    } finally {
        unlink($path);
        rename($backup, $path);
    }
})->with(['control', 'directory']);

test('walkthrough callback refuses changed activation scope', function (string $change): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $base = config('election.storage.directory');
    $precinct = config('election.pop.clustered_precinct');
    $walkthrough = app(BrowserWalkthroughControl::class)->begin('full-election', (string) $precinct);
    $pointer = $storage->currentRun(ElectionRunType::Rehearsal);
    $capturedRequest = null;
    Route::middleware('web')->post('/election/test-changed-activation', function (Request $request) use ($change, &$capturedRequest): array {
        $capturedRequest = $request;
        if ($change === 'precinct') {
            config()->set('election.pop.clustered_precinct', 'different-precinct');
        } elseif ($change === 'scope') {
            config()->set('election.storage.directory', config('election.storage.directory').'/public-simulations/ROUND/PRECINCT');
        } else {
            config()->set('election.runtime.run_type', ElectionRunType::ElectionDay->value);
        }

        return app(ActivateConfiguredPrecinct::class)->handle();
    });
    $this->withoutExceptionHandling();

    try {
        expect(fn () => $this->withHeader(BrowserWalkthroughControl::Header, $walkthrough['token'])->post('/election/test-changed-activation'))
            ->toThrow(RuntimeException::class, 'does not authorize this precinct activation scope');
    } finally {
        config()->set('election.storage.directory', $base);
        config()->set('election.pop.clustered_precinct', $precinct);
    }
    expect($storage->currentRun(ElectionRunType::Rehearsal))->toBe($pointer)
        ->and($walkthrough['run_path'].'/01-precinct-package-and-configuration/active-precinct.json')->not->toBeFile()
        ->and($capturedRequest->attributes->has(LockElectionRunScope::VerifiedWalkthrough))->toBeFalse();
})->with(['precinct', 'scope', 'run type']);

test('scoped walkthrough modes are rejected before creating a round with the local lock', function (string $scenario): void {
    config()->set('election.voting_lock_driver', 'local');
    $this->artisan('election:browser-walkthrough', ['scenario' => $scenario])
        ->expectsOutputToContain('require a verified scoped-run reservation')
        ->assertFailed();
    expect(SimulationRound::query()->count())->toBe(0)
        ->and(app(BrowserWalkthroughControl::class)->read())->toBe([]);
})->with(['public-simulation', 'demo-room']);

test('direct run switching and precinct activations wait for the base run lock', function (string $operation): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $lockDirectory = storage_path('app/private/election-operation-locks');
    if (! is_dir($lockDirectory)) {
        mkdir($lockDirectory, 0770, true);
    }
    $lockPath = $lockDirectory.'/run-scope-'.hash('sha256', $storage->root()).'.lock';
    $holder = new Process([PHP_BINARY, '-r', '$h=fopen($argv[1], "c"); flock($h, LOCK_EX); echo "ready\\n"; flush(); sleep(30);', $lockPath]);
    $holder->start();
    $holder->waitUntil(fn (string $type, string $text): bool => str_contains($text, 'ready'));
    $run = fn () => match ($operation) {
        'scenario' => app(ScenarioRunner::class)->run('full-demo'),
        'sample' => app(ActivateSamplePackage::class)->handle(),
        'configured' => app(ActivateConfiguredPrecinct::class)->handle(),
    };

    try {
        expect($run)->toThrow(LockTimeoutException::class);
        expect($storage->currentRun(ElectionRunType::Rehearsal))->toBe([])
            ->and($storage->currentRun(ElectionRunType::ElectionDay))->toBe([]);
    } finally {
        $holder->stop();
    }
})->with(['scenario', 'sample', 'configured']);

test('walkthrough callbacks reject a mismatched reservation even with a valid token', function (string $field): void {
    $storage = app(ElectionStorage::class);
    $control = app(BrowserWalkthroughControl::class);
    $walkthrough = $control->begin('full-election', '39010001');
    $record = $control->read();
    unset($record['control_hash']);
    $record[$field] = match ($field) {
        'run_path' => $walkthrough['run_path'].'-different',
        'run_type' => ElectionRunType::ElectionDay->value,
        default => 'invalid-schema',
    };
    $record['control_hash'] = app(CanonicalJson::class)->hash($record);
    $path = $storage->root().'/browser-walkthrough/control.json';
    $bytes = app(CanonicalJson::class)->encode($record);
    file_put_contents($path, $bytes);

    expect(fn () => $control->authorize($walkthrough['token']))->toThrow(RuntimeException::class);
    expect(file_get_contents($path))->toBe($bytes);
})->with(['run_path', 'run_type', 'schema_version']);

test('an expired lease does not replace a walkthrough whose coordinator is still alive', function (): void {
    $clock = app(ElectionClock::class);
    $clock->freeze('2026-05-08 08:00:00');
    $control = app(BrowserWalkthroughControl::class);
    $walkthrough = $control->begin('full-election', '39010001');
    $clock->freeze('2026-05-09 08:00:00');

    try {
        expect(fn () => $control->begin('full-election', '39010001'))
            ->toThrow(RuntimeException::class, 'already active');
        expect(fn () => app(BrowserWalkthroughRecovery::class)->recover($control->read(), 'expired'))
            ->toThrow(RuntimeException::class, 'coordinator may still be running');
        expect($control->read()['run_id'])->toBe($walkthrough['run_id'])
            ->and($walkthrough['run_path'].'/12-audit-and-reconciliation/browser-recordings/browser-walkthrough-recovery.json')->not->toBeFile();
    } finally {
        $clock->unfreeze();
    }
});

test('walkthrough recovery refuses inconsistent existing evidence without replacing it', function (string $problem): void {
    config()->set('election.voting_lock_driver', 'local');
    $control = app(BrowserWalkthroughControl::class);
    $storage = app(ElectionStorage::class);
    $walkthrough = $control->begin('full-election', '39010001');
    $controlPath = $storage->root().'/browser-walkthrough/control.json';
    $record = $control->read();
    unset($record['control_hash']);
    $record['coordinator_pid'] = 99_999_999;
    if ($problem === 'path') {
        $record['run_path'] = $storage->root().'/runs/../outside';
    }
    $record['control_hash'] = app(CanonicalJson::class)->hash($record);
    $controlBytes = app(CanonicalJson::class)->encode($record);
    file_put_contents($controlPath, $controlBytes);
    $directory = $walkthrough['run_path'].'/12-audit-and-reconciliation/browser-recordings';
    mkdir($directory, 0700, true);
    $evidencePath = match ($problem) {
        'completion', 'completion_identity', 'link' => $directory.'/browser-walkthrough-completion.json',
        'report', 'report_identity' => $directory.'/browser-walkthrough-report.json',
        'recovery' => $directory.'/browser-walkthrough-recovery.json',
        'finalized' => $walkthrough['run_path'].'/artifact-index.json',
        default => null,
    };
    if ($problem === 'link') {
        symlink('../../run-context.json', $evidencePath);
    } elseif (in_array($problem, ['completion_identity', 'report_identity'], true)) {
        $isCompletion = $problem === 'completion_identity';
        $evidence = [
            'schema_version' => $isCompletion ? 'browser-walkthrough-completion-1' : 'browser-walkthrough-report-1',
            'run_id' => $walkthrough['run_id'],
            'walkthrough_id' => 'different-walkthrough',
            'passed' => true,
        ];
        $hashKey = $isCompletion ? 'completion_hash' : 'report_hash';
        $evidence[$hashKey] = app(CanonicalJson::class)->hash($evidence);
        file_put_contents($evidencePath, app(CanonicalJson::class)->encode($evidence));
    } elseif ($evidencePath !== null) {
        file_put_contents($evidencePath, '{}');
    }
    $evidenceBytes = $evidencePath !== null && $problem !== 'link' ? file_get_contents($evidencePath) : null;

    expect(fn () => $control->recoverInterrupted($walkthrough['run_id']))->toThrow(RuntimeException::class);
    expect(file_get_contents($controlPath))->toBe($controlBytes);
    if ($problem !== 'finalized') {
        expect($walkthrough['run_path'].'/artifact-index.json')->not->toBeFile();
    }
    if ($problem === 'link') {
        expect(is_link($evidencePath))->toBeTrue();
    } elseif ($evidencePath !== null) {
        expect(file_get_contents($evidencePath))->toBe($evidenceBytes);
    }
})->with(['completion', 'completion_identity', 'report', 'report_identity', 'recovery', 'finalized', 'link', 'path']);

test('walkthrough recovery preserves verified completion and report evidence', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $control = app(BrowserWalkthroughControl::class);
    $storage = app(ElectionStorage::class);
    $walkthrough = $control->begin('full-election', '39010001');
    $record = $control->read();
    unset($record['control_hash']);
    $record['coordinator_pid'] = 99_999_999;
    $record['control_hash'] = app(CanonicalJson::class)->hash($record);
    file_put_contents($storage->root().'/browser-walkthrough/control.json', app(CanonicalJson::class)->encode($record));
    $directory = $walkthrough['run_path'].'/12-audit-and-reconciliation/browser-recordings';
    mkdir($directory, 0700, true);
    $completion = [
        'schema_version' => 'browser-walkthrough-completion-1',
        'run_id' => $walkthrough['run_id'],
        'walkthrough_id' => $walkthrough['walkthrough_id'],
        'passed' => true,
    ];
    $completion['completion_hash'] = app(CanonicalJson::class)->hash($completion);
    $report = [
        'schema_version' => 'browser-walkthrough-report-1',
        'run_id' => $walkthrough['run_id'],
        'walkthrough_id' => $walkthrough['walkthrough_id'],
        'scenario' => 'full-election',
        'passed' => true,
    ];
    $report['report_hash'] = app(CanonicalJson::class)->hash($report);
    $completionBytes = app(CanonicalJson::class)->encode($completion);
    $reportBytes = app(CanonicalJson::class)->encode($report);
    file_put_contents($directory.'/browser-walkthrough-completion.json', $completionBytes);
    file_put_contents($directory.'/browser-walkthrough-report.json', $reportBytes);

    $control->recoverInterrupted($walkthrough['run_id']);
    $replacement = $control->begin('full-election', '39010001');

    expect($replacement['run_id'])->not->toBe($walkthrough['run_id'])
        ->and(file_get_contents($directory.'/browser-walkthrough-completion.json'))->toBe($completionBytes)
        ->and(file_get_contents($directory.'/browser-walkthrough-report.json'))->toBe($reportBytes)
        ->and(json_decode(file_get_contents($walkthrough['run_path'].'/run-summary.json'), true, flags: JSON_THROW_ON_ERROR)['passed'])->toBeFalse()
        ->and($walkthrough['run_path'].'/artifact-index.json')->toBeFile();
});

test('an operator can recover a stopped demo walkthrough without starting another run', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $control = app(BrowserWalkthroughControl::class);
    $walkthrough = $control->begin('full-election', '39010001');
    $record = $control->read();
    unset($record['control_hash']);
    $record['coordinator_pid'] = 99_999_999;
    $record['control_hash'] = app(CanonicalJson::class)->hash($record);
    file_put_contents($storage->root().'/browser-walkthrough/control.json', app(CanonicalJson::class)->encode($record));

    $this->artisan('election:browser-walkthrough:recover', ['run' => 'different-run', '--coordinator-stopped' => true])
        ->expectsOutputToContain('run does not match')
        ->assertFailed();
    expect($control->read())->toBe($record);

    $this->artisan('election:browser-walkthrough:recover', ['run' => $walkthrough['run_id'], '--coordinator-stopped' => true])
        ->expectsOutputToContain('Interrupted demo walkthrough recovered.')
        ->assertSuccessful();
    $recovered = $control->read();
    expect($recovered['status'])->toBe('failed')
        ->and($recovered['run_id'])->toBe($walkthrough['run_id'])
        ->and($recovered['recovered_run_finalized'])->toBeTrue()
        ->and($walkthrough['run_path'].'/artifact-index.json')->toBeFile()
        ->and($recovered['recovery_path'])->toBeFile();
    $evidence = file_get_contents($recovered['recovery_path']);
    $this->artisan('election:browser-walkthrough:recover', ['run' => $walkthrough['run_id'], '--coordinator-stopped' => true])
        ->expectsOutputToContain('There is no active walkthrough reservation to recover.')
        ->assertFailed();
    expect(file_get_contents($recovered['recovery_path']))->toBe($evidence);
});

test('the operator recovery command refuses a live coordinator or a nonlocal lock profile', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $control = app(BrowserWalkthroughControl::class);
    $walkthrough = $control->begin('full-election', '39010001');
    $before = $control->read();

    $this->artisan('election:browser-walkthrough:recover', ['run' => $walkthrough['run_id']])
        ->expectsOutputToContain('Confirm that the walkthrough coordinator and its browser are stopped')
        ->assertFailed();
    $this->artisan('election:browser-walkthrough:recover', ['run' => $walkthrough['run_id'], '--coordinator-stopped' => true])
        ->expectsOutputToContain('The walkthrough coordinator may still be running.')
        ->assertFailed();
    expect($control->read())->toBe($before)
        ->and($walkthrough['run_path'].'/artifact-index.json')->not->toBeFile();

    config()->set('election.voting_lock_driver', 'cache');
    $this->artisan('election:browser-walkthrough:recover', ['run' => $walkthrough['run_id'], '--coordinator-stopped' => true])
        ->expectsOutputToContain('requires the single-host local lock profile')
        ->assertFailed();
});

test('a short walkthrough recovery write is reported before the run is finalized', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $control = app(BrowserWalkthroughControl::class);
    $walkthrough = $control->begin('full-election', '39010001');
    $record = $control->read();
    unset($record['control_hash']);
    $record['coordinator_pid'] = 99_999_999;
    $record['control_hash'] = app(CanonicalJson::class)->hash($record);
    file_put_contents($storage->root().'/browser-walkthrough/control.json', app(CanonicalJson::class)->encode($record));
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('put')->once()->andReturn(1);
    $recovery = new BrowserWalkthroughRecovery($storage, app(ElectionClock::class), app(CanonicalJson::class), $files);

    expect(fn () => $recovery->recover($record, 'coordinator stopped'))->toThrow(RuntimeException::class, 'Unable to write walkthrough recovery evidence completely');
    expect($walkthrough['run_path'].'/artifact-index.json')->not->toBeFile()
        ->and($storage->currentRun(ElectionRunType::Rehearsal)['status'])->toBe('open');
});

test('recovery refuses a supplied reservation that differs from the current control record', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $control = app(BrowserWalkthroughControl::class);
    $walkthrough = $control->begin('full-election', '39010001');
    $original = $control->read();
    $forged = $original;
    unset($forged['control_hash']);
    $forged['coordinator_pid'] = 99_999_999;
    $forged['control_hash'] = app(CanonicalJson::class)->hash($forged);

    expect(fn () => app(BrowserWalkthroughRecovery::class)->recover($forged, 'coordinator stopped'))
        ->toThrow(RuntimeException::class, 'does not match the current control record');
    expect($control->read())->toBe($original)
        ->and($walkthrough['run_path'].'/artifact-index.json')->not->toBeFile();
});

test('a partial recovery record blocks a second recovery and preserves evidence', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $control = app(BrowserWalkthroughControl::class);
    $walkthrough = $control->begin('full-election', '39010001');
    $record = $control->read();
    unset($record['control_hash']);
    $record['coordinator_pid'] = 99_999_999;
    $record['control_hash'] = app(CanonicalJson::class)->hash($record);
    file_put_contents($storage->root().'/browser-walkthrough/control.json', app(CanonicalJson::class)->encode($record));
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $writes = 0;
    $files->shouldReceive('put')->twice()->andReturnUsing(function (string $path, string $contents) use (&$writes): int|false {
        $writes++;

        return file_put_contents($path, $writes === 1 ? $contents : substr($contents, 0, 1));
    });
    $recovery = new BrowserWalkthroughRecovery($storage, app(ElectionClock::class), app(CanonicalJson::class), $files);
    $directory = $walkthrough['run_path'].'/12-audit-and-reconciliation/browser-recordings';

    expect(fn () => $recovery->recover($record, 'coordinator stopped'))
        ->toThrow(RuntimeException::class, 'Unable to write walkthrough recovery evidence completely');
    expect(file_get_contents($directory.'/browser-walkthrough-recovery.json'))->toBe('{')
        ->and($directory.'/browser-walkthrough-completion.json')->toBeFile()
        ->and($storage->currentRun(ElectionRunType::Rehearsal)['status'])->toBe('open');
    expect(fn () => $control->recoverInterrupted($walkthrough['run_id']))->toThrow(RuntimeException::class, 'recovery evidence already exists');
    expect(file_get_contents($directory.'/browser-walkthrough-recovery.json'))->toBe('{')
        ->and($control->read()['status'])->toBe('active');
});

test('a partial final reservation write blocks subsequent recovery without replacing evidence', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $control = app(BrowserWalkthroughControl::class);
    $walkthrough = $control->begin('full-election', '39010001');
    $record = $control->read();
    unset($record['control_hash']);
    $record['coordinator_pid'] = 99_999_999;
    $record['control_hash'] = app(CanonicalJson::class)->hash($record);
    $path = $storage->root().'/browser-walkthrough/control.json';
    file_put_contents($path, app(CanonicalJson::class)->encode($record));
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('put')->once()->andReturnUsing(
        fn (string $target, string $contents): int|false => file_put_contents($target, substr($contents, 0, 1)),
    );
    $failingControl = new BrowserWalkthroughControl($storage, app(ElectionClock::class), app(CanonicalJson::class), $files, app(BrowserWalkthroughRecovery::class));

    expect(fn () => $failingControl->recoverInterrupted($walkthrough['run_id']))
        ->toThrow(RuntimeException::class, 'Unable to write the browser walkthrough reservation completely');
    expect(file_get_contents($path))->toBe('{')
        ->and($walkthrough['run_path'].'/artifact-index.json')->toBeFile();
    expect(fn () => $control->recoverInterrupted($walkthrough['run_id']))->toThrow(JsonException::class);
    expect(file_get_contents($path))->toBe('{');
});

test('walkthrough control recovers and locks evidence from a dead coordinator', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $control = app(BrowserWalkthroughControl::class);
    $storage = app(ElectionStorage::class);
    $clock = app(ElectionClock::class);
    $clock->freeze('2026-05-08 08:00:00');
    $interrupted = $control->begin('full-election', '39010001');
    $controlPath = $storage->root().'/browser-walkthrough/control.json';
    $controlRecord = json_decode(file_get_contents($controlPath), true, flags: JSON_THROW_ON_ERROR);
    unset($controlRecord['control_hash']);
    $controlRecord['coordinator_pid'] = 99_999_999;
    $controlRecord['control_hash'] = app(CanonicalJson::class)->hash($controlRecord);
    file_put_contents($controlPath, app(CanonicalJson::class)->encode($controlRecord));
    $clock->tick();

    $control->recoverInterrupted($interrupted['run_id']);
    $replacement = $control->begin('full-election', '39010001');
    $interruptedContext = json_decode(
        file_get_contents($interrupted['run_path'].'/run-context.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $completion = json_decode(
        file_get_contents($interrupted['run_path'].'/12-audit-and-reconciliation/browser-recordings/browser-walkthrough-completion.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $recovery = json_decode(
        file_get_contents($interrupted['run_path'].'/12-audit-and-reconciliation/browser-recordings/browser-walkthrough-recovery.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($replacement['run_id'])->not->toBe($interrupted['run_id'])
        ->and($interruptedContext['status'])->toBe('locked')
        ->and($interrupted['run_path'].'/run-summary.json')->toBeFile()
        ->and($interrupted['run_path'].'/artifact-index.json')->toBeFile()
        ->and($completion['passed'])->toBeFalse()
        ->and($completion['error'])->toContain('no longer running')
        ->and($recovery['completion_found'])->toBeFalse()
        ->and($recovery['completion_passed'])->toBeFalse()
        ->and($control->read()['run_id'])->toBe($replacement['run_id'])
        ->and($control->read()['status'])->toBe('active');

    $clock->unfreeze();
});
