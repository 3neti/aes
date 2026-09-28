<?php

namespace App\Events;

use App\Models\ScannerScanEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ScannerScanEventRecorded implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  array<string, mixed>  $scannerState
     */
    public function __construct(
        public readonly ScannerScanEvent $scanEvent,
        public readonly array $scannerState,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('election.scanner.'.$this->scanEvent->station_id);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function broadcastWith(): array
    {
        return [
            'station_id' => $this->scanEvent->station_id,
            'scan_type' => $this->scanEvent->scan_type,
            'revision' => (int) ($this->scannerState['revision'] ?? $this->scanEvent->id),
            'status' => $this->scanEvent->status,
            'latest_document_hash' => $this->scanEvent->document_hash,
            'occurred_at' => $this->scanEvent->received_at?->toIso8601String(),
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
