<?php

namespace App\Election\Voting;

use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use RuntimeException;

final class PaperBallotLedger
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
    ) {}

    public function nextSerial(): ?string
    {
        $setup = $this->storage->readJson('runtime/precinct-setup.json');

        if ($setup === []) {
            return null;
        }

        $start = (int) ($setup['inventory']['ballot_stock_start'] ?? 0);
        $end = (int) ($setup['inventory']['ballot_stock_end'] ?? -1);
        $issued = $this->issuedCount();
        $number = $start + $issued;

        if ($number > $end) {
            throw new RuntimeException('The configured paper ballot stock is exhausted.');
        }

        return sprintf('%s-%06d', (string) ($setup['precinct_id'] ?? 'PRECINCT'), $number);
    }

    public function nextRequiredSerial(string $precinctId): string
    {
        return $this->nextSerial() ?? sprintf(
            '%s-DEMO-%06d',
            $this->serialPrefix($precinctId),
            $this->issuedCount() + 1,
        );
    }

    public function recordIssued(string $serial, string $ballotId, string $payloadHash): void
    {
        $this->append('paper_ballot.issued', [
            'paper_ballot_serial' => $serial,
            'ballot_id' => $ballotId,
            'payload_hash' => $payloadHash,
        ]);
        $this->storage->writeJson("paper-ballot-ledger/index/{$payloadHash}.json", [
            'schema_version' => 'paper-ballot-ledger-payload-index-1',
            'paper_ballot_serial' => $serial,
            'ballot_id' => $ballotId,
            'payload_hash' => $payloadHash,
        ]);
    }

    public function recordPrinted(string $payloadHash): void
    {
        $this->recordForPayload('paper_ballot.printed', $payloadHash);
    }

    public function recordSpoiled(string $payloadHash): void
    {
        $this->recordForPayload('paper_ballot.spoiled', $payloadHash);
    }

    public function recordDeposited(string $payloadHash): void
    {
        $this->recordForPayload('paper_ballot.deposited', $payloadHash);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $setup = $this->storage->readJson('runtime/precinct-setup.json');
        $events = $this->events();
        $issued = collect($events)->where('event_type', 'paper_ballot.issued');
        $serialsByHash = $issued->pluck('payload.paper_ballot_serial', 'payload.payload_hash');
        $statusBySerial = [];

        foreach ($events as $event) {
            $serial = $event['payload']['paper_ballot_serial'] ?? $serialsByHash[$event['payload']['payload_hash'] ?? ''] ?? null;

            if (is_string($serial)) {
                $statusBySerial[$serial] = $event['event_type'];
            }
        }

        $stockCount = (int) ($setup['inventory']['ballot_stock_count'] ?? 0);

        return [
            'schema_version' => 'paper-ballot-accounting-1',
            'total_stock' => $stockCount,
            'issued' => $issued->count(),
            'printed' => collect($events)->where('event_type', 'paper_ballot.printed')->count(),
            'spoiled' => collect($statusBySerial)->filter(fn (string $status): bool => $status === 'paper_ballot.spoiled')->count(),
            'deposited' => collect($statusBySerial)->filter(fn (string $status): bool => $status === 'paper_ballot.deposited')->count(),
            'unused' => max(0, $stockCount - $issued->count()),
            'event_count' => count($events),
            'balanced' => $issued->count() === collect($statusBySerial)
                ->filter(fn (string $status): bool => in_array($status, ['paper_ballot.printed', 'paper_ballot.spoiled', 'paper_ballot.deposited'], true))
                ->count(),
        ];
    }

    /**
     * @return array{passed: bool, event_count: int, first_invalid_sequence: int|null, error?: string}
     */
    public function verifyChain(): array
    {
        try {
            $events = $this->events();
        } catch (\Throwable $exception) {
            return [
                'passed' => false,
                'event_count' => 0,
                'first_invalid_sequence' => null,
                'error' => $exception->getMessage(),
            ];
        }

        $previousHash = str_repeat('0', 64);

        foreach ($events as $index => $event) {
            $sequence = $index + 1;
            $recordedHash = $event['event_hash'] ?? null;
            $hashableEvent = $event;
            unset($hashableEvent['event_hash']);

            if (($event['sequence'] ?? null) !== $sequence
                || ($event['previous_hash'] ?? null) !== $previousHash
                || ! is_string($recordedHash)
                || ! hash_equals($recordedHash, $this->json->hash($hashableEvent))) {
                return [
                    'passed' => false,
                    'event_count' => count($events),
                    'first_invalid_sequence' => $sequence,
                ];
            }

            $previousHash = $recordedHash;
        }

        return [
            'passed' => true,
            'event_count' => count($events),
            'first_invalid_sequence' => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function events(): array
    {
        return collect($this->storage->files('paper-ballot-ledger'))
            ->map(fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
            ->filter(fn (array $record): bool => ($record['schema_version'] ?? null) === 'paper-ballot-ledger-event-1')
            ->sortBy('sequence')
            ->values()
            ->all();
    }

    private function recordForPayload(string $eventType, string $payloadHash): void
    {
        $issue = $this->storage->readJson("paper-ballot-ledger/index/{$payloadHash}.json");

        if ($issue === []) {
            $issue = collect($this->events())
                ->first(fn (array $event): bool => ($event['event_type'] ?? null) === 'paper_ballot.issued'
                    && ($event['payload']['payload_hash'] ?? null) === $payloadHash);
        }

        if (! is_array($issue) || $issue === []) {
            return;
        }

        $this->append($eventType, [
            'paper_ballot_serial' => $issue['paper_ballot_serial'] ?? $issue['payload']['paper_ballot_serial'],
            'ballot_id' => $issue['ballot_id'] ?? $issue['payload']['ballot_id'],
            'payload_hash' => $payloadHash,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function append(string $eventType, array $payload): void
    {
        $state = $this->ledgerState();
        $sequence = (int) ($state['sequence'] ?? 0) + 1;
        $previousHash = (string) ($state['event_hash'] ?? str_repeat('0', 64));
        $event = [
            'schema_version' => 'paper-ballot-ledger-event-1',
            'sequence' => $sequence,
            'event_type' => $eventType,
            'occurred_at' => $this->clock->now()->toIso8601String(),
            'payload' => $payload,
            'previous_hash' => $previousHash,
        ];
        $event['event_hash'] = $this->json->hash($event);
        $this->storage->writeJson(
            sprintf('paper-ballot-ledger/%06d-%s.json', $event['sequence'], str_replace('.', '-', $eventType)),
            $event,
        );
        $this->storage->writeJson('paper-ballot-ledger/state/current.json', [
            'schema_version' => 'paper-ballot-ledger-state-1',
            'sequence' => $sequence,
            'event_hash' => $event['event_hash'],
            'issued_count' => (int) ($state['issued_count'] ?? 0) + ($eventType === 'paper_ballot.issued' ? 1 : 0),
            'updated_at' => $event['occurred_at'],
        ]);
    }

    private function issuedCount(): int
    {
        $state = $this->storage->readJson('paper-ballot-ledger/state/current.json');

        if (isset($state['issued_count'])) {
            return (int) $state['issued_count'];
        }

        return collect($this->events())->where('event_type', 'paper_ballot.issued')->count();
    }

    /**
     * @return array{sequence?: int, event_hash?: string, issued_count?: int}
     */
    private function ledgerState(): array
    {
        $state = $this->storage->readJson('paper-ballot-ledger/state/current.json');

        if ($state !== []) {
            return $state;
        }

        $events = $this->events();

        if ($events === []) {
            return [
                'sequence' => 0,
                'event_hash' => str_repeat('0', 64),
                'issued_count' => 0,
            ];
        }

        $last = end($events);

        return [
            'sequence' => count($events),
            'event_hash' => (string) $last['event_hash'],
            'issued_count' => collect($events)->where('event_type', 'paper_ballot.issued')->count(),
        ];
    }

    private function serialPrefix(string $precinctId): string
    {
        $prefix = strtoupper((string) preg_replace('/[^A-Z0-9]+/i', '-', trim($precinctId)));
        $prefix = trim($prefix, '-');

        return $prefix !== '' ? $prefix : 'PRECINCT';
    }
}
