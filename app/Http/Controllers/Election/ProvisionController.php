<?php

namespace App\Http\Controllers\Election;

use App\Election\Attestation\ElectoralBoardBaselineService;
use App\Election\Core\ElectionSnapshot;
use App\Election\Preparation\ActivateConfiguredPrecinct;
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
        ActivateConfiguredPrecinct $activate,
    ): Response {
        return Inertia::render('Election/Provision', [
            'snapshot' => $snapshot->get(),
            'electoralBoardBaseline' => $baseline->summary(),
            'legalScenarioSuite' => $harness->summary(),
            'supplyVerificationBaseline' => $supplyBaseline->summary(),
            'activationEvidence' => $activate->summary(),
            'configuredPrecinct' => [
                'clustered_precinct' => config('election.pop.clustered_precinct'),
                'district' => config('election.pop.district'),
                'pop_filename' => basename((string) config('election.pop.source_path')),
                'clc_source' => basename((string) config('election.clc.source_path')),
            ],
        ]);
    }

    public function activate(ActivateConfiguredPrecinct $activate): RedirectResponse
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
