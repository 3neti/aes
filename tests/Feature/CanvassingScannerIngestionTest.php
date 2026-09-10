<?php

use App\Election\Support\ElectionStorage;
use App\Events\ScannerScanEventRecorded;
use App\Models\ScannerScanEvent;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    config()->set('election.review.access.enabled', false);
    app(ElectionStorage::class)->reset();
    $this->withoutVite();
});

test('canvassing scanner ingests election return qr parts in any order', function (): void {
    Event::fake([ScannerScanEventRecorded::class]);

    $this->post(route('election.canvassing-demo.generate'), [
        'ballot_count' => 25,
        'return_count' => 3,
    ])->assertRedirectToRoute('election.canvassing-demo.show');

    $returnScan = app(ElectionStorage::class)->readJson('runtime/canvassing-demo.json')['return_scans'][0];
    $payloads = $returnScan['payloads'];

    expect(count($payloads))->toBeGreaterThan(1);

    $this->postJson(route('election.canvassing-demo.scanner-events.store'), [
        'station_id' => 'canvassing-demo-city',
        'source' => 'keyboard_wedge',
        'payload' => $payloads[1],
    ])
        ->assertOk()
        ->assertJsonPath('event.status', 'partial')
        ->assertJsonPath('state.current_multipart.received_parts', [2]);

    $this->postJson(route('election.canvassing-demo.scanner-events.store'), [
        'station_id' => 'canvassing-demo-city',
        'source' => 'keyboard_wedge',
        'payload' => $payloads[0],
    ])
        ->assertOk()
        ->assertJsonPath('event.status', 'partial')
        ->assertJsonPath('state.current_multipart.received_parts', [1, 2]);

    foreach (array_slice($payloads, 2) as $index => $payload) {
        $response = $this->postJson(route('election.canvassing-demo.scanner-events.store'), [
            'station_id' => 'canvassing-demo-city',
            'source' => 'keyboard_wedge',
            'payload' => $payload,
        ])->assertOk();

        if ($index === count($payloads) - 3) {
            $response
                ->assertJsonPath('event.status', 'accepted')
                ->assertJsonPath('state.accepted_return_hashes.0', $returnScan['return_hash'])
                ->assertJsonPath('state.current_multipart', null);
        } else {
            $response->assertJsonPath('event.status', 'partial');
        }
    }

    expect(ScannerScanEvent::query()->count())->toBe(count($payloads));

    Event::assertDispatched(ScannerScanEventRecorded::class, count($payloads));
});

test('canvassing scanner records duplicate accepted election returns', function (): void {
    $this->post(route('election.canvassing-demo.generate'), [
        'ballot_count' => 25,
        'return_count' => 1,
    ])->assertRedirectToRoute('election.canvassing-demo.show');

    $returnScan = app(ElectionStorage::class)->readJson('runtime/canvassing-demo.json')['return_scan'];

    foreach ($returnScan['payloads'] as $payload) {
        $this->postJson(route('election.canvassing-demo.scanner-events.store'), [
            'payload' => $payload,
        ])->assertOk();
    }

    $this->postJson(route('election.canvassing-demo.scanner-events.store'), [
        'payload' => $returnScan['payloads'][0],
    ])
        ->assertOk()
        ->assertJsonPath('event.status', 'duplicate')
        ->assertJsonPath('state.scan_events.'.count($returnScan['payloads']).'.status', 'duplicate');

    expect(ScannerScanEvent::query()->where('status', 'accepted')->count())->toBe(1)
        ->and(ScannerScanEvent::query()->where('status', 'duplicate')->count())->toBe(1);
});

test('canvassing scanner rejects unsupported payload text', function (): void {
    $this->post(route('election.canvassing-demo.generate'), [
        'ballot_count' => 25,
        'return_count' => 1,
    ])->assertRedirectToRoute('election.canvassing-demo.show');

    $this->postJson(route('election.canvassing-demo.scanner-events.store'), [
        'payload' => 'not-a-truth-payload',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('event.status', 'rejected')
        ->assertJsonPath('state.scan_events.0.status', 'rejected');

    expect(ScannerScanEvent::query()->where('status', 'rejected')->count())->toBe(1);
});

test('canvassing scanner state can be cleared for the station', function (): void {
    $this->post(route('election.canvassing-demo.generate'), [
        'ballot_count' => 25,
        'return_count' => 1,
    ])->assertRedirectToRoute('election.canvassing-demo.show');

    $payload = app(ElectionStorage::class)->readJson('runtime/canvassing-demo.json')['return_scan']['payloads'][0];

    $this->postJson(route('election.canvassing-demo.scanner-events.store'), [
        'payload' => $payload,
    ])->assertOk();

    $this->postJson(route('election.canvassing-demo.scanner-events.reset'))
        ->assertOk()
        ->assertJsonPath('revision', 0)
        ->assertJsonPath('scan_events', []);

    expect(ScannerScanEvent::query()->count())->toBe(0);
});

test('canvassing scanner artisan command records payloads through the same ingestion path', function (): void {
    $this->post(route('election.canvassing-demo.generate'), [
        'ballot_count' => 25,
        'return_count' => 1,
    ])->assertRedirectToRoute('election.canvassing-demo.show');

    $payloads = app(ElectionStorage::class)->readJson('runtime/canvassing-demo.json')['return_scan']['payloads'];

    foreach (array_slice($payloads, 0, -1) as $payload) {
        $this->artisan('election:canvassing-scanner-ingest', [
            '--payload' => $payload,
        ])
            ->expectsOutputToContain('PARTIAL:')
            ->assertSuccessful();
    }

    $this->artisan('election:canvassing-scanner-ingest', [
        '--payload' => $payloads[array_key_last($payloads)],
    ])
        ->expectsOutputToContain('ACCEPTED: Accepted ER 1.')
        ->assertSuccessful();

    expect(ScannerScanEvent::query()->count())->toBe(count($payloads))
        ->and(ScannerScanEvent::query()->where('source', 'scanner_bridge')->count())->toBe(count($payloads))
        ->and(ScannerScanEvent::query()->where('status', 'accepted')->count())->toBe(1);
});

test('canvassing simulator tick ingests generated election returns in scan order', function (): void {
    $this->post(route('election.canvassing-demo.generate'), [
        'ballot_count' => 25,
        'return_count' => 1,
    ])->assertRedirectToRoute('election.canvassing-demo.show');

    $payloads = app(ElectionStorage::class)->readJson('runtime/canvassing-demo.json')['return_scan']['payloads'];

    foreach (array_slice($payloads, 0, -1) as $index => $payload) {
        $this->postJson(route('election.canvassing-demo.simulator.tick'))
            ->assertOk()
            ->assertJsonPath('completed', false)
            ->assertJsonPath('event.status', 'partial')
            ->assertJsonPath('state.current_multipart.received_parts.'.$index, $index + 1);
    }

    $this->postJson(route('election.canvassing-demo.simulator.tick'))
        ->assertOk()
        ->assertJsonPath('completed', false)
        ->assertJsonPath('event.status', 'accepted')
        ->assertJsonPath('state.current_multipart', null);

    $this->postJson(route('election.canvassing-demo.simulator.tick'))
        ->assertOk()
        ->assertJsonPath('completed', true)
        ->assertJsonPath('message', 'All generated election returns have been scanned.');

    expect(ScannerScanEvent::query()->count())->toBe(count($payloads))
        ->and(ScannerScanEvent::query()->where('source', 'public_board_simulator')->count())->toBe(count($payloads))
        ->and(ScannerScanEvent::query()->where('status', 'accepted')->count())->toBe(1);
});
