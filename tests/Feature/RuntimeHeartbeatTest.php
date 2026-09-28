<?php

use App\Election\Runtime\RuntimeHeartbeat;
use App\Jobs\RecordRuntimeHeartbeat as RecordRuntimeHeartbeatJob;
use Illuminate\Support\Facades\Storage;

test('runtime heartbeats are recorded and expire', function (): void {
    Storage::fake('local');
    $this->freezeTime();

    $heartbeats = app(RuntimeHeartbeat::class);
    $recorded = $heartbeats->record('scheduler');

    expect($recorded['service'])->toBe('scheduler')
        ->and($heartbeats->latest('scheduler')['recorded_at'])->toBe($recorded['recorded_at'])
        ->and($heartbeats->isFresh('scheduler'))->toBeTrue();

    $this->travel(91)->seconds();

    expect($heartbeats->isFresh('scheduler'))->toBeFalse();
});

test('the queue heartbeat job proves that a worker processed queued work', function (): void {
    Storage::fake('local');

    app()->call([new RecordRuntimeHeartbeatJob('queue'), 'handle']);

    expect(app(RuntimeHeartbeat::class)->isFresh('queue'))->toBeTrue();
});

test('the runtime heartbeat command records the requested service', function (): void {
    Storage::fake('local');

    $this->artisan('election:runtime-heartbeat', ['service' => 'scanner'])
        ->expectsOutputToContain('scanner heartbeat recorded at')
        ->assertSuccessful();

    expect(app(RuntimeHeartbeat::class)->isFresh('scanner'))->toBeTrue();
});
