<?php

namespace App\Election\PublicSimulation;

use App\Election\Counting\TallyPresentation;
use App\Election\Documents\DocumentProfileRegistry;
use App\Election\Support\ElectionStorage;
use App\Election\Truth\TruthQrEnvelope;
use App\Election\Voting\BallotQrPayload;
use App\Events\ScannerScanEventRecorded;
use App\Models\ScannerScanEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

final class RoleDemoPrecinctTallyIngestion
{
    private const ScanType = 'official_ballot';

    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly TruthQrEnvelope $envelope,
        private readonly BallotQrPayload $qrPayload,
        private readonly TallyPresentation $presentation,
        private readonly DocumentProfileRegistry $documents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function ingest(string $payload, string $stationId = 'role-demo-precinct', string $source = 'keyboard_wedge'): array
    {
        $payload = trim($payload);
        $decoded = null;
        $metadata = ['kind' => 'unknown'];
        $documentHash = null;
        $status = 'rejected';
        $message = 'Payload is not a supported WAES ballot QR.';

        try {
            $metadata = $this->ballotMetadata($payload);
            $decoded = $this->decode($payload);
            $this->guardPrecinct($decoded);
            $documentHash = (string) ($decoded['payload_hash'] ?? hash('sha256', $payload));

            if ($this->hasAcceptedBallot($stationId, $documentHash)) {
                $status = 'duplicate';
                $message = 'Ballot already accepted.';
            } else {
                $status = 'accepted';
                $message = 'Accepted ballot.';
            }
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        $event = ScannerScanEvent::create([
            'station_id' => $stationId,
            'scan_type' => self::ScanType,
            'source' => $source,
            'payload' => $payload,
            'payload_hash' => hash('sha256', $payload),
            'status' => $status,
            'group_id' => $metadata['group_id'] ?? null,
            'part_number' => 1,
            'total_parts' => 1,
            'precinct_id' => $decoded['precinct_id'] ?? null,
            'document_hash' => $documentHash,
            'message' => $message,
            'metadata' => [
                'envelope' => $metadata,
                'ballot' => $decoded,
                'tally' => is_array($decoded) ? $this->deltaTally((array) ($decoded['selections'] ?? [])) : [],
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
    public function state(string $stationId = 'role-demo-precinct'): array
    {
        /** @var Collection<int, ScannerScanEvent> $events */
        $events = ScannerScanEvent::query()
            ->where('station_id', $stationId)
            ->where('scan_type', self::ScanType)
            ->orderBy('id')
            ->get();

        $acceptedEvents = $events->where('status', 'accepted');
        $tally = $this->emptyTally();

        foreach ($acceptedEvents as $event) {
            $this->addTally($tally, (array) ($event->metadata['tally'] ?? []));
        }

        return [
            'station_id' => $stationId,
            'revision' => $events->last()?->id ?? 0,
            'accepted_ballot_hashes' => $acceptedEvents
                ->pluck('document_hash')
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'latest_accepted_ballot_hash' => $acceptedEvents
                ->pluck('document_hash')
                ->filter()
                ->last(),
            'accepted_count' => $acceptedEvents->count(),
            'display_tally' => $this->presentation->displayTally($tally),
            'tally' => $tally,
            'scan_events' => $events
                ->map(fn (ScannerScanEvent $event): array => $this->eventSummary($event))
                ->values()
                ->all(),
            'accepted_ballots' => $acceptedEvents
                ->values()
                ->map(fn (ScannerScanEvent $event, int $index): array => $this->ballotDocument($event, $index + 1))
                ->all(),
            'latest_message' => $events->last()?->message ?? 'Ready for scanner input.',
            'latest_status' => $events->last()?->status ?? 'ready',
        ];
    }

    public function reset(string $stationId = 'role-demo-precinct'): void
    {
        ScannerScanEvent::query()
            ->where('station_id', $stationId)
            ->where('scan_type', self::ScanType)
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function simulateNext(string $stationId = 'role-demo-precinct'): array
    {
        $state = $this->state($stationId);
        $acceptedHashes = collect((array) ($state['accepted_ballot_hashes'] ?? []))
            ->map(fn (mixed $hash): string => (string) $hash)
            ->all();

        $nextBallot = collect($this->loadedBallots())
            ->first(fn (array $ballot): bool => ! in_array((string) ($ballot['payload_hash'] ?? ''), $acceptedHashes, true));

        if (! is_array($nextBallot)) {
            return [
                'completed' => true,
                'message' => 'All loaded ballots have been scanned.',
                'state' => $state,
            ];
        }

        $result = $this->ingest((string) $nextBallot['payload'], $stationId, 'demo_feed');

        return [
            'completed' => false,
            'message' => $result['state']['latest_message'] ?? 'Simulator scan recorded.',
            'event' => $result['event'],
            'state' => $result['state'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadedBallots(): array
    {
        return collect($this->storage->files('counting/sealed'))
            ->map(function (string $path, int $index): array {
                $record = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                $payload = Crypt::decryptString((string) ($record['encrypted_payload'] ?? ''));
                $decoded = $this->decode($payload);

                return [
                    'sequence' => $index + 1,
                    'payload' => $payload,
                    'canonical_payload' => $this->envelope->canonicalPayload($payload),
                    'payload_hash' => $decoded['payload_hash'] ?? hash('sha256', $payload),
                    'paper_ballot_serial' => $decoded['paper_ballot_serial'] ?? null,
                    'precinct_id' => $decoded['precinct_id'] ?? null,
                    'source' => 'loaded demo ballot',
                    'selections' => $decoded['selections'] ?? [],
                    'this_ballot_tally' => $this->deltaTally((array) ($decoded['selections'] ?? [])),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function ballotMetadata(string $payload): array
    {
        if ($payload === '') {
            throw new RuntimeException('No scan payload received.');
        }

        $metadata = $this->envelope->inspect($payload);

        if (($metadata['document_type'] ?? null) !== TruthQrEnvelope::BallotType) {
            throw new RuntimeException('Payload is not a WAES ballot QR.');
        }

        return [
            'kind' => 'complete',
            'group_id' => $metadata['group_id'],
            'total_parts' => 1,
            'canonical_payload' => $metadata['canonical_payload'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $payload): array
    {
        return $this->qrPayload->decode($this->envelope->canonicalPayload($payload));
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function guardPrecinct(array $decoded): void
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');

        foreach (['election_id', 'precinct_id', 'ballot_style_id', 'mapping_hash'] as $key) {
            if (($decoded[$key] ?? null) !== ($configuration[$key] ?? null)) {
                throw new RuntimeException(str($key)->replace('_', ' ')->ucfirst()->append(' mismatch.')->toString());
            }
        }

        $expectedProfile = $this->documents->ballotReference();
        $profile = $decoded['document_profile'] ?? null;

        if (is_array($profile) && $profile !== $expectedProfile) {
            throw new RuntimeException('Ballot document profile mismatch.');
        }
    }

    private function hasAcceptedBallot(string $stationId, string $documentHash): bool
    {
        return ScannerScanEvent::query()
            ->where('station_id', $stationId)
            ->where('scan_type', self::ScanType)
            ->where('status', 'accepted')
            ->where('document_hash', $documentHash)
            ->exists();
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function emptyTally(): array
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');

        return collect($configuration['contests'] ?? [])
            ->filter(fn (mixed $contest): bool => is_array($contest))
            ->mapWithKeys(fn (array $contest): array => [
                (string) ($contest['id'] ?? '') => collect($contest['candidates'] ?? [])
                    ->filter(fn (mixed $candidate): bool => is_array($candidate))
                    ->mapWithKeys(fn (array $candidate): array => [(string) ($candidate['id'] ?? '') => 0])
                    ->all(),
            ])
            ->all();
    }

    /**
     * @param  array<string, array<int, string>>  $selections
     * @return array<string, array<string, int>>
     */
    private function deltaTally(array $selections): array
    {
        $tally = [];

        foreach ($selections as $contestId => $candidateIds) {
            $tally[$contestId] ??= [];

            foreach ((array) $candidateIds as $candidateId) {
                $candidateId = (string) $candidateId;
                $tally[$contestId][$candidateId] = ($tally[$contestId][$candidateId] ?? 0) + 1;
            }
        }

        return $tally;
    }

    /**
     * @param  array<string, array<string, int>>  $tally
     * @param  array<string, array<string, int>>  $delta
     */
    private function addTally(array &$tally, array $delta): void
    {
        foreach ($delta as $contestId => $candidateVotes) {
            $tally[$contestId] ??= [];

            foreach ($candidateVotes as $candidateId => $votes) {
                $tally[$contestId][$candidateId] = ($tally[$contestId][$candidateId] ?? 0) + (int) $votes;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ballotDocument(ScannerScanEvent $event, int $sequence): array
    {
        $ballot = (array) ($event->metadata['ballot'] ?? []);

        return [
            'type' => 'official-ballot',
            'sequence' => $sequence,
            'source' => $event->source,
            'ballot_id' => $ballot['ballot_id'] ?? $event->document_hash,
            'precinct_id' => $ballot['precinct_id'] ?? $event->precinct_id,
            'paper_ballot_serial' => $ballot['paper_ballot_serial'] ?? null,
            'payload' => $event->payload,
            'canonical_payload' => $event->metadata['envelope']['canonical_payload'] ?? null,
            'payload_hash' => $event->document_hash ?? $event->payload_hash,
            'document_profile' => $ballot['document_profile'] ?? null,
            'selections' => $ballot['selections'] ?? [],
            'this_ballot_tally' => $event->metadata['tally'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventSummary(ScannerScanEvent $event): array
    {
        return [
            'id' => (string) $event->id,
            'title' => $this->eventTitle($event),
            'subtitle' => $event->precinct_id,
            'meta' => "{$this->sourceLabel($event->source)} · single QR document · {$event->message}",
            'hash' => $event->document_hash ?? $event->payload_hash,
            'status' => $event->status,
            'document_hash' => $event->document_hash,
        ];
    }

    private function eventTitle(ScannerScanEvent $event): string
    {
        if ($event->status === 'accepted') {
            return 'Ballot accepted';
        }

        if ($event->status === 'duplicate') {
            return 'Duplicate ballot QR';
        }

        return 'Rejected ballot QR';
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'demo_feed' => 'Demo feed',
            'browser_paste' => 'Browser paste',
            'keyboard_wedge' => 'Hardware scan',
            'scanner_bridge' => 'Scanner bridge',
            default => str($source)->replace('_', ' ')->title()->toString(),
        };
    }
}
