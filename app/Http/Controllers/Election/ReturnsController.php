<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Counting\CountingReconciliationService;
use App\Election\Counting\CountingService;
use App\Election\Lifecycle\CeremonyActions;
use App\Election\Printing\PrintFormProfileResolver;
use App\Election\Returns\ElectionReturnApprovalService;
use App\Election\Returns\ElectionReturnCopyDistributionService;
use App\Election\Returns\ElectionReturnLegalEvidenceService;
use App\Election\Returns\ElectionReturnService;
use App\Election\Support\ElectionStorage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ReturnsController extends Controller
{
    public function show(
        ElectionSnapshot $snapshot,
        ElectionStorage $storage,
        ElectionReturnLegalEvidenceService $legalEvidence,
        ElectionReturnCopyDistributionService $copyDistribution,
        ElectionReturnApprovalService $approval,
        PrintFormProfileResolver $printProfiles,
    ): Response {
        $configuration = $storage->readJson('runtime/active-precinct.json');
        $precinctId = $configuration['precinct_id'] ?? '0421-A';

        return Inertia::render('Election/Returns', [
            'snapshot' => $snapshot->get(),
            'returnArtifact' => $storage->readJson("returns/{$precinctId}-return.json"),
            'returnCopyDistribution' => $copyDistribution->summary(),
            'electionReturnLegalEvidence' => $legalEvidence->summary(),
            'returnApproval' => $approval->summary(),
            'printProfiles' => $printProfiles->options(),
        ]);
    }

    public function generate(
        CountingService $counting,
        ElectionReturnService $returns,
        CountingReconciliationService $reconciliation,
    ): RedirectResponse {
        if (! $reconciliation->summary()['passed']) {
            throw ValidationException::withMessages([
                'reconciliation' => 'The paper ballot reconciliation must pass before generating the Election Return.',
            ]);
        }

        $returns->generate($counting->tally());

        return redirect()->route('election.returns');
    }

    public function close(CeremonyActions $ceremonies, ElectionReturnApprovalService $approval): RedirectResponse
    {
        if (! ($approval->summary()['passed'] ?? false)) {
            throw ValidationException::withMessages([
                'approval' => 'Dual-control Election Return approval is required before official handoff.',
            ]);
        }

        $ceremonies->moveToTransmission();

        return redirect()->route('election.transmission');
    }

    public function approve(Request $request, ElectionReturnApprovalService $approval): RedirectResponse
    {
        $validated = $request->validate([
            'chairperson_code' => ['required', 'string'],
            'chairperson_pin' => ['required', 'digits:6'],
            'poll_clerk_code' => ['required', 'string', 'different:chairperson_code'],
            'poll_clerk_pin' => ['required', 'digits:6'],
        ]);
        $approval->approve(
            $validated['chairperson_code'],
            $validated['chairperson_pin'],
            $validated['poll_clerk_code'],
            $validated['poll_clerk_pin'],
        );

        return redirect()->route('election.returns');
    }

    public function copyDistribution(ElectionReturnCopyDistributionService $distribution): RedirectResponse
    {
        $distribution->prepare();

        return redirect()->route('election.returns');
    }

    public function downloadForm(string $profile, ElectionStorage $storage, PrintFormProfileResolver $profiles): BinaryFileResponse
    {
        $configuration = $storage->readJson('runtime/active-precinct.json');
        $precinctId = (string) ($configuration['precinct_id'] ?? '');
        abort_if($precinctId === '', 404);

        $resolved = $profiles->from($profile);
        $path = $storage->path("print-forms/election-return/{$precinctId}/{$resolved->value}.pdf");

        abort_unless(file_exists($path), 404);

        return response()->file($path, ['Content-Type' => 'application/pdf']);
    }
}
