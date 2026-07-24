<?php

namespace App\Http\Controllers\Election;

use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\BallotPayloadService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class VoterBallotController extends Controller
{
    public function show(ElectionStorage $storage, LifecycleState $lifecycle): Response
    {
        abort_unless($lifecycle->current() === Lifecycle::Voting, 409);

        $configuration = $storage->readJson('runtime/active-precinct.json');

        return Inertia::render('Election/VoterBallot', [
            'ballot' => [
                'election_id' => $configuration['election_id'] ?? null,
                'precinct_id' => $configuration['precinct_id'] ?? null,
                'ballot_style_id' => $configuration['ballot_style_id'] ?? null,
                'contests' => $configuration['contests'] ?? [],
            ],
        ]);
    }

    public function finalize(
        Request $request,
        BallotPayloadService $payloads,
        LifecycleState $lifecycle,
    ): RedirectResponse {
        if ($lifecycle->current() !== Lifecycle::Voting) {
            throw ValidationException::withMessages([
                'lifecycle' => 'The voter ballot is available only while polls are open.',
            ]);
        }

        $validated = $request->validate([
            'selections' => ['nullable', 'array'],
            'selections.*' => ['array'],
            'selections.*.*' => ['string'],
        ]);
        $selections = collect($validated['selections'] ?? [])
            ->map(fn (array $candidateIds): array => array_values($candidateIds))
            ->all();
        $payload = $payloads->finalize($selections);

        return redirect()->route('election.voter.complete', ['ballot' => $payload['ballot_id']]);
    }

    public function complete(Request $request, ElectionStorage $storage): Response
    {
        $ballotId = $request->string('ballot')->toString();
        $payload = $storage->readJson("ballots/{$ballotId}.json");

        abort_if($payload === [], 404);

        return Inertia::render('Election/VoterComplete', [
            'ballot' => [
                'ballot_id' => $payload['ballot_id'],
                'paper_ballot_serial' => $payload['paper_ballot_serial'] ?? null,
            ],
        ]);
    }
}
