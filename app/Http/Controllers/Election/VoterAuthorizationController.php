<?php

namespace App\Http\Controllers\Election;

use App\Election\Attestation\OfficerRegistry;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\AnonymousVoterAuthorization;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClaimVoterAuthorizationRequest;
use App\Http\Requests\IssueVoterAuthorizationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class VoterAuthorizationController extends Controller
{
    public function show(ElectionStorage $storage, LifecycleState $lifecycle): Response
    {
        abort_unless($lifecycle->current() === Lifecycle::Voting, 409);

        $configuration = $storage->readJson('runtime/active-precinct.json');

        return Inertia::render('Election/VoterWelcome', [
            'precinct' => [
                'election_id' => $configuration['election_id'] ?? null,
                'precinct_id' => $configuration['precinct_id'] ?? null,
            ],
        ]);
    }

    public function issue(
        IssueVoterAuthorizationRequest $request,
        OfficerRegistry $officers,
        AnonymousVoterAuthorization $authorizations,
        LifecycleState $lifecycle,
    ): RedirectResponse {
        if ($lifecycle->current() !== Lifecycle::Voting) {
            throw ValidationException::withMessages([
                'lifecycle' => 'Voter authorizations may be issued only while polls are open.',
            ]);
        }

        if ($officers->verify(
            $request->validated('officer_code'),
            $request->validated('officer_pin'),
        ) === null) {
            throw ValidationException::withMessages([
                'officer_pin' => 'The officer code or PIN is invalid.',
            ]);
        }

        $currentAuthorization = $request->session()->get('voter_authorization');
        $previousAuthorizationId = is_array($currentAuthorization)
            && is_string($currentAuthorization['authorization_id'] ?? null)
                ? $currentAuthorization['authorization_id']
                : null;
        $request->session()->put(
            'voter_authorization',
            $authorizations->issue($previousAuthorizationId),
        );

        return redirect()->route('election.voting');
    }

    public function claim(
        ClaimVoterAuthorizationRequest $request,
        AnonymousVoterAuthorization $authorizations,
    ): RedirectResponse {
        try {
            $authorization = $authorizations->claim($request->validated('code'));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        $request->session()->put('election.voter_authorization_id', $authorization['authorization_id']);

        return redirect()->route('election.voter.ballot');
    }
}
