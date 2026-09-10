<?php

namespace App\Http\Controllers\Election;

use App\Election\PublicSimulation\CanvassingDemoSimulation;
use App\Election\PublicSimulation\CanvassingScannerIngestion;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CanvassingDemoController extends Controller
{
    public function show(CanvassingDemoSimulation $simulation): Response
    {
        return Inertia::render('Election/CanvassingDemo', [
            'simulation' => $simulation->summary(),
            'actions' => [
                'generate' => route('election.canvassing-demo.generate'),
                'publicBoard' => route('election.canvassing-demo.public'),
                'scannerState' => route('election.canvassing-demo.scanner-events.index'),
                'scannerIngest' => route('election.canvassing-demo.scanner-events.store'),
                'scannerReset' => route('election.canvassing-demo.scanner-events.reset'),
                'simulatorTick' => route('election.canvassing-demo.simulator.tick'),
            ],
        ]);
    }

    public function public(Request $request, CanvassingDemoSimulation $simulation): Response
    {
        return Inertia::render('Election/CanvassingPublicBoard', [
            'simulation' => $simulation->summary(),
            'view' => (string) $request->query('view', 'all'),
            'actions' => [
                'scannerState' => route('election.canvassing-demo.scanner-events.index'),
                'simulatorTick' => route('election.canvassing-demo.simulator.tick'),
                'operatorBoard' => route('election.canvassing-demo.show'),
                'publicBoard' => route('election.canvassing-demo.public'),
            ],
        ]);
    }

    public function generate(Request $request, CanvassingDemoSimulation $simulation, CanvassingScannerIngestion $ingestion): RedirectResponse
    {
        $validated = $request->validate([
            'ballot_count' => ['required', 'integer', 'min:1', 'max:1000'],
            'return_count' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $state = $simulation->generate((int) $validated['ballot_count'], (int) $validated['return_count']);
        $ingestion->reset();

        return to_route('election.canvassing-demo.show')
            ->with('canvassing_demo.feedback', "Generated {$state['return_count']} election returns from {$state['ballots_per_return']} ballot QR payloads each.");
    }

    public function scannerEvents(Request $request, CanvassingScannerIngestion $ingestion): JsonResponse
    {
        $stationId = (string) $request->string('station_id', 'canvassing-demo-city');

        return response()->json($ingestion->state($stationId));
    }

    public function storeScannerEvent(Request $request, CanvassingScannerIngestion $ingestion): JsonResponse
    {
        $validated = $request->validate([
            'payload' => ['required', 'string', 'max:25000'],
            'station_id' => ['nullable', 'string', 'max:80'],
            'source' => ['nullable', 'string', 'max:80'],
        ]);

        $result = $ingestion->ingest(
            (string) $validated['payload'],
            (string) ($validated['station_id'] ?? 'canvassing-demo-city'),
            (string) ($validated['source'] ?? 'keyboard_wedge'),
        );

        return response()->json($result, $result['event']['status'] === 'rejected' ? 422 : 200);
    }

    public function resetScannerEvents(Request $request, CanvassingScannerIngestion $ingestion): JsonResponse
    {
        $stationId = (string) $request->string('station_id', 'canvassing-demo-city');
        $ingestion->reset($stationId);

        return response()->json($ingestion->state($stationId));
    }

    public function simulatorTick(Request $request, CanvassingScannerIngestion $ingestion): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => ['nullable', 'string', 'max:80'],
        ]);

        return response()->json(
            $ingestion->simulateNext((string) ($validated['station_id'] ?? 'canvassing-demo-city')),
        );
    }
}
