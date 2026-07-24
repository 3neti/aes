<?php

use App\Election\Lifecycle\ElectionRunType;
use App\Election\Scenarios\BrowserWalkthroughControl;
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
        ->toThrow(\RuntimeException::class, 'already active');

    $completed = $control->complete($walkthrough['token'], 'passed');

    expect($completed['status'])->toBe('passed')
        ->and(fn () => $control->authorize($walkthrough['token']))
        ->toThrow(\RuntimeException::class);
});
