<?php

namespace App\Events;

use App\Models\ScannerScanEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ScannerScanEventRecorded
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  array<string, mixed>  $scannerState
     */
    public function __construct(
        public readonly ScannerScanEvent $scanEvent,
        public readonly array $scannerState,
    ) {}
}
