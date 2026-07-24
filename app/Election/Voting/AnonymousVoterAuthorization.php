<?php

namespace App\Election\Voting;

use App\Election\Core\ActivityJournal;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Support\Str;
use RuntimeException;

final class AnonymousVoterAuthorization
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @return array{authorization_id: string, code: string, expires_at: string}
     */
    public function issue(): array
    {
        $authorizationId = (string) Str::uuid();
        $code = $this->code();
        $expiresAt = $this->clock->now()->addSeconds(
            (int) config('election.voter.authorization_ttl_seconds', 300),
        );
        $record = [
            'schema_version' => 'anonymous-voter-authorization-1',
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

        return [
            'authorization_id' => $authorizationId,
            'code' => $code,
            'expires_at' => $record['expires_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function claim(string $code): array
    {
        $record = collect($this->storage->files('voter-authorizations'))
            ->map(fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
            ->first(fn (array $candidate): bool => hash_equals(
                (string) ($candidate['code_hash'] ?? ''),
                $this->hash($code),
            ));

        if (! is_array($record) || ($record['status'] ?? null) !== 'issued') {
            throw new RuntimeException('The voter authorization is invalid or has already been used.');
        }

        if ($this->clock->now()->isAfter($record['expires_at'])) {
            throw new RuntimeException('The voter authorization has expired.');
        }

        $record['status'] = 'claimed';
        $record['claimed_at'] = $this->clock->now()->toIso8601String();
        $this->storage->writeJson("voter-authorizations/{$record['authorization_id']}.json", $record);
        $this->journal->record('voter.authorization_claimed', [
            'authorization_id' => $record['authorization_id'],
        ]);

        return $record;
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
            throw new RuntimeException('The voter authorization is not active.');
        }

        $record['status'] = 'completed';
        $record['completed_at'] = $this->clock->now()->toIso8601String();
        $this->storage->writeJson("voter-authorizations/{$authorizationId}.json", $record);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', Str::upper(trim($code)), (string) config('app.key'));
    }

    private function code(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $characters = '';

        for ($index = 0; $index < 8; $index++) {
            $characters .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return substr($characters, 0, 4).'-'.substr($characters, 4);
    }
}
