<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Counting\CountingService;
use App\Election\Lifecycle\CeremonyActions;
use App\Election\Returns\ElectionReturnService;
use App\Election\Support\ElectionStorage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class ReturnsController extends Controller
{
    public function show(ElectionSnapshot $snapshot, ElectionStorage $storage): Response
    {
        return Inertia::render('Election/Returns', [
            'snapshot' => $snapshot->get(),
            'returnArtifact' => $storage->readJson('returns/0421-A-return.json'),
        ]);
    }

    public function generate(CountingService $counting, ElectionReturnService $returns): RedirectResponse
    {
        $returns->generate($counting->tally());

        return redirect()->route('election.returns');
    }

    public function close(CeremonyActions $ceremonies): RedirectResponse
    {
        $ceremonies->closePrecinct();

        return redirect()->route('election.diagnostics');
    }
}
