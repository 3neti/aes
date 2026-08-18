<?php

namespace App\Election\Voting;

use App\Election\Core\ActivityJournal;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionOperationLock;
use App\Election\Support\ElectionStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

final class AnonymousVoterAuthorization
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly ActivityJournal $journal,
        private readonly ElectionOperationLock $operationLock,
    ) {}

    /**
     * @return array{authorization_id: string, code: string, expires_at: string}
     */
    public function issue(?string $previousAuthorizationId = null): array
    {
        return $this->operationLock->execute(
            'voter-authorization',
            fn (): array => $this->issueUnlocked($previousAuthorizationId),
        );
    }

    /**
     * @return array{authorization_id: string, status: string, expires_at: string|null, seconds_remaining: int}
     */
    public function inspect(string $authorizationId): array
    {
        return $this->operationLock->execute(
            'voter-authorization',
            function () use ($authorizationId): array {
                $record = $this->storage->readJson("voter-authorizations/{$authorizationId}.json");

                if ($record === []) {
                    return [
                        'authorization_id' => $authorizationId,
                        'status' => 'missing',
                        'expires_at' => null,
                        'seconds_remaining' => 0,
                    ];
                }

                $record = $this->expireIfNeeded($record);

                return $this->publicStatus($record);
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function claim(string $code): array
    {
        return $this->operationLock->execute(
            'voter-authorization',
            fn (): array => $this->claimUnlocked($code),
        );
    }

    public function isClaimed(string $authorizationId): bool
    {
        $record = $this->storage->readJson("voter-authorizations/{$authorizationId}.json");

        return ($record['status'] ?? null) === 'claimed';
    }

    public function complete(string $authorizationId): void
    {
        $record = $this->storage->readJson("voter-authorizations/{$authorizationId}.json");

        if (($record['status'] ?? null) !== 'claimed') {
            throw new RuntimeException('The voter control number is not active.');
        }

        $record['status'] = 'completed';
        $record['completed_at'] = $this->clock->now()->toIso8601String();
        $this->storage->writeJson("voter-authorizations/{$authorizationId}.json", $record);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function expireOldestIssued(string $reason): ?array
    {
        return $this->operationLock->execute(
            'voter-authorization',
            function () use ($reason): ?array {
                $record = collect($this->storage->files('voter-authorizations'))
                    ->map(fn (string $path): array => $this->storage->readJson('voter-authorizations/'.basename($path)))
                    ->filter(fn (array $candidate): bool => ($candidate['status'] ?? null) === 'issued')
                    ->sortBy(fn (array $candidate): string => (string) ($candidate['issued_at'] ?? ''))
                    ->first();

                if (! is_array($record)) {
                    return null;
                }

                $record['status'] = 'expired';
                $record['expired_at'] = $this->clock->now()->toIso8601String();
                $record['expired_reason'] = $reason;
                $this->storage->writeJson("voter-authorizations/{$record['authorization_id']}.json", $record);
                $this->journal->record('voter.authorization_expired', [
                    'authorization_id' => $record['authorization_id'],
                    'expired_at' => $record['expired_at'],
                    'reason' => $reason,
                ]);

                return $record;
            },
        );
    }

    /**
     * @return array{authorization_id: string, code: string, expires_at: string}
     */
    private function issueUnlocked(?string $previousAuthorizationId): array
    {
        $previous = $this->replacementCandidate($previousAuthorizationId);
        $authorizationId = (string) Str::uuid();
        $code = $this->controlNumber();
        $expiresAt = $this->clock->now()->addSeconds(
            (int) config('election.voter.authorization_ttl_seconds', 300),
        );
        $record = [
            'schema_version' => 'voter-control-number-1',
            'authorization_id' => $authorizationId,
            'code_hash' => $this->hash($code),
            'status' => 'issued',
            'issued_at' => $this->clock->now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        $this->storage->writeJson("voter-authorizations/{$authorizationId}.json", $record);
        $this->journal->record('voter.authorization_issued', [
            'authorization_id' => $authorizationId,
            'expires_at' => $record['expires_at'],
        ]);

        if ($previous !== []) {
            if (($previous['status'] ?? null) === 'issued') {
                $previous['status'] = 'superseded';
                $previous['superseded_at'] = $this->clock->now()->toIso8601String();
            }

            $previous['replacement_authorization_id'] = $authorizationId;
            $this->storage->writeJson(
                "voter-authorizations/{$previous['authorization_id']}.json",
                $previous,
            );
            $this->journal->record('voter.authorization_replaced', [
                'previous_authorization_id' => $previous['authorization_id'],
                'previous_status' => $previous['status'],
                'replacement_authorization_id' => $authorizationId,
                'replacement_expires_at' => $record['expires_at'],
            ]);
        }

        return [
            'authorization_id' => $authorizationId,
            'code' => $code,
            'expires_at' => $record['expires_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function claimUnlocked(string $code): array
    {
        $record = collect($this->storage->files('voter-authorizations'))
            ->map(fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
            ->first(fn (array $candidate): bool => hash_equals(
                (string) ($candidate['code_hash'] ?? ''),
                $this->hash($code),
            ));

        if (! is_array($record) || ($record['status'] ?? null) !== 'issued') {
            throw new RuntimeException('The voter control number is invalid or has already been used.');
        }

        $record = $this->expireIfNeeded($record);

        if (($record['status'] ?? null) === 'expired') {
            throw new RuntimeException('The voter control number has expired.');
        }

        $record['status'] = 'claimed';
        $record['claimed_at'] = $this->clock->now()->toIso8601String();
        $this->storage->writeJson("voter-authorizations/{$record['authorization_id']}.json", $record);
        $this->journal->record('voter.authorization_claimed', [
            'authorization_id' => $record['authorization_id'],
        ]);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function replacementCandidate(?string $authorizationId): array
    {
        if ($authorizationId === null || $authorizationId === '') {
            return [];
        }

        $record = $this->storage->readJson("voter-authorizations/{$authorizationId}.json");

        if ($record === []) {
            return [];
        }

        $record = $this->expireIfNeeded($record);

        return in_array($record['status'] ?? null, ['issued', 'expired'], true)
            ? $record
            : [];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function expireIfNeeded(array $record): array
    {
        if (
            ($record['status'] ?? null) !== 'issued'
            || ! isset($record['expires_at'])
            || $this->clock->now()->isBefore(CarbonImmutable::parse($record['expires_at']))
        ) {
            return $record;
        }

        $record['status'] = 'expired';
        $record['expired_at'] = $this->clock->now()->toIso8601String();
        $this->storage->writeJson("voter-authorizations/{$record['authorization_id']}.json", $record);
        $this->journal->record('voter.authorization_expired', [
            'authorization_id' => $record['authorization_id'],
            'expired_at' => $record['expired_at'],
        ]);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{authorization_id: string, status: string, expires_at: string|null, seconds_remaining: int}
     */
    private function publicStatus(array $record): array
    {
        $expiresAt = isset($record['expires_at'])
            ? CarbonImmutable::parse($record['expires_at'])
            : null;

        return [
            'authorization_id' => (string) $record['authorization_id'],
            'status' => (string) ($record['status'] ?? 'missing'),
            'expires_at' => $expiresAt?->toIso8601String(),
            'seconds_remaining' => $expiresAt === null
                ? 0
                : max(0, $expiresAt->getTimestamp() - $this->clock->now()->getTimestamp()),
        ];
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', Str::upper(trim($code)), (string) config('app.key'));
    }

    private function controlNumber(): string
    {
        $usedHashes = collect($this->storage->files('voter-authorizations'))
            ->map(fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
            ->pluck('code_hash')
            ->filter(fn (mixed $hash): bool => is_string($hash))
            ->flip();
        $start = random_int(0, 9999);

        for ($offset = 0; $offset < 10000; $offset++) {
            $number = str_pad((string) (($start + $offset) % 10000), 4, '0', STR_PAD_LEFT);

            if (! $usedHashes->has($this->hash($number))) {
                return $number;
            }
        }

        throw new RuntimeException('All voter control numbers have been issued for this precinct run.');
    }
}
