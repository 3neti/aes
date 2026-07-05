<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Counting\CountingService;
use App\Election\Lifecycle\CeremonyActions;
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

    public function scan(Request $request, CountingService $counting): RedirectResponse
    {
        $validated = $request->validate(['payload' => ['required', 'string']]);
        $counting->accept($validated['payload']);

        return redirect()->route('election.counting');
    }

    public function complete(CeremonyActions $ceremonies): RedirectResponse
    {
        $ceremonies->moveToReturns();

        return redirect()->route('election.returns');
    }
}
