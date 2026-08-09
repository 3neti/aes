<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ActivityJournal;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\AnonymousVoterAuthorization;
use App\Election\Voting\PrivateBallotRelease;
use App\Election\Voting\VoterBallotAnalytics;
use App\Http\Controllers\Controller;
use App\Http\Requests\FinalizePrivateBallotRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class VoterBallotController extends Controller
{
    public function show(
        Request $request,
        ElectionStorage $storage,
        LifecycleState $lifecycle,
        AnonymousVoterAuthorization $authorizations,
        VoterBallotAnalytics $analytics,
    ): Response {
        abort_unless($lifecycle->current() === Lifecycle::Voting, 409);
        $authorizationId = $request->session()->get('election.voter_authorization_id');
        abort_unless(is_string($authorizationId) && $authorizations->isClaimed($authorizationId), 403);

        $configuration = $storage->readJson('runtime/active-precinct.json');

        return Inertia::render('Election/VoterBallot', [
            'ballot' => [
                'election_id' => $configuration['election_id'] ?? null,
                'precinct_id' => $configuration['precinct_id'] ?? null,
                'ballot_style_id' => $configuration['ballot_style_id'] ?? null,
                'contests' => $configuration['contests'] ?? [],
            ],
            'ballotUiProfile' => $this->ballotUiProfile(),
            'selectionTarget' => $this->selectionTarget(),
            'analytics' => $this->analyticsProps($analytics),
        ]);
    }

    public function finalize(
        FinalizePrivateBallotRequest $request,
        AnonymousVoterAuthorization $authorizations,
        PrivateBallotRelease $releases,
        VoterBallotAnalytics $analytics,
        LifecycleState $lifecycle,
    ): RedirectResponse {
        if ($lifecycle->current() !== Lifecycle::Voting) {
            throw ValidationException::withMessages([
                'lifecycle' => 'The voter ballot is available only while polls are open.',
            ]);
        }

        $authorizationId = $request->session()->get('election.voter_authorization_id');

        if (! is_string($authorizationId) || ! $authorizations->isClaimed($authorizationId)) {
            abort(403);
        }

        $validated = $request->validated();
        $selections = collect($validated['selections'] ?? [])
            ->map(fn (array $candidateIds): array => array_values($candidateIds))
            ->all();

        try {
            $release = $releases->create($authorizationId, $selections);
            $authorizations->complete($authorizationId);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['selections' => $exception->getMessage()]);
        }

        $request->session()->forget('election.voter_authorization_id');
        $analyticsSummary = $analytics->record($validated['analytics'] ?? [], [
            'release_id' => $release['release_id'],
            'precinct_id' => $release['precinct_id'] ?? null,
            'ballot_ui_profile' => $this->ballotUiProfile(),
            'selection_target' => $this->selectionTarget(),
        ]);

        if ($analyticsSummary !== null) {
            $release['analytics'] = $analyticsSummary;
        }

        $request->session()->put('election.voter_print_release', $release);

        return redirect()->route('election.voter.complete');
    }

    public function complete(Request $request): Response
    {
        $release = $request->session()->get('election.voter_print_release');

        abort_unless(is_array($release), 404);

        return Inertia::render('Election/VoterComplete', [
            'release' => $release,
            'resetAction' => route('election.voter.reset'),
        ]);
    }

    public function reset(Request $request, ActivityJournal $journal): RedirectResponse
    {
        $release = $request->session()->get('election.voter_print_release');

        $journal->record('voting.booth.reset', [
            'release_id' => is_array($release) ? ($release['release_id'] ?? null) : null,
            'reason' => 'next-voter',
            'pending_print_authorization_preserved' => true,
        ]);

        $request->session()->forget([
            'election.voter_authorization_id',
            'election.voter_print_release',
        ]);

        return redirect()->route('election.voter');
    }

    private function ballotUiProfile(): string
    {
        $profile = config('election.voter.ballot_ui_profile', 'paper_facsimile');

        return in_array($profile, ['touch_guided', 'paper_facsimile'], true)
            ? $profile
            : 'paper_facsimile';
    }

    private function selectionTarget(): string
    {
        $target = config('election.voter.selection_target', 'circle');

        return in_array($target, ['circle', 'circle_with_label', 'row'], true)
            ? $target
            : 'circle';
    }

    /**
     * @return array<string, mixed>
     */
    private function analyticsProps(VoterBallotAnalytics $analytics): array
    {
        return [
            'enabled' => $analytics->enabled(),
            'display_mode' => $analytics->displayMode(),
        ];
    }
}
