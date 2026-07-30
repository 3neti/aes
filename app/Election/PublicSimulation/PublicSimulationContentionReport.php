<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionOperationLock;
use App\Election\Support\ElectionStorage;

final class PublicSimulationContentionReport
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly ElectionOperationLock $lock,
        private readonly ActivityJournal $journal,
        private readonly CanonicalJson $json,
        private readonly PublicSimulationAdmissionCapacity $capacity,
        private readonly PublicSimulationAdmissionQueue $queue,
    ) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $eventCounts = collect($this->journal->entries())
            ->countBy(fn (array $entry): string => (string) ($entry['event_type'] ?? 'unknown'));

        return [
            'capacity' => $this->capacity->summary(),
            'waiting_line' => $this->queue->summary(),
            'activity' => [
                'journal_events' => $eventCounts->sum(),
                'control_numbers_issued' => (int) $eventCounts->get('public_simulation.admission_capacity_reserved', 0),
                'tickets_joined' => (int) $eventCounts->get('public_simulation.admission_queue_joined', 0),
                'tickets_released' => (int) $eventCounts->get('public_simulation.admission_queue_released', 0),
                'tickets_expired' => (int) $eventCounts->get('public_simulation.admission_queue_expired', 0),
                'close_attempts_blocked' => (int) $eventCounts->get('public_simulation.close_blocked_pending_voters', 0),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function generate(): array
    {
        return $this->lock->execute('public-simulation-contention-report', function (): array {
            $sequence = count($this->storage->files('contention-reports')) + 1;
            $report = [
                'schema_version' => 'public-simulation-contention-report-1',
                'sequence' => $sequence,
                'generated_at' => $this->clock->now()->toIso8601String(),
                'purpose' => 'Aggregate officer operational pressure report for a public election simulation.',
                'privacy_notice' => 'This report contains aggregate counts only. It excludes voter identifiers, control numbers, tickets, ballot selections, QR payloads, releases, and browser/session information.',
                ...$this->summary(),
            ];
            $report['report_hash'] = $this->json->hash($report);
            $report['artifact_path'] = $this->storage->writeJson(sprintf('contention-reports/%06d-contention-report.json', $sequence), $report);

            $this->journal->record('public_simulation.contention_report_generated', [
                'sequence' => $sequence,
                'report_hash' => $report['report_hash'],
                'active_admissions' => $report['capacity']['active_admissions'],
                'waiting_voters' => $report['waiting_line']['waiting_voters'],
                'close_attempts_blocked' => $report['activity']['close_attempts_blocked'],
            ]);

            return $report;
        });
    }
}
