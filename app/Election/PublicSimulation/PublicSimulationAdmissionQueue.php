<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionOperationLock;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\AnonymousVoterAuthorization;
use Illuminate\Support\Str;
use RuntimeException;

final class PublicSimulationAdmissionQueue
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly ElectionOperationLock $lock,
        private readonly ActivityJournal $journal,
        private readonly PublicSimulationAdmissionCapacity $capacity,
    ) {}

    /** @return array<string, mixed> */
    public function join(?string $existingTicketId = null): array
    {
        return $this->lock->execute('public-simulation-admission-queue', function () use ($existingTicketId): array {
            $this->ensureEnabled();
            $this->expireWaitingTickets();

            if (is_string($existingTicketId) && $existingTicketId !== '') {
                $existing = $this->record($existingTicketId);

                if (in_array($existing['status'] ?? null, ['waiting', 'released'], true)) {
                    return $this->publicTicket($existing);
                }
            }

            $summary = $this->summary();

            if ($summary['waiting_voters'] >= $summary['maximum_waiting_voters']) {
                throw new RuntimeException('The voter waiting line is full. Ask the Election Officer when another place becomes available.');
            }

            $sequence = count($this->records()) + 1;
            $ticket = [
                'schema_version' => 'public-simulation-admission-ticket-1',
                'ticket_id' => (string) Str::uuid(),
                'ticket_number' => sprintf('%03d', $sequence),
                'sequence' => $sequence,
                'status' => 'waiting',
                'joined_at' => $this->clock->now()->toIso8601String(),
                'expires_at' => $this->clock->now()->addSeconds($this->ticketTtlSeconds())->toIso8601String(),
            ];
            $this->write($ticket);
            $this->journal->record('public_simulation.admission_queue_joined', [
                'ticket_id' => $ticket['ticket_id'],
                'ticket_number' => $ticket['ticket_number'],
                'sequence' => $ticket['sequence'],
                'expires_at' => $ticket['expires_at'],
            ]);

            return $this->publicTicket($ticket);
        });
    }

    /**
     * @return array{ticket: array<string, mixed>, authorization: array{authorization_id: string, code: string, expires_at: string}}
     */
    public function releaseNext(AnonymousVoterAuthorization $authorizations): array
    {
        return $this->lock->execute('public-simulation-admission-queue', function () use ($authorizations): array {
            $this->ensureEnabled();
            $this->expireWaitingTickets();
            $ticket = collect($this->records())
                ->where('status', 'waiting')
                ->sortBy('sequence')
                ->first();

            if (! is_array($ticket)) {
                throw new RuntimeException('There is no waiting voter ticket to admit.');
            }

            $authorization = $this->capacity->issue($authorizations);
            $ticket['status'] = 'released';
            $ticket['released_at'] = $this->clock->now()->toIso8601String();
            $ticket['authorization_id'] = $authorization['authorization_id'];
            $this->write($ticket);
            $this->journal->record('public_simulation.admission_queue_released', [
                'ticket_id' => $ticket['ticket_id'],
                'ticket_number' => $ticket['ticket_number'],
                'authorization_id' => $authorization['authorization_id'],
            ]);

            return [
                'ticket' => $this->publicTicket($ticket),
                'authorization' => $authorization,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function status(?string $ticketId): array
    {
        return $this->lock->execute('public-simulation-admission-queue', function () use ($ticketId): array {
            if (! $this->enabled() || ! is_string($ticketId) || $ticketId === '') {
                return ['enabled' => $this->enabled(), 'status' => 'not_joined'];
            }

            $this->expireWaitingTickets();
            $ticket = $this->record($ticketId);

            return $ticket === []
                ? ['enabled' => true, 'status' => 'missing']
                : $this->publicTicket($ticket);
        });
    }

    /** @return array{enabled: bool, waiting_voters: int, maximum_waiting_voters: int, available_admissions: int} */
    public function summary(): array
    {
        $capacity = $this->capacity->summary();

        return [
            'enabled' => $this->enabled(),
            'waiting_voters' => collect($this->records())->where('status', 'waiting')->count(),
            'maximum_waiting_voters' => max(1, (int) config('election.public_simulation.admission_queue.maximum_waiting_voters', 25)),
            'available_admissions' => $capacity['available_admissions'],
        ];
    }

    private function ensureEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('The voter waiting line is disabled for this simulation.');
        }
    }

    private function enabled(): bool
    {
        return (bool) config('election.public_simulation.admission_queue.enabled', true);
    }

    private function ticketTtlSeconds(): int
    {
        return max(60, (int) config('election.public_simulation.admission_queue.ticket_ttl_seconds', 900));
    }

    private function expireWaitingTickets(): void
    {
        foreach ($this->records() as $ticket) {
            if (($ticket['status'] ?? null) !== 'waiting' || ! $this->clock->now()->isAfter($ticket['expires_at'])) {
                continue;
            }

            $ticket['status'] = 'expired';
            $ticket['expired_at'] = $this->clock->now()->toIso8601String();
            $this->write($ticket);
            $this->journal->record('public_simulation.admission_queue_expired', [
                'ticket_id' => $ticket['ticket_id'],
                'ticket_number' => $ticket['ticket_number'],
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function records(): array
    {
        return collect($this->storage->files('admission-queue'))
            ->map(fn (string $path): array => $this->storage->readJson('admission-queue/'.basename($path)))
            ->sortBy('sequence')
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function record(string $ticketId): array
    {
        return $this->storage->readJson("admission-queue/{$ticketId}.json");
    }

    /** @param array<string, mixed> $ticket */
    private function write(array $ticket): void
    {
        $this->storage->writeJson("admission-queue/{$ticket['ticket_id']}.json", $ticket);
    }

    /**
     * @param  array<string, mixed>  $ticket
     * @return array<string, mixed>
     */
    private function publicTicket(array $ticket): array
    {
        $position = ($ticket['status'] ?? null) === 'waiting'
            ? collect($this->records())
                ->where('status', 'waiting')
                ->where('sequence', '<=', $ticket['sequence'])
                ->count()
            : null;

        return [
            'enabled' => true,
            'ticket_id' => $ticket['ticket_id'],
            'ticket_number' => $ticket['ticket_number'],
            'status' => $ticket['status'],
            'position' => $position,
            'expires_at' => $ticket['expires_at'],
        ];
    }
}
