<?php

namespace App\Election\Core;

use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;

final class ActivityJournal
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly Filesystem $files,
        private readonly CanonicalJson $json,
        private readonly ElectionClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function record(string $eventType, array $payload = []): array
    {
        $this->storage->ensureDirectories();
        $entries = $this->entries();
        $previous = end($entries) ?: null;

        $entry = [
            'sequence' => count($entries) + 1,
            'event_type' => $eventType,
            'occurred_at' => $this->clock->now()->toIso8601String(),
            'payload' => $payload,
            'previous_hash' => is_array($previous) ? $previous['event_hash'] : null,
        ];

        $entry['event_hash'] = $this->json->hash($entry);
        $path = $this->storage->path('journals/activity.jsonl');
        $this->files->append($path, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $entry;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function entries(): array
    {
        $path = $this->storage->path('journals/activity.jsonl');

        if (! $this->files->exists($path)) {
            return [];
        }

        return collect(explode(PHP_EOL, trim($this->files->get($path))))
            ->filter()
            ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 8): array
    {
        return array_slice($this->entries(), -$limit);
    }

    /**
     * @return array{passed: bool, entry_count: int, first_invalid_sequence: int|null, error?: string}
     */
    public function verifyChain(): array
    {
        try {
            $entries = $this->entries();
        } catch (\Throwable $exception) {
            return [
                'passed' => false,
                'entry_count' => 0,
                'first_invalid_sequence' => null,
                'error' => $exception->getMessage(),
            ];
        }

        $previousHash = null;

        foreach ($entries as $index => $entry) {
            $sequence = $index + 1;
            $recordedHash = $entry['event_hash'] ?? null;
            $hashableEntry = $entry;
            unset($hashableEntry['event_hash']);

            if (($entry['sequence'] ?? null) !== $sequence
                || ($entry['previous_hash'] ?? null) !== $previousHash
                || ! is_string($recordedHash)
                || ! hash_equals($recordedHash, $this->json->hash($hashableEntry))) {
                return [
                    'passed' => false,
                    'entry_count' => count($entries),
                    'first_invalid_sequence' => $sequence,
                ];
            }

            $previousHash = $recordedHash;
        }

        return [
            'passed' => true,
            'entry_count' => count($entries),
            'first_invalid_sequence' => null,
        ];
    }
}
