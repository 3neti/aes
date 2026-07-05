<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Counting\CountingService;
use App\Election\Lifecycle\CeremonyActions;
use App\Election\Scanning\BallotScanner;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CountingController extends Controller
{
    public function show(ElectionSnapshot $snapshot, CountingService $counting): Response
    {
        return Inertia::render('Election/Counting', [
            'snapshot' => $snapshot->get(),
            'tally' => $counting->tally(),
        ]);
    }

    public function scan(Request $request, CountingService $counting, BallotScanner $scanner): RedirectResponse
    {
        $validated = $request->validate(['payload' => ['required', 'string']]);
        $scan = $scanner->scan($validated['payload']);
        $counting->accept($scan['payload']);

        return redirect()->route('election.counting');
    }

    public function complete(CeremonyActions $ceremonies): RedirectResponse
    {
        $ceremonies->moveToReturns();

        return redirect()->route('election.returns');
    }
}
