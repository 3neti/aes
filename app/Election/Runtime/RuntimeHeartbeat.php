<?php

namespace App\Election\Runtime;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemManager;
use InvalidArgumentException;

final class RuntimeHeartbeat
{
    public function __construct(
        private readonly FilesystemManager $filesystems,
    ) {}

    /**
     * @return array{schema_version: string, service: string, recorded_at: string, process_id: int, host: string}
     */
    public function record(string $service): array
    {
        $service = $this->validatedService($service);
        $heartbeat = [
            'schema_version' => 'waes-runtime-heartbeat-1',
            'service' => $service,
            'recorded_at' => now()->toIso8601String(),
            'process_id' => getmypid(),
            'host' => gethostname() ?: 'unknown',
        ];

        $this->filesystems->disk('local')->put(
            $this->path($service),
            json_encode($heartbeat, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        return $heartbeat;
    }

    /**
     * @return array<string, mixed>
     */
    public function latest(string $service): array
    {
        $service = $this->validatedService($service);
        $disk = $this->filesystems->disk('local');
        $path = $this->path($service);

        if (! $disk->exists($path)) {
            return [];
        }

        return (array) json_decode($disk->get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function isFresh(string $service, int $maximumAgeSeconds = 90): bool
    {
        $recordedAt = $this->latest($service)['recorded_at'] ?? null;

        if (! is_string($recordedAt) || $recordedAt === '') {
            return false;
        }

        return CarbonImmutable::parse($recordedAt)
            ->greaterThanOrEqualTo(now()->subSeconds($maximumAgeSeconds));
    }

    private function validatedService(string $service): string
    {
        $service = trim($service);

        if ($service === '' || preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $service) !== 1) {
            throw new InvalidArgumentException('Runtime heartbeat service names may contain lowercase letters, numbers, dashes, and underscores only.');
        }

        return $service;
    }

    private function path(string $service): string
    {
        return 'runtime/heartbeats/'.$service.'.json';
    }
}
