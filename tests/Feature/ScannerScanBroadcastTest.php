<?php

use App\Events\ScannerScanEventRecorded;
use App\Models\ScannerScanEvent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

test('scanner broadcasts contain revision metadata but not canonical document data', function (): void {
    $scanEvent = new ScannerScanEvent([
        'station_id' => 'role-demo-precinct',
        'scan_type' => 'official_ballot',
        'source' => 'scanner_bridge',
        'payload' => 'truth://sensitive-ballot-payload',
        'payload_hash' => hash('sha256', 'truth://sensitive-ballot-payload'),
        'status' => 'accepted',
        'document_hash' => 'ballot-document-hash',
        'message' => 'Accepted ballot.',
        'received_at' => now(),
    ]);
    $scanEvent->id = 42;

    $event = new ScannerScanEventRecorded($scanEvent, [
        'revision' => 42,
        'accepted_ballots' => [['payload' => 'must-not-be-broadcast']],
        'tally' => ['president' => ['candidate-1' => 1]],
    ]);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event->broadcastOn()->name)->toBe('election.scanner.role-demo-precinct')
        ->and($event->broadcastQueue())->toBe('broadcasts')
        ->and($event->broadcastWith())->toMatchArray([
            'station_id' => 'role-demo-precinct',
            'scan_type' => 'official_ballot',
            'revision' => 42,
            'status' => 'accepted',
            'latest_document_hash' => 'ballot-document-hash',
        ])
        ->and($event->broadcastWith())->not->toHaveKeys([
            'payload',
            'accepted_ballots',
            'tally',
        ]);
});
