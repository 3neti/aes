<?php

namespace App\Http\Controllers\Election;

use App\Election\PublicSimulation\PublicSimulationService;
use App\Election\Support\ElectionStorage;
use App\Http\Controllers\Controller;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PublicSimulationWatcherController extends Controller
{
    public function show(SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, ElectionStorage $storage): Response
    {
        $this->scope($round, $precinct, $simulations);
        $configuration = $storage->readJson('runtime/active-precinct.json');
        $return = $configuration === [] ? [] : $storage->readJson("returns/{$configuration['precinct_id']}-return.json");

        return Inertia::render('Election/PublicSimulationWatcher', [
            'precinct' => [
                'label' => $precinct->label,
                'code' => $precinct->code,
                'status' => $precinct->status,
                'accepted_ballots' => $return['accepted_ballots'] ?? null,
                'tally' => $return['tally'] ?? [],
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
            'published' => $return !== [],
            'downloads' => [
                'tally' => route('election.public-simulation.watcher.tally', [$round, $precinct]),
                'return' => route('election.public-simulation.watcher.return', [$round, $precinct]),
            ],
        ]);
    }

    public function tally(SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, ElectionStorage $storage): BinaryFileResponse
    {
        $this->scope($round, $precinct, $simulations);
        abort_unless($precinct->status === 'closed' && file_exists($storage->path('runtime/tally-sheet.pdf')), 404);

        return response()->download($storage->path('runtime/tally-sheet.pdf'), "{$precinct->code}-tally-sheet.pdf");
    }

    public function electionReturn(SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, ElectionStorage $storage): BinaryFileResponse
    {
        $this->scope($round, $precinct, $simulations);
        $configuration = $storage->readJson('runtime/active-precinct.json');
        $precinctId = (string) ($configuration['precinct_id'] ?? '');
        $path = $storage->path("returns/{$precinctId}-return.pdf");
        abort_unless($precinct->status === 'closed' && $precinctId !== '' && file_exists($path), 404);

        return response()->download($path, "{$precinct->code}-election-return.pdf");
    }

    private function scope(SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations): void
    {
        abort_unless($precinct->simulation_round_id === $round->id, 404);
        $simulations->applyScope($precinct);
    }
}
