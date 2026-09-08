<?php

namespace App\Http\Controllers\Election;

use App\Election\PublicSimulation\CanvassingDemoSimulation;
use App\Http\Controllers\Controller;
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
            ],
        ]);
    }

    public function generate(Request $request, CanvassingDemoSimulation $simulation): RedirectResponse
    {
        $validated = $request->validate([
            'ballot_count' => ['required', 'integer', 'min:1', 'max:1000'],
            'return_count' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $state = $simulation->generate((int) $validated['ballot_count'], (int) $validated['return_count']);

        return to_route('election.canvassing-demo.show')
            ->with('canvassing_demo.feedback', "Generated {$state['return_count']} election returns from {$state['ballots_per_return']} ballot QR payloads each.");
    }
}
