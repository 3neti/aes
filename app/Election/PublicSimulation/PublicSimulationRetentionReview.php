<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class PublicSimulationRetentionReview
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
    ) {}

    /** @return array<string, mixed> */
    public function review(SimulationRound $round): array
    {
        $round->loadMissing('precincts');
        $retentionDays = max(1, (int) config('election.public_simulation.retention_days', 30));
        $reference = $round->archived_at ?? $round->opened_at ?? now();
        $reference = CarbonImmutable::instance($reference);
        $dueAt = $reference->addDays($retentionDays);
        $status = $round->status !== 'archived'
            ? 'active_round'
            : ($this->clock->now()->greaterThanOrEqualTo($dueAt) ? 'review_due' : 'retained');
        $directory = $this->directory($round);
        $this->files->ensureDirectoryExists($directory);

        $report = [
            'schema_version' => 'public-simulation-retention-review-1',
            'generated_at' => $this->clock->now()->toIso8601String(),
            'round_code' => $round->code,
            'round_status' => $round->status,
            'retention_days' => $retentionDays,
            'retention_reference_at' => $reference->toIso8601String(),
            'review_due_at' => $dueAt->toIso8601String(),
            'review_status' => $status,
            'precinct_statuses' => $round->precincts
                ->sortBy('code')
                ->map(fn (SimulationPrecinct $precinct): array => ['code' => $precinct->code, 'status' => $precinct->status])
                ->values()
                ->all(),
            'disposition_policy' => 'manual-review-required-no-automatic-deletion',
            'next_required_action' => $this->nextAction($status),
            'privacy_notice' => 'This report contains round and precinct lifecycle status only. It excludes voter identities, browser sessions, control numbers, ballots, QR payloads, and individual selections.',
        ];
        $report['review_hash'] = $this->json->hash($report);
        $report['artifact_path'] = $directory.'/retention-review.json';
        $this->files->put($report['artifact_path'], $this->json->encode($report));
        $this->files->put($directory.'/README.md', $this->readme($report));

        return $report;
    }

    private function directory(SimulationRound $round): string
    {
        $directory = trim((string) config('election.storage.directory', 'election'), '/');
        $baseDirectory = Str::before($directory.'/public-simulations/', '/public-simulations/');

        return storage_path("app/{$baseDirectory}/public-simulations/{$round->code}/RETENTION-REVIEW");
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'active_round' => 'Preserve active precinct evidence. Do not archive or delete it.',
            'review_due' => 'A facilitator must document a retain, external archive, or separately authorized deletion decision. This application never deletes evidence automatically.',
            default => 'Continue retaining the archived evidence until its review date. No automatic deletion occurs.',
        };
    }

    /** @param array<string, mixed> $report */
    private function readme(array $report): string
    {
        return implode(PHP_EOL, [
            '# Public Simulation Retention Review',
            '',
            "Round: {$report['round_code']} ({$report['round_status']})",
            "Retention window: {$report['retention_days']} days",
            "Review due: {$report['review_due_at']}",
            "Current status: {$report['review_status']}",
            '',
            $report['next_required_action'],
            '',
            'No evidence is deleted by this report or by the public simulation retention process.',
        ]).PHP_EOL;
    }
}
