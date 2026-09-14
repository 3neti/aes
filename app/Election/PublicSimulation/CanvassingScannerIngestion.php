<?php

namespace App\Election\PublicSimulation;

use App\Election\Truth\TruthQrEnvelope;
use App\Events\ScannerScanEventRecorded;
use App\Models\ScannerScanEvent;
use Illuminate\Support\Collection;
use RuntimeException;

final class CanvassingScannerIngestion
{
    private const ScanType = 'election_return';

    public function __construct(
        private readonly CanvassingDemoSimulation $simulation,
        private readonly TruthQrEnvelope $envelope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function ingest(string $payload, string $stationId = 'canvassing-demo-city', string $source = 'keyboard_wedge'): array
    {
        $payload = trim($payload);
        $metadata = $this->parseEnvelopeMetadata($payload);
        $matchingReturn = $this->matchingReturn($payload);
        $status = 'rejected';
        $message = 'Payload is not a supported WAES election return QR.';

        if ($payload === '') {
            $message = 'No scan payload received.';
        } elseif (! str_starts_with($payload, 'truth://')) {
            $message = 'Payload is not a truth:// URI.';
        } elseif (($metadata['kind'] ?? 'unknown') === 'unknown') {
            $message = 'Invalid or unsupported ER payload envelope.';
        } elseif ($matchingReturn === null) {
            $message = 'Payload is not in this canvassing sample set.';
        } elseif ($this->hasAcceptedReturn($stationId, (string) $matchingReturn['return_hash'])) {
            $status = 'duplicate';
            $message = "ER {$matchingReturn['sequence']} already accepted.";
        } elseif (($metadata['kind'] ?? null) === 'complete') {
            $status = 'accepted';
            $message = "Accepted ER {$matchingReturn['sequence']}.";
        } else {
            [$status, $message] = $this->multipartStatus($stationId, $metadata, $matchingReturn);
        }

        $event = ScannerScanEvent::create([
            'station_id' => $stationId,
            'scan_type' => self::ScanType,
            'source' => $source,
            'payload' => $payload,
            'payload_hash' => hash('sha256', $payload),
            'status' => $status,
            'group_id' => $metadata['group_id'] ?? null,
            'part_number' => $metadata['part_number'] ?? null,
            'total_parts' => $metadata['total_parts'] ?? null,
            'precinct_id' => $matchingReturn['precinct_id'] ?? null,
            'document_hash' => $matchingReturn['return_hash'] ?? null,
            'message' => $message,
            'metadata' => [
                'envelope' => $metadata,
                'return_sequence' => $matchingReturn['sequence'] ?? null,
                'accepted_ballots' => $matchingReturn['accepted_ballots'] ?? null,
            ],
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        $state = $this->state($stationId);

        ScannerScanEventRecorded::dispatch($event, $state);

        return [
            'event' => $this->eventSummary($event),
            'state' => $state,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function state(string $stationId = 'canvassing-demo-city'): array
    {
        /** @var Collection<int, ScannerScanEvent> $events */
        $events = ScannerScanEvent::query()
            ->where('station_id', $stationId)
            ->where('scan_type', self::ScanType)
            ->orderBy('id')
            ->get();

        $acceptedReturnHashes = $events
            ->where('status', 'accepted')
            ->pluck('document_hash')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $latestAcceptedHash = $events
            ->where('status', 'accepted')
            ->pluck('document_hash')
            ->filter()
            ->last();

        return [
            'station_id' => $stationId,
            'revision' => $events->last()?->id ?? 0,
            'accepted_return_hashes' => $acceptedReturnHashes,
            'latest_accepted_return_hash' => $latestAcceptedHash,
            'scan_events' => $events
                ->map(fn (ScannerScanEvent $event): array => $this->eventSummary($event))
                ->values()
                ->all(),
            'current_multipart' => $this->currentMultipart($events),
            'latest_message' => $events->last()?->message ?? 'Ready for scanner input.',
            'latest_status' => $events->last()?->status ?? 'ready',
        ];
    }

    public function reset(string $stationId = 'canvassing-demo-city'): void
    {
        ScannerScanEvent::query()
            ->where('station_id', $stationId)
            ->where('scan_type', self::ScanType)
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function simulateNext(string $stationId = 'canvassing-demo-city'): array
    {
        $state = $this->state($stationId);
        $scan = $this->nextSimulationScan($state);

        if ($scan === null) {
            return [
                'completed' => true,
                'message' => 'All generated election returns have been scanned.',
                'state' => $state,
            ];
        }

        $result = $this->ingest((string) $scan['payload'], $stationId, 'public_board_simulator');

        return [
            'completed' => false,
            'message' => $result['state']['latest_message'] ?? 'Simulator scan recorded.',
            'event' => $result['event'],
            'state' => $result['state'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseEnvelopeMetadata(string $payload): array
    {
        try {
            $metadata = $this->envelope->inspect($payload);
        } catch (RuntimeException) {
            return ['kind' => 'unknown'];
        }

        if (($metadata['document_type'] ?? null) !== TruthQrEnvelope::ElectionReturnType) {
            return ['kind' => 'unknown'];
        }

        if (($metadata['part_kind'] ?? null) === 'complete') {
            return [
                'kind' => 'complete',
                'total_parts' => 1,
            ];
        }

        if (($metadata['part_kind'] ?? null) !== 'fragment') {
            return ['kind' => 'unknown'];
        }

        return [
            'kind' => 'fragment',
            'group_id' => $metadata['group_id'],
            'part_number' => $metadata['part_number'],
            'total_parts' => $metadata['total_parts'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchingReturn(string $payload): ?array
    {
        return collect($this->simulation->summary()['scanner']['returns'] ?? [])
            ->first(fn (mixed $scan): bool => is_array($scan) && in_array($payload, (array) ($scan['payloads'] ?? []), true));
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{payload: string}|null
     */
    private function nextSimulationScan(array $state): ?array
    {
        $returns = collect($this->simulation->summary()['scanner']['returns'] ?? [])
            ->filter(fn (mixed $scan): bool => is_array($scan))
            ->values();

        if ($returns->isEmpty()) {
            return null;
        }

        $currentMultipart = is_array($state['current_multipart'] ?? null)
            ? $state['current_multipart']
            : null;

        if ($currentMultipart !== null) {
            $returnHash = (string) ($currentMultipart['return_hash'] ?? '');
            $receivedParts = collect((array) ($currentMultipart['received_parts'] ?? []))
                ->map(fn (mixed $part): int => (int) $part)
                ->all();

            $activeReturn = $returns->first(fn (array $scan): bool => ($scan['return_hash'] ?? null) === $returnHash);

            if (is_array($activeReturn)) {
                $nextPayload = collect((array) ($activeReturn['payloads'] ?? []))
                    ->first(function (mixed $payload) use ($receivedParts): bool {
                        if (! is_string($payload)) {
                            return false;
                        }

                        $metadata = $this->parseEnvelopeMetadata($payload);

                        return ! in_array((int) ($metadata['part_number'] ?? 1), $receivedParts, true);
                    });

                if (is_string($nextPayload)) {
                    return ['payload' => $nextPayload];
                }
            }
        }

        $acceptedHashes = collect((array) ($state['accepted_return_hashes'] ?? []))
            ->map(fn (mixed $hash): string => (string) $hash)
            ->all();

        $nextReturn = $returns->first(fn (array $scan): bool => ! in_array((string) ($scan['return_hash'] ?? ''), $acceptedHashes, true));
        $payload = is_array($nextReturn) ? ((array) ($nextReturn['payloads'] ?? []))[0] ?? null : null;

        return is_string($payload) ? ['payload' => $payload] : null;
    }

    private function hasAcceptedReturn(string $stationId, string $returnHash): bool
    {
        return ScannerScanEvent::query()
            ->where('station_id', $stationId)
            ->where('scan_type', self::ScanType)
            ->where('status', 'accepted')
            ->where('document_hash', $returnHash)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $matchingReturn
     * @return array{0: string, 1: string}
     */
    private function multipartStatus(string $stationId, array $metadata, array $matchingReturn): array
    {
        $existingParts = ScannerScanEvent::query()
            ->where('station_id', $stationId)
            ->where('scan_type', self::ScanType)
            ->where('group_id', $metadata['group_id'])
            ->where('document_hash', $matchingReturn['return_hash'])
            ->whereIn('status', ['partial', 'accepted'])
            ->pluck('part_number')
            ->filter()
            ->map(fn (mixed $partNumber): int => (int) $partNumber)
            ->unique()
            ->values();

        if ($existingParts->contains((int) $metadata['part_number'])) {
            return [
                'duplicate',
                "Part {$metadata['part_number']} of {$metadata['total_parts']} already scanned.",
            ];
        }

        $receivedParts = $existingParts->push((int) $metadata['part_number'])->unique()->count();

        if ($receivedParts >= (int) $metadata['total_parts']) {
            return [
                'accepted',
                "Accepted ER {$matchingReturn['sequence']}.",
            ];
        }

        return [
            'partial',
            "ER {$matchingReturn['sequence']}: {$receivedParts} of {$metadata['total_parts']} parts.",
        ];
    }

    /**
     * @param  Collection<int, ScannerScanEvent>  $events
     * @return array<string, mixed>|null
     */
    private function currentMultipart(Collection $events): ?array
    {
        $latestPartial = $events
            ->where('status', 'partial')
            ->reverse()
            ->first();

        if (! $latestPartial instanceof ScannerScanEvent || $latestPartial->group_id === null) {
            return null;
        }

        if (
            $events
                ->where('group_id', $latestPartial->group_id)
                ->where('document_hash', $latestPartial->document_hash)
                ->where('status', 'accepted')
                ->isNotEmpty()
        ) {
            return null;
        }

        $receivedParts = $events
            ->where('group_id', $latestPartial->group_id)
            ->where('document_hash', $latestPartial->document_hash)
            ->whereIn('status', ['partial', 'accepted'])
            ->pluck('part_number')
            ->filter()
            ->map(fn (mixed $partNumber): int => (int) $partNumber)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'group_id' => $latestPartial->group_id,
            'total_parts' => $latestPartial->total_parts,
            'received_parts' => $receivedParts,
            'precinct_id' => $latestPartial->precinct_id,
            'return_hash' => $latestPartial->document_hash,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventSummary(ScannerScanEvent $event): array
    {
        $sequence = $event->metadata['return_sequence'] ?? null;
        $partLabel = $event->total_parts !== null && $event->total_parts > 1
            ? "Part {$event->part_number} of {$event->total_parts}"
            : 'Single QR document';

        return [
            'id' => (string) $event->id,
            'title' => $this->eventTitle($event, $sequence),
            'subtitle' => $event->precinct_id,
            'meta' => "{$this->sourceLabel($event->source)} · {$partLabel} · {$event->message}",
            'hash' => $event->document_hash ?? $event->group_id ?? $event->payload_hash,
            'status' => $event->status,
            'document_hash' => $event->document_hash,
        ];
    }

    private function eventTitle(ScannerScanEvent $event, mixed $sequence): string
    {
        if ($event->status === 'accepted' && $sequence !== null) {
            return "ER {$sequence} accepted";
        }

        if ($event->status === 'partial') {
            return 'ER QR set';
        }

        if ($event->status === 'duplicate') {
            return 'Duplicate ER QR';
        }

        return 'Rejected ER QR';
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'demo_feed' => 'Demo feed',
            'browser_paste' => 'Browser paste',
            'keyboard_wedge' => 'Hardware scan',
            'scanner_bridge' => 'Scanner bridge',
            'public_board_simulator' => 'Public board simulator',
            default => str($source)->replace('_', ' ')->title()->toString(),
        };
    }
}
