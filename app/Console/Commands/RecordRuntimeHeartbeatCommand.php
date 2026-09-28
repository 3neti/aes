<?php

namespace App\Console\Commands;

use App\Election\Runtime\RuntimeHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:runtime-heartbeat {service : Runtime service name}')]
#[Description('Record a WAES runtime service heartbeat.')]
final class RecordRuntimeHeartbeatCommand extends Command
{
    public function handle(RuntimeHeartbeat $heartbeats): int
    {
        $heartbeat = $heartbeats->record((string) $this->argument('service'));
        $this->line($heartbeat['service'].' heartbeat recorded at '.$heartbeat['recorded_at'].'.');

        return self::SUCCESS;
    }
}
