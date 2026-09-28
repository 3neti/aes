<?php

namespace App\Jobs;

use App\Election\Runtime\RuntimeHeartbeat as RuntimeHeartbeatStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RecordRuntimeHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public readonly string $service,
    ) {}

    public function handle(RuntimeHeartbeatStore $heartbeats): void
    {
        $heartbeats->record($this->service);
    }
}
