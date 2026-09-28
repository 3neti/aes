<?php

use App\Election\Runtime\RuntimeHeartbeat;
use App\Election\Runtime\RuntimeReadinessService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('election.runtime.heartbeat_max_age_seconds', 150);
    config()->set('election.runtime.scanner.mode', 'browser');
    config()->set('broadcasting.default', 'null');
    config()->set('election.devices.printer.driver', 'file');
    config()->set('election.control_number_printer.driver', 'file');
    config()->set('election.closeout_printer.driver', 'file');
});

test('runtime is ready with fresh required heartbeats and optional warnings', function (): void {
    $heartbeats = app(RuntimeHeartbeat::class);
    $heartbeats->record('queue');
    $heartbeats->record('scheduler');

    $report = app(RuntimeReadinessService::class)->inspect();

    expect($report['ready'])->toBeTrue()
        ->and($report['status'])->toBe('degraded')
        ->and(collect($report['checks'])->firstWhere('id', 'reverb_configuration')['status'])->toBe('warn')
        ->and(collect($report['checks'])->firstWhere('id', 'scanner_bridge')['required'])->toBeFalse();
});

test('runtime fails when a required worker heartbeat is missing', function (): void {
    app(RuntimeHeartbeat::class)->record('scheduler');

    $report = app(RuntimeReadinessService::class)->inspect();

    expect($report['ready'])->toBeFalse()
        ->and($report['status'])->toBe('not_ready')
        ->and(collect($report['checks'])->firstWhere('id', 'queue_heartbeat')['status'])->toBe('fail');
});

test('configured scanner bridge requires a readable device', function (): void {
    config()->set('election.runtime.scanner.mode', 'evdev');
    config()->set('election.runtime.scanner.device', '/dev/input/by-id/missing-scanner');

    $report = app(RuntimeReadinessService::class)->inspect();

    $scanner = collect($report['checks'])->firstWhere('id', 'scanner_bridge');

    expect($scanner['required'])->toBeTrue()
        ->and($scanner['status'])->toBe('fail');
});

test('configured scanner bridge requires a fresh functional heartbeat', function (): void {
    $device = Storage::disk('local')->path('scanner-device');
    Storage::disk('local')->put('scanner-device', '');
    config()->set('election.runtime.scanner.mode', 'evdev');
    config()->set('election.runtime.scanner.device', $device);
    app(RuntimeHeartbeat::class)->record('scanner');

    $report = app(RuntimeReadinessService::class)->inspect();
    $scanner = collect($report['checks'])->firstWhere('id', 'scanner_bridge');

    expect($scanner['required'])->toBeTrue()
        ->and($scanner['status'])->toBe('pass')
        ->and($scanner['detail'])->toContain('Scanner bridge heartbeat received');
});

test('cups readiness fails when a configured printer reports a blocked device', function (): void {
    app(RuntimeHeartbeat::class)->record('queue');
    app(RuntimeHeartbeat::class)->record('scheduler');
    config()->set('election.control_number_printer.driver', 'cups');
    config()->set('election.control_number_printer.cups.name', 'Thermal80');
    Process::preventStrayProcesses();
    Process::fake([
        'lpstat -p Thermal80 -l' => Process::result(
            output: "printer Thermal80 now printing Thermal80-252. Waiting for printer to become available.\n",
        ),
    ]);

    $report = app(RuntimeReadinessService::class)->inspect();
    $printer = collect($report['checks'])->firstWhere('id', 'cups_thermal80');

    expect($printer['required'])->toBeTrue()
        ->and($printer['status'])->toBe('fail')
        ->and($report['ready'])->toBeFalse();
});

test('cups readiness warns when a reachable printer has unfinished jobs', function (): void {
    app(RuntimeHeartbeat::class)->record('queue');
    app(RuntimeHeartbeat::class)->record('scheduler');
    config()->set('election.devices.printer.driver', 'cups');
    config()->set('election.devices.printer.cups.name', 'HP');
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::sequence()
            ->push(Process::result(output: "printer HP is idle. enabled since today\n"))
            ->push(Process::result(output: "HP-42 ace 2048 today\n")),
    ]);

    $report = app(RuntimeReadinessService::class)->inspect();
    $printer = collect($report['checks'])->firstWhere('id', 'cups_hp');

    expect($printer['status'])->toBe('warn')
        ->and($printer['detail'])->toContain('1 unfinished print job')
        ->and($report['status'])->toBe('degraded');
});

test('cups readiness fails when cups is enabled without a queue name', function (): void {
    app(RuntimeHeartbeat::class)->record('queue');
    app(RuntimeHeartbeat::class)->record('scheduler');
    config()->set('election.closeout_printer.driver', 'cups');
    config()->set('election.closeout_printer.cups.name', '');

    $report = app(RuntimeReadinessService::class)->inspect();
    $printer = collect($report['checks'])->firstWhere('id', 'cups_closeout_configuration');

    expect($printer['status'])->toBe('fail')
        ->and($printer['detail'])->toContain('no queue name');
});

test('runtime check command returns failure for missing required heartbeats', function (): void {
    $this->artisan('election:runtime-check')
        ->assertFailed();
});
