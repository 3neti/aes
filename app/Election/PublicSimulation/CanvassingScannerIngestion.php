<?php

namespace App\Election\PublicSimulation;

use App\Election\Documents\DocumentProfileRegistry;
use App\Election\Returns\ElectionReturnQrPayload;
use App\Election\Support\ElectionStorage;
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
        private readonly ElectionReturnQrPayload $qrPayload,
        private readonly ElectionStorage $storage,
        private readonly DocumentProfileRegistry $documents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function ingest(string $payload, string $stationId = 'canvassing-demo-city', string $source = 'keyboard_wedge'): array
    {
        $payload = trim($payload);
        $metadata = $this->parseEnvelopeMetadata($payload);
        $matchingReturn = $this->matchingReturn($payload) ?? $this->decodedCompleteReturn($payload);
        $status = 'rejected';
        $message = 'Payload is not a supported WAES election return QR.';
        $documentHash = $matchingReturn['document_hash'] ?? $matchingReturn['payload_hash'] ?? $matchingReturn['return_hash'] ?? null;

        if ($payload === '') {
            $message = 'No scan payload received.';
        } elseif (! str_starts_with($payload, 'truth://')) {
            $message = 'Payload is not a truth:// URI.';
        } elseif (($metadata['kind'] ?? 'unknown') === 'unknown') {
            $message = 'Invalid or unsupported ER payload envelope.';
        } elseif ($matchingReturn === null && ($metadata['kind'] ?? null) !== 'fragment') {
            $message = 'Payload is not in this canvassing sample set.';
        } elseif ($matchingReturn !== null && $this->hasAcceptedDocument($stationId, (string) $documentHash)) {
            $status = 'duplicate';
            $message = $this->acceptedMessage($matchingReturn, true);
        } elseif (($metadata['kind'] ?? null) === 'complete') {
            $status = 'accepted';
            $message = $this->acceptedMessage($matchingReturn);
        } else {
            [$status, $message] = $this->multipartStatus($stationId, $metadata, $matchingReturn, $payload);
            $matchingReturn = $status === 'accepted'
                ? $this->completedMultipartReturn($stationId, $metadata, $payload)
                : $matchingReturn;
            $documentHash = $matchingReturn['document_hash'] ?? $matchingReturn['payload_hash'] ?? $matchingReturn['return_hash'] ?? $metadata['group_id'] ?? null;
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
            'document_hash' => $documentHash,
            'message' => $message,
            'metadata' => [
                'envelope' => $metadata,
                'return_sequence' => $matchingReturn['sequence'] ?? null,
                'return_scope' => $matchingReturn['return_scope'] ?? null,
                'return_hash' => $matchingReturn['return_hash'] ?? null,
                'payload_hash' => $matchingReturn['payload_hash'] ?? null,
                'accepted_ballots' => $matchingReturn['accepted_ballots'] ?? null,
                'return' => $matchingReturn,
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

        $acceptedEvents = $events->where('status', 'accepted');
        $acceptedReturnHashes = $acceptedEvents
            ->map(fn (ScannerScanEvent $event): ?string => $this->officialReturnHash($event))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $latestAcceptedHash = $acceptedEvents
            ->map(fn (ScannerScanEvent $event): ?string => $this->officialReturnHash($event))
            ->filter()
            ->last();

        $acceptedReturns = $acceptedEvents
            ->map(fn (ScannerScanEvent $event): array => (array) ($event->metadata['return'] ?? []))
            ->filter(fn (array $return): bool => $return !== [])
            ->values()
            ->all();

        return [
            'station_id' => $stationId,
            'revision' => $events->last()?->id ?? 0,
            'accepted_return_hashes' => $acceptedReturnHashes,
            'latest_accepted_return_hash' => $latestAcceptedHash,
            'accepted_returns' => $acceptedReturns,
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

    private function officialReturnHash(ScannerScanEvent $event): ?string
    {
        $returnHash = $event->metadata['return_hash'] ?? null;

        return is_string($returnHash) && $returnHash !== ''
            ? $returnHash
            : $event->document_hash;
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
                'group_id' => $metadata['group_id'] ?? null,
                'part_number' => 1,
                'total_parts' => 1,
                'canonical_payload' => $metadata['canonical_payload'] ?? null,
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
        $matchingReturn = collect($this->simulation->summary()['scanner']['returns'] ?? [])
            ->first(fn (mixed $scan): bool => is_array($scan) && in_array($payload, (array) ($scan['payloads'] ?? []), true));

        return is_array($matchingReturn) ? $this->normalizeReturn($matchingReturn) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodedCompleteReturn(string $payload): ?array
    {
        try {
            $canonicalPayload = $this->envelope->canonicalPayload($payload);
            $decoded = $this->qrPayload->decode($canonicalPayload);
            $this->guardReturn($decoded);

            return $this->normalizeReturn([
                'sequence' => null,
                'source' => 'printed election return QR',
                'precinct_id' => (string) ($decoded['precinct_id'] ?? ''),
                'return_scope' => (string) ($decoded['return_scope'] ?? 'combined'),
                'payloads' => [$payload],
                'canonical_payload' => $canonicalPayload,
                'payload_hash' => (string) ($decoded['payload_hash'] ?? hash('sha256', $canonicalPayload)),
                'return_hash' => (string) ($decoded['return_hash'] ?? ''),
                'document_profile' => $decoded['document_profile'] ?? null,
                'accepted_ballots' => (int) ($decoded['accepted_ballots'] ?? 0),
                'rejected_ballots' => (int) ($decoded['rejected_ballots'] ?? 0),
                'tally' => $decoded['tally'] ?? [],
            ]);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function guardReturn(array $decoded): void
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');

        foreach (['election_id', 'mapping_hash'] as $key) {
            if (($decoded[$key] ?? null) !== ($configuration[$key] ?? null)) {
                throw new RuntimeException(str($key)->replace('_', ' ')->ucfirst()->append(' mismatch.')->toString());
            }
        }

        $expectedProfile = $this->documents->electionReturnReference();
        $profile = $decoded['document_profile'] ?? null;

        if (is_array($profile) && $profile !== $expectedProfile) {
            throw new RuntimeException('Election return document profile mismatch.');
        }
    }

    /**
     * @param  array<string, mixed>  $return
     * @return array<string, mixed>
     */
    private function normalizeReturn(array $return): array
    {
        $payloadHash = (string) ($return['payload_hash'] ?? $return['return_hash'] ?? '');

        return [
            ...$return,
            'sequence' => $return['sequence'] ?? null,
            'return_scope' => (string) ($return['return_scope'] ?? 'combined'),
            'payload_hash' => $payloadHash,
            'document_hash' => $payloadHash !== '' ? $payloadHash : (string) ($return['return_hash'] ?? ''),
        ];
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

    private function hasAcceptedDocument(string $stationId, string $documentHash): bool
    {
        if ($documentHash === '') {
            return false;
        }

        return ScannerScanEvent::query()
            ->where('station_id', $stationId)
            ->where('scan_type', self::ScanType)
            ->where('status', 'accepted')
            ->where('document_hash', $documentHash)
            ->exists();
    }

    /**
     * @param  array<string, mixed>|null  $matchingReturn
     * @return array{0: string, 1: string}
     */
    private function multipartStatus(string $stationId, array $metadata, ?array $matchingReturn, string $currentPayload): array
    {
        $groupId = (string) ($metadata['group_id'] ?? '');

        if ($groupId === '') {
            return ['rejected', 'Election return QR fragment metadata is malformed.'];
        }

        $existingParts = ScannerScanEvent::query()
            ->where('station_id', $stationId)
            ->where('scan_type', self::ScanType)
            ->where('group_id', $groupId)
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

        if ($receivedParts < (int) $metadata['total_parts']) {
            return [
                'partial',
                "ER QR set: {$receivedParts} of {$metadata['total_parts']} parts.",
            ];
        }

        $completedReturn = $matchingReturn ?? $this->completedMultipartReturn($stationId, $metadata, $currentPayload);

        if ($completedReturn === null) {
            return ['rejected', 'Completed ER QR set is not part of this canvassing session.'];
        }

        if ($this->hasAcceptedDocument($stationId, (string) ($completedReturn['document_hash'] ?? ''))) {
            return ['duplicate', $this->acceptedMessage($completedReturn, true)];
        }

        return ['accepted', $this->acceptedMessage($completedReturn)];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private function completedMultipartReturn(string $stationId, array $metadata, ?string $currentPayload = null): ?array
    {
        $payloads = ScannerScanEvent::query()
            ->where('station_id', $stationId)
            ->where('scan_type', self::ScanType)
            ->where('group_id', $metadata['group_id'] ?? null)
            ->whereIn('status', ['partial', 'accepted'])
            ->orderBy('id')
            ->pluck('payload')
            ->filter()
            ->values()
            ->all();

        if ($currentPayload !== null) {
            $payloads[] = $currentPayload;
        }

        try {
            $canonicalPayload = $this->envelope->reassemble(array_map('strval', $payloads));
            $decoded = $this->qrPayload->decode($canonicalPayload);
            $this->guardReturn($decoded);

            return $this->normalizeReturn([
                'sequence' => null,
                'source' => 'printed election return QR',
                'precinct_id' => (string) ($decoded['precinct_id'] ?? ''),
                'return_scope' => (string) ($decoded['return_scope'] ?? 'combined'),
                'payloads' => $payloads,
                'canonical_payload' => $canonicalPayload,
                'payload_hash' => (string) ($decoded['payload_hash'] ?? hash('sha256', $canonicalPayload)),
                'return_hash' => (string) ($decoded['return_hash'] ?? ''),
                'document_profile' => $decoded['document_profile'] ?? null,
                'accepted_ballots' => (int) ($decoded['accepted_ballots'] ?? 0),
                'rejected_ballots' => (int) ($decoded['rejected_ballots'] ?? 0),
                'tally' => $decoded['tally'] ?? [],
            ]);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $matchingReturn
     */
    private function acceptedMessage(array $matchingReturn, bool $duplicate = false): string
    {
        $scope = str((string) ($matchingReturn['return_scope'] ?? 'combined'))->replace('_', ' ')->title()->toString();
        $sequence = $matchingReturn['sequence'] ?? null;
        $label = $sequence === null ? "{$scope} ER" : "ER {$sequence}";

        return $duplicate ? "{$label} already accepted." : "Accepted {$label}.";
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
                ->where('status', 'accepted')
                ->isNotEmpty()
        ) {
            return null;
        }

        $receivedParts = $events
            ->where('group_id', $latestPartial->group_id)
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
            'return_hash' => $latestPartial->metadata['return_hash'] ?? $latestPartial->document_hash,
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
            'scanned_at' => $event->received_at?->toJSON(),
            'meta' => "{$this->sourceLabel($event->source)} · {$partLabel} · {$event->message}",
            'hash' => $event->document_hash ?? $event->group_id ?? $event->payload_hash,
            'status' => $event->status,
            'document_hash' => $event->document_hash,
        ];
    }

    private function eventTitle(ScannerScanEvent $event, mixed $sequence): string
    {
        if ($event->status === 'accepted') {
            return $sequence !== null ? "ER {$sequence} accepted" : 'ER accepted';
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
