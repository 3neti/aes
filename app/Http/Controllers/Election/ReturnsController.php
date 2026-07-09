<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Counting\CountingService;
use App\Election\Lifecycle\CeremonyActions;
use App\Election\Returns\ElectionReturnCopyDistributionService;
use App\Election\Returns\ElectionReturnLegalEvidenceService;
use App\Election\Returns\ElectionReturnService;
use App\Election\Support\ElectionStorage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class ReturnsController extends Controller
{
    public function show(
        ElectionSnapshot $snapshot,
        ElectionStorage $storage,
        ElectionReturnLegalEvidenceService $legalEvidence,
        ElectionReturnCopyDistributionService $copyDistribution,
    ): Response {
        $configuration = $storage->readJson('runtime/active-precinct.json');
        $precinctId = $configuration['precinct_id'] ?? '0421-A';

        return Inertia::render('Election/Returns', [
            'snapshot' => $snapshot->get(),
            'returnArtifact' => $storage->readJson("returns/{$precinctId}-return.json"),
            'returnCopyDistribution' => $copyDistribution->summary(),
            'electionReturnLegalEvidence' => $legalEvidence->summary(),
        ]);
    }

    public function generate(CountingService $counting, ElectionReturnService $returns): RedirectResponse
    {
        $returns->generate($counting->tally());

        return redirect()->route('election.returns');
    }

    public function close(CeremonyActions $ceremonies): RedirectResponse
    {
        $ceremonies->moveToTransmission();

        return redirect()->route('election.transmission');
    }

    public function copyDistribution(ElectionReturnCopyDistributionService $distribution): RedirectResponse
    {
        $distribution->prepare();

        return redirect()->route('election.returns');
    }
}
