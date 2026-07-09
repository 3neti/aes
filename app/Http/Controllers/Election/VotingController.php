<?php

namespace App\Http\Controllers\Election;

use App\Election\Attestation\OfficerRegistry;
use App\Election\Certification\InitializationReportService;
use App\Election\Core\ElectionSnapshot;
use App\Election\Lifecycle\CeremonyActions;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Voting\BallotPayloadService;
use App\Http\Controllers\Controller;
use App\Http\Requests\OpenPollsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class VotingController extends Controller
{
    public function show(ElectionSnapshot $snapshot): Response
    {
        return Inertia::render('Election/Voting', ['snapshot' => $snapshot->get()]);
    }

    public function openPolls(
        OpenPollsRequest $request,
        OfficerRegistry $officers,
        LifecycleState $lifecycle,
        InitializationReportService $initializationReport,
        CeremonyActions $ceremonies,
    ): RedirectResponse {
        $officerCode = $request->validated('officer_code');
        $officerPin = $request->validated('officer_pin');
        $officer = $officers->verify($officerCode, $officerPin);

        if ($officer === null) {
            throw ValidationException::withMessages([
                'officer_pin' => 'The officer code or PIN is invalid.',
            ]);
        }

        if ($lifecycle->current() === Lifecycle::OpenPrecinct) {
            $initializationReport->write('opening/initialization-report.json');
        }

        $ceremonies->openPolls($officer['name']);

        return redirect()->route('election.voting');
    }

    public function finalize(Request $request, BallotPayloadService $payloads): RedirectResponse
    {
        $validated = $request->validate([
            'selections' => ['nullable', 'array'],
            'selections.*' => ['array'],
            'selections.*.*' => ['string'],
        ]);

        $selections = collect($validated['selections'] ?? [])
            ->map(fn (array $candidateIds): array => array_values($candidateIds))
            ->all();

        $payload = $payloads->finalize($selections);

        return redirect()->route('election.printing', ['ballot' => $payload['ballot_id']]);
    }

    public function closePolls(CeremonyActions $ceremonies): RedirectResponse
    {
        $ceremonies->closePolls();
        $ceremonies->startCounting();

        return redirect()->route('election.counting');
    }
}
