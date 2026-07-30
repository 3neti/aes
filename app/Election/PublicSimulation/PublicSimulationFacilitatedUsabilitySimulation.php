<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Models\SimulationPrecinct;

final class PublicSimulationFacilitatedUsabilitySimulation
{
    public function __construct(
        private readonly PublicSimulationService $simulations,
        private readonly PublicSimulationUsabilitySessionKit $sessionKit,
        private readonly PublicSimulationFieldRehearsal $fieldRehearsal,
        private readonly PublicSimulationOperationalObservation $observations,
        private readonly PublicSimulationObservationReview $observationReview,
        private readonly PublicSimulationImprovementBacklog $improvementBacklog,
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
    ) {}

    /** @return array<string, mixed> */
    public function run(SimulationPrecinct $precinct, int $voterCount): array
    {
        $this->simulations->applyScope($precinct);
        $kit = $this->sessionKit->build($precinct);
        $fieldReport = $this->fieldRehearsal->run($precinct, $voterCount);
        $this->simulations->applyScope($precinct->fresh('round'));

        $this->recordSyntheticObservations();
        $review = $this->observationReview->build();
        $backlog = $this->improvementBacklog->build();

        $sequence = count($this->storage->files('usability-simulations')) + 1;
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $precinctId = (string) ($configuration['precinct_id'] ?? $precinct->clustered_precinct);
        $report = [
            'schema_version' => 'public-simulation-facilitated-usability-simulation-1',
            'simulation_kind' => 'synthetic_facilitated_usability_dry_run',
            'generated_at' => $this->clock->now()->toIso8601String(),
            'round_code' => $precinct->round->code,
            'precinct_code' => $precinct->code,
            'precinct_id' => $precinctId,
            'voter_cohort_size' => $voterCount,
            'flow' => [
                'usability_session_kit_prepared',
                'precinct_opened',
                'voter_cohort_admitted',
                'premature_closeout_blocked',
                'private_ballots_finalized_printed_and_deposited',
                'precinct_closed',
                'vvdat_tallied',
                'election_return_generated',
                'watcher_results_published',
                'synthetic_observations_recorded',
                'private_observation_review_created',
                'private_improvement_backlog_created',
            ],
            'statistics' => [
                'field_rehearsal_voters' => $fieldReport['voter_cohort_size'],
                'device_tabulated_ballots' => $fieldReport['observations']['device_tabulated_ballots'] ?? null,
                'observations_recorded' => $this->observations->summary()['total'],
                'follow_up_observations' => count($review['follow_up_observations'] ?? []),
                'backlog_items' => $backlog['summary']['total_items'] ?? 0,
            ],
            'artifacts' => [
                'usability_session_kit' => $this->artifact('usability-session-kit/session-kit.json', $kit['kit_hash'] ?? null),
                'facilitator_guide' => $this->artifact('usability-session-kit/facilitator-guide.md'),
                'participant_observation_sheet' => $this->artifact('usability-session-kit/participant-observation-sheet.md'),
                'field_rehearsal_report' => $this->artifactFromReport($fieldReport, 'report_hash'),
                'tally_sheet_pdf' => $this->artifact('runtime/tally-sheet.pdf'),
                'election_return_pdf' => $this->artifact("returns/{$precinctId}-return.pdf"),
                'publication_manifest' => $this->artifact('returns/publication-manifest.json', $fieldReport['evidence']['publication_manifest_hash'] ?? null),
                'observation_review' => $this->artifactFromReport($review, 'review_hash'),
                'improvement_backlog' => $this->artifactFromReport($backlog, 'backlog_hash'),
            ],
            'privacy_notice' => 'This is a synthetic dry-run report. It contains flow statistics and artifact pointers only, and it must not be represented as external participant feedback.',
        ];
        $report['report_hash'] = $this->json->hash($report);
        $report['artifact_path'] = $this->storage->writeJson(sprintf('usability-simulations/%06d-facilitated-usability-simulation.json', $sequence), $report);

        $this->journal->record('public_simulation.facilitated_usability_simulation_completed', [
            'round_code' => $precinct->round->code,
            'precinct_code' => $precinct->code,
            'voter_cohort_size' => $voterCount,
            'report_hash' => $report['report_hash'],
            'backlog_items' => $report['statistics']['backlog_items'],
        ]);

        return $report;
    }

    private function recordSyntheticObservations(): void
    {
        $this->observations->record(
            'facilitator',
            'admission',
            'clear',
            'Synthetic dry-run: the officer handoff from physical identity check to four-digit control number was understandable.'
        );
        $this->observations->record(
            'voter',
            'private_printing',
            'needs_attention',
            'Synthetic dry-run: the voter needed stronger confirmation that the print step remains private before presenting the QR or release value.'
        );
        $this->observations->record(
            'watcher',
            'results',
            'needs_attention',
            'Synthetic dry-run: the watcher wanted more prominent links to the published tally sheet and Election Return artifacts.'
        );
    }

    /** @return array{path: string, absolute_path: string, sha256: string|null} */
    private function artifact(string $relativePath, mixed $knownHash = null): array
    {
        $path = $this->storage->path($relativePath);

        return [
            'path' => $relativePath,
            'absolute_path' => $path,
            'sha256' => is_file($path) ? hash_file('sha256', $path) : (is_string($knownHash) ? $knownHash : null),
        ];
    }

    /** @param array<string, mixed> $report */
    private function artifactFromReport(array $report, string $hashKey): array
    {
        $path = (string) ($report['artifact_path'] ?? '');

        return [
            'path' => $this->relativePath($path),
            'absolute_path' => $path,
            'sha256' => is_file($path) ? hash_file('sha256', $path) : (is_string($report[$hashKey] ?? null) ? $report[$hashKey] : null),
        ];
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->storage->root(), '/').'/';

        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }

        return $path;
    }
}
