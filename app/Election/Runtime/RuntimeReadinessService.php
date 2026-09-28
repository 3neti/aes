<?php

namespace App\Election\Runtime;

use Illuminate\Filesystem\Filesystem;

final class RuntimeReadinessService
{
    public function __construct(
        private readonly RuntimeHeartbeat $heartbeats,
        private readonly Filesystem $files,
        private readonly CupsQueueReadiness $cupsQueues,
    ) {}

    /**
     * @return array{ready: bool, status: string, checked_at: string, checks: list<array{id: string, label: string, status: string, required: bool, detail: string}>}
     */
    public function inspect(): array
    {
        $maximumAge = max(30, (int) config('election.runtime.heartbeat_max_age_seconds', 150));
        $checks = [
            $this->heartbeatCheck('queue', 'Queue worker', $maximumAge),
            $this->heartbeatCheck('scheduler', 'Scheduler timer', $maximumAge),
            $this->broadcastCheck(),
            $this->scannerCheck(),
            ...$this->cupsQueues->inspect(),
        ];
        $ready = collect($checks)
            ->filter(fn (array $check): bool => $check['required'])
            ->every(fn (array $check): bool => $check['status'] !== 'fail');
        $hasWarning = collect($checks)->contains(
            fn (array $check): bool => $check['status'] === 'warn',
        );

        return [
            'ready' => $ready,
            'status' => $ready ? ($hasWarning ? 'degraded' : 'ready') : 'not_ready',
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, required: bool, detail: string}
     */
    private function heartbeatCheck(string $service, string $label, int $maximumAge): array
    {
        $heartbeat = $this->heartbeats->latest($service);
        $fresh = $this->heartbeats->isFresh($service, $maximumAge);

        return [
            'id' => $service.'_heartbeat',
            'label' => $label,
            'status' => $fresh ? 'pass' : 'fail',
            'required' => true,
            'detail' => $fresh
                ? 'Heartbeat received at '.($heartbeat['recorded_at'] ?? 'unknown').'.'
                : 'No heartbeat was received within the last '.$maximumAge.' seconds.',
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, required: bool, detail: string}
     */
    private function broadcastCheck(): array
    {
        $connection = (string) config('broadcasting.default', 'null');
        $configured = $connection === 'reverb' && filled(config('broadcasting.connections.reverb.key'));

        return [
            'id' => 'reverb_configuration',
            'label' => 'Live updates',
            'status' => $configured ? 'pass' : 'warn',
            'required' => false,
            'detail' => $configured
                ? 'Reverb broadcasting is configured; polling remains available as fallback.'
                : 'Reverb is not configured. Canonical screens will continue using polling.',
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, required: bool, detail: string}
     */
    private function scannerCheck(): array
    {
        $mode = (string) config('election.runtime.scanner.mode', 'browser');
        $device = (string) config('election.runtime.scanner.device', '');

        if ($mode === 'browser') {
            return [
                'id' => 'scanner_bridge',
                'label' => 'Scanner bridge',
                'status' => 'warn',
                'required' => false,
                'detail' => 'Browser keyboard-wedge mode is active; no boot-managed scanner bridge is required.',
            ];
        }

        $readable = $device !== '' && $this->files->exists($device) && is_readable($device);
        $maximumAge = max(30, (int) config('election.runtime.heartbeat_max_age_seconds', 150));
        $heartbeat = $this->heartbeats->latest('scanner');
        $heartbeatFresh = $this->heartbeats->isFresh('scanner', $maximumAge);
        $ready = $readable && $heartbeatFresh;

        return [
            'id' => 'scanner_bridge',
            'label' => 'Scanner bridge device',
            'status' => $ready ? 'pass' : 'fail',
            'required' => true,
            'detail' => match (true) {
                ! $readable => 'Configured '.$mode.' scanner device is missing or unreadable: '.($device ?: '(not set)').'.',
                ! $heartbeatFresh => 'Scanner device is readable, but its bridge heartbeat is missing or stale.',
                default => 'Scanner bridge heartbeat received at '.($heartbeat['recorded_at'] ?? 'unknown').' for '.$device.'.',
            },
        ];
    }
}
