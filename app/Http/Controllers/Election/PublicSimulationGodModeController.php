<?php

namespace App\Http\Controllers\Election;

use App\Election\PublicSimulation\PublicSimulationOperationsBoard;
use App\Election\PublicSimulation\PublicSimulationService;
use App\Election\Support\ElectionStorage;
use App\Http\Controllers\Controller;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Inertia\Inertia;
use Inertia\Response;

final class PublicSimulationGodModeController extends Controller
{
    public function __invoke(SimulationRound $round, PublicSimulationService $simulations, ElectionStorage $storage, PublicSimulationOperationsBoard $operationsBoard): Response
    {
        abort_unless(config('election.public_simulation.god_mode.enabled', false), 404);

        $round->load('precincts');

        return Inertia::render('Election/PublicSimulationGodMode', [
            'round' => [
                'code' => $round->code,
                'name' => $round->name,
                'precincts' => $round->precincts->map(function (SimulationPrecinct $precinct) use ($simulations, $storage, $operationsBoard): array {
                    $simulations->applyScope($precinct);
                    $journal = collect(explode(PHP_EOL, trim($storage->readText('journals/activity.jsonl'))))
                        ->filter()
                        ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR))
                        ->filter(fn (array $event): bool => str_starts_with((string) $event['event_type'], 'lifecycle.')
                            || str_starts_with((string) $event['event_type'], 'public_simulation.')
                            || str_starts_with((string) $event['event_type'], 'vvdat.'))
                        ->take(-8)
                        ->map(fn (array $event): array => [
                            'event_type' => $event['event_type'],
                            'occurred_at' => $event['occurred_at'],
                        ])
                        ->values()
                        ->all();

                    return [
                        'code' => $precinct->code,
                        'label' => $precinct->label,
                        'status' => $precinct->status,
                        'deposited_ballots' => count($storage->files('counting/sealed')),
                        'vvdat_records' => count($storage->files('device-tabulation-ledger')),
                        'operations_board' => $operationsBoard->summary(),
                        'journal' => $journal,
                    ];
                })->all(),
            ],
            'privacyNotice' => 'This facilitator screen intentionally excludes voter selections, control numbers, print releases, paper serials, QR payloads, and participant identity.',
        ]);
    }
}
