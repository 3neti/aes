<?php

namespace App\Election\Bundle;

use Closure;
use Illuminate\Support\Facades\DB;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyContext;
use LBHurtado\ElectionCeremonies\Contracts\CeremonyStore;
use RuntimeException;

final class DatabaseResearchCeremonyStore implements CeremonyStore
{
    public function synchronized(string $runId, Closure $operation): mixed
    {
        return DB::transaction(function () use ($runId, $operation): mixed {
            DB::table('election_research_runs')->where('id', $runId)->lockForUpdate()->first();

            return $operation();
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    public function state(string $runId): array
    {
        $run = DB::table('election_research_runs')->where('id', $runId)->first();

        if ($run === null) {
            return [];
        }

        $last = DB::table('election_research_events')->where('run_id', $runId)->orderByDesc('sequence')->first();

        if (($last?->event_hash ?? null) !== $run->head_hash) {
            throw new RuntimeException('Research ceremony journal head is invalid.');
        }

        if ($last !== null) {
            $this->verifyEvent($last);
        }

        return [
            'stage' => $run->stage,
            'bundle_hash' => $run->bundle_hash,
            'assignment_hash' => $run->assignment_hash,
            'profile_hash' => $run->profile_hash,
            'allowed_ceremonies' => json_decode($run->allowed_ceremonies, true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    public function commission(string $runId, string $bundleHash, string $assignmentHash, string $profileHash, array $allowedCeremonies): void
    {
        $this->synchronized($runId, function () use ($runId, $bundleHash, $assignmentHash, $profileHash, $allowedCeremonies): void {
            $existing = $this->state($runId);

            if ($existing !== []) {
                if ($existing['bundle_hash'] !== $bundleHash || $existing['assignment_hash'] !== $assignmentHash
                    || $existing['profile_hash'] !== $profileHash || $existing['allowed_ceremonies'] !== array_values($allowedCeremonies)) {
                    throw new RuntimeException('Commissioned research identity cannot be replaced.');
                }

                return;
            }

            DB::table('election_research_runs')->insert([
                'id' => $runId,
                'bundle_id' => '',
                'bundle_hash' => $bundleHash,
                'assignment_hash' => $assignmentHash,
                'profile_hash' => $profileHash,
                'allowed_ceremonies' => json_encode(array_values($allowedCeremonies), JSON_THROW_ON_ERROR),
                'artifact_directory' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /** @return array<string, mixed>|null */
    public function completed(string $runId, string $idempotencyKey): ?array
    {
        $event = DB::table('election_research_events')->where('run_id', $runId)
            ->where('idempotency_key', $idempotencyKey)->first();

        if ($event === null) {
            return null;
        }

        $this->verifyEvent($event);

        return json_decode($event->result, true, flags: JSON_THROW_ON_ERROR);
    }

    public function commit(CeremonyContext $context): void
    {
        $request = $context->request;
        $run = DB::table('election_research_runs')->where('id', $request->runId)->lockForUpdate()->first();

        if ($run === null) {
            throw new RuntimeException('Research run is not commissioned.');
        }

        $sequence = (int) DB::table('election_research_events')->where('run_id', $request->runId)->max('sequence') + 1;
        $result = [
            'run_id' => $request->runId,
            'ceremony_id' => $request->ceremonyId,
            'actor_id' => $request->actorId,
            'from' => $context->from,
            'to' => $context->to,
            'bundle_hash' => $request->bundleHash,
            'assignment_hash' => $request->assignmentHash,
            'profile_hash' => $context->profileHash,
            'request_hash' => hash('sha256', json_encode($request, JSON_THROW_ON_ERROR)),
            'evidence' => $context->evidence,
            'trace' => $context->trace,
        ];
        $event = [
            'sequence' => $sequence,
            'previous_hash' => $run->head_hash,
            'idempotency_key' => $request->idempotencyKey,
            'result_hash' => hash('sha256', json_encode($result, JSON_THROW_ON_ERROR)),
        ];
        $eventHash = hash('sha256', json_encode($event, JSON_THROW_ON_ERROR));
        $eventId = DB::table('election_research_events')->insertGetId([
            'run_id' => $request->runId,
            'sequence' => $sequence,
            'idempotency_key' => $request->idempotencyKey,
            'ceremony_id' => $request->ceremonyId,
            'actor_id' => $request->actorId,
            'request_hash' => $result['request_hash'],
            'result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'previous_hash' => $run->head_hash,
            'event_hash' => $eventHash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($request->ceremonyId === 'record_decision') {
            $decision = $context->evidence['review_decision'];
            DB::table('election_research_decisions')->insert([
                'run_id' => $request->runId,
                'event_id' => $eventId,
                'case_type' => $decision['case_type'],
                'case_id' => $decision['case_id'],
                'decision' => $decision['decision'],
                'reviewer_id' => (int) $request->actorId,
                'rationale' => $decision['rationale'],
                'evidence' => json_encode($decision, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('election_research_runs')->where('id', $request->runId)->update([
            'stage' => $context->to,
            'head_hash' => $eventHash,
            'closed_at' => $request->ceremonyId === 'close_review' ? now() : $run->closed_at,
            'updated_at' => now(),
        ]);
    }

    public function verifyJournal(string $runId): void
    {
        $previous = null;
        $sequence = 0;

        foreach (DB::table('election_research_events')->where('run_id', $runId)->orderBy('sequence')->cursor() as $event) {
            if ($event->sequence !== ++$sequence || $event->previous_hash !== $previous) {
                throw new RuntimeException('Research ceremony journal chain is invalid.');
            }

            $this->verifyEvent($event);
            $previous = $event->event_hash;
        }

        $run = DB::table('election_research_runs')->where('id', $runId)->first();

        if ($run === null || $run->head_hash !== $previous) {
            throw new RuntimeException('Research ceremony journal head is invalid.');
        }
    }

    private function verifyEvent(object $event): void
    {
        $result = json_decode($event->result, true, flags: JSON_THROW_ON_ERROR);
        $fields = [
            'sequence' => $event->sequence,
            'previous_hash' => $event->previous_hash,
            'idempotency_key' => $event->idempotency_key,
            'result_hash' => hash('sha256', json_encode($result, JSON_THROW_ON_ERROR)),
        ];

        if (! hash_equals($event->event_hash, hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('Research ceremony journal event is invalid.');
        }
    }
}
