<?php

namespace App\Http\Controllers\Election;

use App\Election\Counting\TallyPresentation;
use App\Election\PublicSimulation\PublicRandomManualAuditPublication;
use App\Election\PublicSimulation\PublicSimulationService;
use App\Election\PublicSimulation\PublicVvdatAuditExport;
use App\Election\Support\ElectionStorage;
use App\Http\Controllers\Controller;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PublicSimulationWatcherController extends Controller
{
    public function show(SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PublicVvdatAuditExport $exports, PublicRandomManualAuditPublication $auditPublication, ElectionStorage $storage, TallyPresentation $presentation): Response
    {
        $this->scope($round, $precinct, $simulations);
        $configuration = $storage->readJson('runtime/active-precinct.json');
        $return = $configuration === [] ? [] : $storage->readJson("returns/{$configuration['precinct_id']}-return.json");
        $publication = $storage->readJson('returns/publication-manifest.json');

        return Inertia::render('Election/PublicSimulationWatcher', [
            'precinct' => [
                'label' => $precinct->label,
                'code' => $precinct->code,
                'status' => $precinct->status,
                'accepted_ballots' => $return['accepted_ballots'] ?? null,
                'tally' => $return['tally'] ?? [],
                'display_tally' => $presentation->displayTally((array) ($return['tally'] ?? [])),
            ],
            'ballot' => [
                'contests' => collect($configuration['contests'] ?? [])
                    ->map(fn (array $contest): array => [
                        'id' => $contest['id'],
                        'title' => $contest['title'],
                        'candidates' => collect($contest['candidates'])
                            ->map(fn (array $candidate): array => [
                                'id' => $candidate['id'],
                                'name' => $candidate['name'],
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
            ],
            'published' => $publication !== [],
            'auditExportAvailable' => $publication !== [] && $exports->isAvailable(),
            'randomManualAudit' => $publication === [] ? [] : $auditPublication->watcherSummary(),
            'publication' => [
                'manifest_hash' => $publication['manifest_hash'] ?? null,
                'ledger_root' => $publication['vvdat_ledger_root'] ?? null,
            ],
            'downloads' => [
                'tally' => route('election.public-simulation.watcher.tally', [$round, $precinct]),
                'return' => route('election.public-simulation.watcher.return', [$round, $precinct]),
                'vvdat_audit_export' => route('election.public-simulation.watcher.vvdat-audit-export', [$round, $precinct]),
                'random_manual_audit' => route('election.public-simulation.watcher.rma-audit', [$round, $precinct]),
            ],
        ]);
    }

    public function tally(SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, ElectionStorage $storage): BinaryFileResponse
    {
        $this->scope($round, $precinct, $simulations);
        abort_unless($this->isPublished($precinct, $storage) && file_exists($storage->path('runtime/tally-sheet.pdf')), 404);

        return response()->download($storage->path('runtime/tally-sheet.pdf'), "{$precinct->code}-tally-sheet.pdf");
    }

    public function electionReturn(SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, ElectionStorage $storage): BinaryFileResponse
    {
        $this->scope($round, $precinct, $simulations);
        $configuration = $storage->readJson('runtime/active-precinct.json');
        $precinctId = (string) ($configuration['precinct_id'] ?? '');
        $path = $storage->path("returns/{$precinctId}-return.pdf");
        abort_unless($this->isPublished($precinct, $storage) && $precinctId !== '' && file_exists($path), 404);

        return response()->download($path, "{$precinct->code}-election-return.pdf");
    }

    public function vvdatAuditExport(SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PublicVvdatAuditExport $exports, ElectionStorage $storage): BinaryFileResponse
    {
        $this->scope($round, $precinct, $simulations);
        abort_unless($this->isPublished($precinct, $storage) && $exports->isAvailable(), 404);
        $export = $exports->generate();

        return response()->download((string) $export['artifact_path'], "{$precinct->code}-vvdat-audit-export.json");
    }

    public function randomManualAudit(SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PublicRandomManualAuditPublication $publication, ElectionStorage $storage): BinaryFileResponse
    {
        $this->scope($round, $precinct, $simulations);
        $path = $storage->path('returns/public-rma-audit-summary.pdf');
        abort_unless($this->isPublished($precinct, $storage) && $publication->summary() !== [] && is_file($path), 404);

        return response()->download($path, "{$precinct->code}-random-manual-audit-summary.pdf");
    }

    private function scope(SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations): void
    {
        abort_unless($precinct->simulation_round_id === $round->id, 404);
        $simulations->applyScope($precinct);
    }

    private function isPublished(SimulationPrecinct $precinct, ElectionStorage $storage): bool
    {
        return $precinct->status === 'published'
            && $storage->readJson('returns/publication-manifest.json') !== [];
    }
}
