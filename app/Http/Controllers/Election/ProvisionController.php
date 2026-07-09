<?php

namespace App\Http\Controllers\Election;

use App\Election\Attestation\ElectoralBoardBaselineService;
use App\Election\Core\ElectionSnapshot;
use App\Election\Preparation\ActivateSamplePackage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class ProvisionController extends Controller
{
    public function show(ElectionSnapshot $snapshot, ElectoralBoardBaselineService $baseline): Response
    {
        return Inertia::render('Election/Provision', [
            'snapshot' => $snapshot->get(),
            'electoralBoardBaseline' => $baseline->summary(),
        ]);
    }

    public function activate(ActivateSamplePackage $activate): RedirectResponse
    {
        $activate->handle();

        return redirect()->route('election.certification');
    }

    public function writeElectoralBoardBaseline(ElectoralBoardBaselineService $baseline): RedirectResponse
    {
        $report = $baseline->write();

        return redirect()->route('election.provision')
            ->with('electoral_board_baseline_hash', $report['baseline_hash'] ?? null);
    }
}
