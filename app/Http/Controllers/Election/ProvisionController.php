<?php

namespace App\Http\Controllers\Election;

use App\Election\Attestation\ElectoralBoardBaselineService;
use App\Election\Core\ElectionSnapshot;
use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Preparation\SupplyVerificationBaselineService;
use App\Election\Scenarios\LegalScenarioHarnessService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class ProvisionController extends Controller
{
    public function show(
        ElectionSnapshot $snapshot,
        ElectoralBoardBaselineService $baseline,
        LegalScenarioHarnessService $harness,
        SupplyVerificationBaselineService $supplyBaseline,
    ): Response {
        return Inertia::render('Election/Provision', [
            'snapshot' => $snapshot->get(),
            'electoralBoardBaseline' => $baseline->summary(),
            'legalScenarioSuite' => $harness->summary(),
            'supplyVerificationBaseline' => $supplyBaseline->summary(),
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

    public function runLegalScenarioSuite(LegalScenarioHarnessService $harness): RedirectResponse
    {
        $report = $harness->run();

        return redirect()->route('election.provision')
            ->with('legal_scenario_suite_hash', $report['archived_report_path'] ?? null);
    }

    public function writeSupplyVerificationBaseline(SupplyVerificationBaselineService $supply): RedirectResponse
    {
        $report = $supply->write();

        return redirect()->route('election.provision')
            ->with('supply_verification_baseline_hash', $report['baseline_hash'] ?? null);
    }
}
