<?php

namespace App\Election\Core;

use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionOperationLock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class ActivityJournal
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly Filesystem $files,
        private readonly CanonicalJson $json,
        private readonly ElectionClock $clock,
        private readonly ElectionOperationLock $lock,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function record(string $eventType, array $payload = []): array
    {
        return $this->lock->execute('activity-journal', fn (): array => $this->recordWithinLock($eventType, $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function recordWithinLock(string $eventType, array $payload): array
    {
        $this->storage->ensureDirectories();
        $entries = $this->entries();
        if (! $this->verifyEntries($entries)['passed']) {
            throw new RuntimeException('The activity journal is invalid. Operator reconciliation is required.');
        }
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
        $this->files->ensureDirectoryExists(dirname($path));
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
        $written = $this->files->append($path, $line);
        if ($written !== strlen($line)) {
            throw new RuntimeException('Unable to write the activity journal.');
        }

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

        $contents = $this->files->get($path);
        if ($contents !== '' && ! str_ends_with($contents, PHP_EOL)) {
            throw new RuntimeException('The activity journal has an incomplete final entry.');
        }

        return collect(explode(PHP_EOL, trim($contents)))
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

    /** @return array<int, array<string, mixed>> */
    public function verifiedEntries(): array
    {
        return $this->lock->execute('activity-journal', function (): array {
            $entries = $this->entries();
            if (! $this->verifyEntries($entries)['passed']) {
                throw new RuntimeException('The activity journal is invalid. Operator reconciliation is required.');
            }

            return $entries;
        });
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

        return $this->verifyEntries($entries);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array{passed: bool, entry_count: int, first_invalid_sequence: int|null}
     */
    private function verifyEntries(array $entries): array
    {
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
