<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ActivityJournal;
use App\Election\Counting\TallyPresentation;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Preparation\ActivateConfiguredPrecinct;
use App\Election\Printing\BallotPrinter;
use App\Election\Printing\PrintFormProfile;
use App\Election\Printing\PrintFormProfileResolver;
use App\Election\PublicSimulation\PublicSimulationAdmissionCapacity;
use App\Election\PublicSimulation\PublicSimulationOperationsBoard;
use App\Election\PublicSimulation\PublicSimulationService;
use App\Election\PublicSimulation\PublicSimulationVotingGate;
use App\Election\PublicSimulation\RoleDemoInterimCloseout;
use App\Election\Support\ElectionStorage;
use App\Election\Support\PartyLabelNormalizer;
use App\Election\Voting\AnonymousVoterAuthorization;
use App\Election\Voting\PrivateBallotRelease;
use App\Election\Voting\SealedBallotBox;
use App\Election\Voting\VoterBallotAnalytics;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClaimVoterAuthorizationRequest;
use App\Http\Requests\FinalizePrivateBallotRequest;
use App\Http\Requests\RedeemPrintReleaseRequest;
use App\Models\SimulationPrecinct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class RoleDemoController extends Controller
{
    public function index(PublicSimulationService $simulations): Response
    {
        $precinct = $this->precinct($simulations);

        return Inertia::render('Election/RoleDemoLobby', [
            'precinct' => $this->precinctSummary($precinct),
            'actions' => [
                'officer' => route('election.role-demo.officer'),
                'voter' => route('election.role-demo.voter'),
                'watcher' => route('election.role-demo.watcher'),
                'reset' => route('election.role-demo.reset'),
            ],
        ]);
    }

    public function reset(PublicSimulationService $simulations, ActivityJournal $journal): RedirectResponse
    {
        $round = $simulations->currentRound();
        $simulations->refreshDemoSet();
        $journal->record('role_demo.reset', [
            'previous_round_code' => $round->code,
            'reason' => 'operator-requested-role-pov-refresh',
        ]);

        $this->precinct($simulations);

        return to_route('election.role-demo.index')
            ->with('role_demo.feedback', 'The role POV demo has been reset. The demo precinct is open again.');
    }

    public function officer(
        PublicSimulationService $simulations,
        PublicSimulationAdmissionCapacity $capacity,
        PublicSimulationOperationsBoard $operationsBoard,
        ElectionStorage $storage,
        RoleDemoInterimCloseout $forms,
        Request $request,
    ): Response {
        $precinct = $this->precinct($simulations);
        $tally = $forms->tally();
        $configuration = $storage->readJson('runtime/active-precinct.json');

        return Inertia::render('Election/RoleDemoOfficer', [
            'precinct' => [
                ...$this->precinctSummary($precinct),
                'election_id' => $configuration['election_id'] ?? null,
                'precinct_id' => $configuration['precinct_id'] ?? null,
            ],
            'admission' => $capacity->summary(),
            'operationsBoard' => $operationsBoard->summary(),
            'currentTally' => [
                'accepted_ballots' => $tally['accepted_ballots'],
                'rejected_ballots' => $tally['rejected_ballots'],
                'tally_hash' => $tally['tally_hash'],
            ],
            'controlNumber' => $request->session()->get('role_demo.control_number'),
            'printFeedback' => $request->session()->get('role_demo.print_feedback'),
            'feedback' => $request->session()->get('role_demo.feedback'),
            'actions' => [
                'home' => route('election.role-demo.index'),
                'admit' => route('election.role-demo.admit'),
                'dismissControlNumber' => route('election.role-demo.dismiss-control-number'),
                'acceptPrint' => route('election.role-demo.print.accept'),
                'lastBallot' => route('election.role-demo.print.last-ballot'),
                'tally' => route('election.role-demo.tally-sheet'),
                'return' => route('election.role-demo.election-return'),
                'watcher' => route('election.role-demo.watcher'),
                'reset' => route('election.role-demo.reset'),
            ],
            'printPinDigits' => min(6, max(4, (int) config('election.voter.print_pin_digits', 4))),
        ]);
    }

    public function admit(PublicSimulationService $simulations, PublicSimulationAdmissionCapacity $capacity, AnonymousVoterAuthorization $authorizations, PublicSimulationVotingGate $voting, Request $request): RedirectResponse
    {
        $this->precinct($simulations);

        try {
            $authorization = $voting->execute(fn (): array => $capacity->issue($authorizations));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['admission' => $exception->getMessage()]);
        }

        $request->session()->put('role_demo.control_number', $authorization);

        return to_route('election.role-demo.officer')
            ->with('role_demo.feedback', 'Give this four-digit control number to the next voter.');
    }

    public function dismissControlNumber(Request $request): RedirectResponse
    {
        $request->session()->forget('role_demo.control_number');

        return to_route('election.role-demo.officer');
    }

    public function acceptPrint(RedeemPrintReleaseRequest $request, PublicSimulationService $simulations, PrivateBallotRelease $releases, BallotPrinter $printer, SealedBallotBox $ballotBox, PublicSimulationVotingGate $voting, RoleDemoInterimCloseout $forms): RedirectResponse
    {
        $precinct = $this->precinct($simulations);

        try {
            $result = $voting->execute(function () use ($request, $releases, $printer, $ballotBox, $forms, $precinct): array {
                $release = $releases->redeem($request->validated('code'));
                $releases->print((string) $release['release_id'], $printer);
                $printedPdf = $releases->printedBallotPdfPath((string) $release['release_id']);
                $deposit = $ballotBox->deposit((string) $release['release_id']);
                $forms->generate($precinct, 'role-demo-print-accepted');

                return [
                    'release' => $release,
                    'deposit' => $deposit,
                    'printed_pdf' => $printedPdf,
                ];
            });
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        $request->session()->put('role_demo.last_printed_ballot_pdf', $result['printed_pdf']);
        $request->session()->flash('role_demo.print_feedback', [
            'status' => 'accepted',
            'paper_ballot_serial' => $result['deposit']['paper_ballot_serial'] ?? $result['release']['paper_ballot_serial'] ?? null,
            'message' => 'Ballot PDF generated and sealed VVDAT record deposited. The watcher tally has been refreshed.',
        ]);

        return to_route('election.role-demo.officer');
    }

    public function voter(PublicSimulationService $simulations, ElectionStorage $storage, Request $request): Response
    {
        $this->precinct($simulations);
        $configuration = $storage->readJson('runtime/active-precinct.json');

        return Inertia::render('Election/VoterWelcome', [
            'precinct' => [
                'election_id' => $configuration['election_id'] ?? null,
                'precinct_id' => $configuration['precinct_id'] ?? null,
            ],
            'claimAction' => route('election.role-demo.voter.claim'),
            'demoControlNumberAction' => route('election.role-demo.voter.control-number'),
            'publicSimulation' => true,
            'initialControlNumber' => $this->initialControlNumber($request),
        ]);
    }

    public function controlNumber(PublicSimulationService $simulations, PublicSimulationAdmissionCapacity $capacity, AnonymousVoterAuthorization $authorizations, PublicSimulationVotingGate $voting, ActivityJournal $journal): JsonResponse
    {
        $precinct = $this->precinct($simulations);

        $recycledAuthorization = null;

        try {
            $authorization = $voting->execute(fn (): array => $capacity->issue($authorizations));
        } catch (RuntimeException $exception) {
            $recycledAuthorization = $authorizations->expireOldestIssued('role-demo-self-service-capacity-recycled');

            if ($recycledAuthorization === null) {
                throw ValidationException::withMessages(['control_number' => $exception->getMessage()]);
            }

            $journal->record('role_demo.self_service_control_number_recycled', [
                'authorization_id' => $recycledAuthorization['authorization_id'],
                'precinct_code' => $precinct->code,
                'reason' => 'active admission limit reached',
            ]);

            try {
                $authorization = $voting->execute(fn (): array => $capacity->issue($authorizations));
            } catch (RuntimeException $retryException) {
                throw ValidationException::withMessages(['control_number' => $retryException->getMessage()]);
            }
        }

        $journal->record('role_demo.self_service_control_number_issued', [
            'authorization_id' => $authorization['authorization_id'],
            'precinct_code' => $precinct->code,
            'expires_at' => $authorization['expires_at'],
            'mode' => 'self-service-voter-demo',
            'recycled_authorization_id' => $recycledAuthorization['authorization_id'] ?? null,
        ]);

        return response()->json([
            'code' => $authorization['code'],
            'expires_at' => $authorization['expires_at'],
        ]);
    }

    public function claim(ClaimVoterAuthorizationRequest $request, PublicSimulationService $simulations, AnonymousVoterAuthorization $authorizations, PublicSimulationVotingGate $voting): RedirectResponse
    {
        $this->precinct($simulations);

        try {
            $authorization = $voting->execute(fn (): array => $authorizations->claim($request->validated('code')));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        $request->session()->put('role_demo.authorization', $authorization['authorization_id']);

        return to_route('election.role-demo.voter.ballot');
    }

    public function ballot(PublicSimulationService $simulations, ElectionStorage $storage, AnonymousVoterAuthorization $authorizations, PartyLabelNormalizer $partyLabels, VoterBallotAnalytics $analytics, Request $request): Response
    {
        $this->precinct($simulations);
        $authorizationId = $request->session()->get('role_demo.authorization');
        abort_unless(is_string($authorizationId) && $authorizations->isClaimed($authorizationId), 403);
        $configuration = $storage->readJson('runtime/active-precinct.json');

        return Inertia::render('Election/VoterBallot', [
            'ballot' => [
                'election_id' => $configuration['election_id'] ?? null,
                'precinct_id' => $configuration['precinct_id'] ?? null,
                'ballot_style_id' => $configuration['ballot_style_id'] ?? null,
                'contests' => $this->normalizeContestPartyLabels($configuration['contests'] ?? [], $partyLabels),
            ],
            'finalizeAction' => route('election.role-demo.voter.finalize'),
            'publicSimulation' => true,
            'ballotUiProfile' => 'paper_facsimile',
            'ballotMaxColumns' => 2,
            'selectionTarget' => $this->selectionTarget(),
            'demoRandomFillEnabled' => (bool) config('election.voter.role_demo_random_fill_enabled', true),
            'analytics' => [
                'enabled' => $analytics->enabled(),
                'display_mode' => $analytics->displayMode(),
            ],
        ]);
    }

    public function finalize(FinalizePrivateBallotRequest $request, PublicSimulationService $simulations, AnonymousVoterAuthorization $authorizations, PrivateBallotRelease $releases, PublicSimulationVotingGate $voting, VoterBallotAnalytics $analytics): RedirectResponse
    {
        $precinct = $this->precinct($simulations);
        $authorizationId = $request->session()->get('role_demo.authorization');
        abort_unless(is_string($authorizationId) && $authorizations->isClaimed($authorizationId), 403);
        $selections = collect($request->validated('selections', []))
            ->map(fn (array $candidateIds): array => array_values($candidateIds))
            ->all();

        try {
            $release = $voting->execute(function () use ($authorizationId, $authorizations, $releases, $selections): array {
                $release = $releases->create($authorizationId, $selections);
                $authorizations->complete($authorizationId);

                return $release;
            });
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['selections' => $exception->getMessage()]);
        }

        $request->session()->forget('role_demo.authorization');
        $analyticsSummary = $analytics->record($request->validated('analytics', []), [
            'release_id' => $release['release_id'],
            'precinct_id' => $release['precinct_id'] ?? null,
            'public_simulation_precinct_code' => $precinct->code,
            'ballot_ui_profile' => 'role_demo_two_column',
            'selection_target' => $this->selectionTarget(),
        ]);

        if ($analyticsSummary !== null) {
            $release['analytics'] = $analyticsSummary;
        }

        $request->session()->put('role_demo.release', $release);

        return to_route('election.role-demo.voter.complete');
    }

    public function complete(PublicSimulationService $simulations, Request $request): Response
    {
        $precinct = $this->precinct($simulations);
        $release = $request->session()->get('role_demo.release');

        return Inertia::render('Election/VoterComplete', [
            'release' => is_array($release) ? $release : null,
            'precinctClosed' => false,
            'precinct' => [
                'code' => $precinct->code,
                'label' => $precinct->label,
            ],
            'returnAction' => route('election.role-demo.index'),
            'resetAction' => route('election.role-demo.voter.reset'),
            'demoBallotPreviewEnabled' => (bool) config('election.voter.role_demo_voter_ballot_preview_enabled', true),
            'ballotPreviewAction' => is_array($release) ? route('election.role-demo.voter.complete.ballot-preview') : null,
            'publicSimulation' => true,
        ]);
    }

    public function voterBallotPreview(PublicSimulationService $simulations, PrivateBallotRelease $releases, Request $request): BinaryFileResponse
    {
        abort_unless(config('election.voter.role_demo_voter_ballot_preview_enabled', true), 404);
        $this->precinct($simulations);
        $release = $request->session()->get('role_demo.release');
        abort_unless(is_array($release) && isset($release['release_id']), 404);

        $path = $releases->previewBallotPdfPath((string) $release['release_id']);
        abort_unless($path !== null, 404);

        return response()->file($path, [
            'Content-Disposition' => 'inline; filename="role-demo-voter-ballot-preview.pdf"',
        ]);
    }

    public function resetVoter(PublicSimulationService $simulations, ActivityJournal $journal, Request $request): RedirectResponse
    {
        $precinct = $this->precinct($simulations);
        $release = $request->session()->get('role_demo.release');

        $journal->record('role_demo.booth_reset', [
            'release_id' => is_array($release) ? ($release['release_id'] ?? null) : null,
            'precinct_code' => $precinct->code,
        ]);

        $request->session()->forget([
            'role_demo.authorization',
            'role_demo.release',
        ]);

        return to_route('election.role-demo.voter');
    }

    public function watcher(PublicSimulationService $simulations, ElectionStorage $storage, TallyPresentation $presentation, RoleDemoInterimCloseout $forms): Response
    {
        $precinct = $this->precinct($simulations);
        $tally = $forms->tally();
        $configuration = $storage->readJson('runtime/active-precinct.json');

        return Inertia::render('Election/RoleDemoWatcher', [
            'precinct' => [
                ...$this->precinctSummary($precinct),
                'accepted_ballots' => $tally['accepted_ballots'],
                'rejected_ballots' => $tally['rejected_ballots'],
                'tally_hash' => $tally['tally_hash'],
                'display_tally' => $presentation->displayTally((array) ($tally['tally'] ?? [])),
            ],
            'ballot' => [
                'contests' => collect($configuration['contests'] ?? [])
                    ->map(fn (array $contest): array => [
                        'id' => $contest['id'],
                        'title' => $contest['title'],
                        'candidates' => collect($contest['candidates'])
                            ->map(fn (array $candidate): array => [
                                'id' => $candidate['id'],
                                'name' => $candidate['name'],
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
            ],
            'downloads' => [
                'tally' => route('election.role-demo.tally-sheet'),
                'return' => route('election.role-demo.election-return'),
            ],
        ]);
    }

    public function tallySheet(PublicSimulationService $simulations, ElectionStorage $storage, PrintFormProfileResolver $profiles, RoleDemoInterimCloseout $forms, ?string $profile = null): BinaryFileResponse
    {
        $precinct = $this->precinct($simulations);
        $forms->generate($precinct, 'role-demo-tally-download');
        $resolved = $profiles->from($profile ?? PrintFormProfile::A4->value);
        $path = $storage->path("print-forms/tally-sheet/{$resolved->value}.pdf");
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Disposition' => 'inline; filename="'.$precinct->code.'-'.$resolved->value.'-interim-tally-sheet.pdf"',
        ]);
    }

    public function electionReturn(PublicSimulationService $simulations, ElectionStorage $storage, PrintFormProfileResolver $profiles, RoleDemoInterimCloseout $forms, ?string $profile = null): BinaryFileResponse
    {
        $precinct = $this->precinct($simulations);
        $forms->generate($precinct, 'role-demo-return-download');
        $configuration = $storage->readJson('runtime/active-precinct.json');
        $precinctId = (string) ($configuration['precinct_id'] ?? '');
        $resolved = $profiles->from($profile ?? PrintFormProfile::A4->value);
        $path = $storage->path("print-forms/election-return/{$precinctId}/{$resolved->value}.pdf");
        abort_unless($precinctId !== '' && is_file($path), 404);

        return response()->file($path, [
            'Content-Disposition' => 'inline; filename="'.$precinct->code.'-'.$resolved->value.'-interim-election-return.pdf"',
        ]);
    }

    public function lastPrintedBallot(Request $request): BinaryFileResponse
    {
        $path = $request->session()->get('role_demo.last_printed_ballot_pdf');
        abort_unless(is_string($path) && is_file($path), 404);

        return response()->file($path, [
            'Content-Disposition' => 'inline; filename="role-demo-last-printed-ballot.pdf"',
        ]);
    }

    private function precinct(PublicSimulationService $simulations): SimulationPrecinct
    {
        abort_unless(config('election.public_simulation.enabled'), 404);

        $round = $simulations->currentRound();
        $precinct = $round->precincts()->orderBy('id')->firstOrFail();

        if ($precinct->status !== 'open') {
            $precinct = $simulations->open($precinct, $precinct->officer_code, '123456');
        } else {
            $simulations->applyScope($precinct);
        }

        $this->ensurePrecinctPackageActivated($precinct);
        app(LifecycleState::class)->set(Lifecycle::Voting);

        return $precinct->fresh('round');
    }

    private function ensurePrecinctPackageActivated(SimulationPrecinct $precinct): void
    {
        $storage = app(ElectionStorage::class);
        $configuration = $storage->readJson('runtime/active-precinct.json');
        $contests = $configuration['contests'] ?? [];
        $candidateCount = collect(is_array($contests) ? $contests : [])
            ->sum(fn (array $contest): int => count($contest['candidates'] ?? []));

        if (
            ($configuration['precinct_id'] ?? null) === $precinct->clustered_precinct
            && is_array($contests)
            && count($contests) > 0
            && $candidateCount > 0
        ) {
            return;
        }

        app(ActivateConfiguredPrecinct::class)->handle();
    }

    /**
     * @param  array<int, array<string, mixed>>  $contests
     * @return array<int, array<string, mixed>>
     */
    private function normalizeContestPartyLabels(array $contests, PartyLabelNormalizer $partyLabels): array
    {
        return collect($contests)
            ->map(function (array $contest) use ($partyLabels): array {
                $contest['candidates'] = collect($contest['candidates'] ?? [])
                    ->map(function (array $candidate) use ($partyLabels): array {
                        $candidate['political_party'] = $partyLabels->normalize($candidate['political_party'] ?? null);

                        return $candidate;
                    })
                    ->all();

                return $contest;
            })
            ->all();
    }

    /** @return array<string, mixed> */
    private function precinctSummary(SimulationPrecinct $precinct): array
    {
        return [
            'code' => $precinct->code,
            'label' => $precinct->label,
            'clustered_precinct' => $precinct->clustered_precinct,
            'city_municipality' => $precinct->city_municipality,
            'province' => $precinct->province,
            'status' => $precinct->status,
        ];
    }

    private function selectionTarget(): string
    {
        $target = config('election.voter.selection_target', 'circle');

        return in_array($target, ['circle', 'circle_with_label', 'row'], true)
            ? $target
            : 'circle';
    }

    private function initialControlNumber(Request $request): ?string
    {
        $code = $request->query('code');

        if (! is_string($code) || preg_match('/^[0-9]{4}$/', $code) !== 1) {
            return null;
        }

        return $code;
    }
}
