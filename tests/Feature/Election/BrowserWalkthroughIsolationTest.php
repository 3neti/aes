<?php

use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\ElectionRunType;
use App\Election\Scenarios\BrowserWalkthroughControl;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();

    Route::middleware('web')->get('/browser-walkthrough-context-test', function (ElectionStorage $storage): array {
        return [
            'configured_run_type' => config('election.runtime.run_type'),
            'run_id' => $storage->currentRun()['run_id'] ?? null,
        ];
    });
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

test('walkthrough control recovers and locks evidence from a dead coordinator', function (): void {
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
