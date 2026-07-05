<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Lifecycle\CeremonyActions;
use App\Election\Voting\BallotPayloadService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class VotingController extends Controller
{
    public function show(ElectionSnapshot $snapshot): Response
    {
        return Inertia::render('Election/Voting', ['snapshot' => $snapshot->get()]);
    }

    public function openPolls(CeremonyActions $ceremonies): RedirectResponse
    {
        $ceremonies->openPolls();

        return redirect()->route('election.voting');
    }

    public function finalize(Request $request, BallotPayloadService $payloads): RedirectResponse
    {
        $validated = $request->validate([
            'president' => ['nullable', 'array'],
            'president.*' => ['string'],
            'mayor' => ['nullable', 'array'],
            'mayor.*' => ['string'],
            'council' => ['nullable', 'array'],
            'council.*' => ['string'],
        ]);

        $payload = $payloads->finalize([
            'president' => array_values($validated['president'] ?? []),
            'mayor' => array_values($validated['mayor'] ?? []),
            'council' => array_values($validated['council'] ?? []),
        ]);

        return redirect()->route('election.printing', ['ballot' => $payload['ballot_id']]);
    }

    public function closePolls(CeremonyActions $ceremonies): RedirectResponse
    {
        $ceremonies->closePolls();
        $ceremonies->startCounting();

        return redirect()->route('election.counting');
    }
}
