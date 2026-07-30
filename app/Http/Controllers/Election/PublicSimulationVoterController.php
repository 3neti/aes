<?php

namespace App\Http\Controllers\Election;

use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Printing\BallotPrinter;
use App\Election\PublicSimulation\PublicSimulationAdmissionQueue;
use App\Election\PublicSimulation\PublicSimulationService;
use App\Election\PublicSimulation\PublicSimulationVotingGate;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\AnonymousVoterAuthorization;
use App\Election\Voting\PrivateBallotRelease;
use App\Election\Voting\SealedBallotBox;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClaimVoterAuthorizationRequest;
use App\Http\Requests\FinalizePrivateBallotRequest;
use App\Http\Requests\RedeemPrintReleaseRequest;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class PublicSimulationVoterController extends Controller
{
    public function show(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, ElectionStorage $storage, LifecycleState $lifecycle, PublicSimulationAdmissionQueue $queue): Response
    {
        $this->scope($round, $precinct, $simulations);
        abort_unless($lifecycle->current() === Lifecycle::Voting, 409);
        $configuration = $storage->readJson('runtime/active-precinct.json');

        return Inertia::render('Election/VoterWelcome', [
            'precinct' => [
                'election_id' => $configuration['election_id'] ?? null,
                'precinct_id' => $configuration['precinct_id'] ?? null,
            ],
            'claimAction' => route('election.public-simulation.voter.claim', [$round, $precinct]),
            'joinQueueAction' => route('election.public-simulation.voter.join-queue', [$round, $precinct]),
            'admissionQueue' => $queue->status($request->session()->get($this->queueSessionKey($precinct))),
            'publicSimulation' => true,
        ]);
    }

    public function joinQueue(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PublicSimulationAdmissionQueue $queue, PublicSimulationVotingGate $voting): RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);

        try {
            $ticket = $voting->execute(fn (): array => $queue->join($request->session()->get($this->queueSessionKey($precinct))));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['queue' => $exception->getMessage()]);
        }

        $request->session()->put($this->queueSessionKey($precinct), $ticket['ticket_id']);

        return to_route('election.public-simulation.voter.show', [$round, $precinct]);
    }

    public function claim(ClaimVoterAuthorizationRequest $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, AnonymousVoterAuthorization $authorizations, PublicSimulationVotingGate $voting): RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);

        try {
            $authorization = $voting->execute(fn (): array => $authorizations->claim($request->validated('code')));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        $request->session()->put($this->authorizationSessionKey($precinct), $authorization['authorization_id']);

        return to_route('election.public-simulation.voter.ballot', [$round, $precinct]);
    }

    public function ballot(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, ElectionStorage $storage, LifecycleState $lifecycle, AnonymousVoterAuthorization $authorizations): Response
    {
        $this->scope($round, $precinct, $simulations);
        abort_unless($lifecycle->current() === Lifecycle::Voting, 409);
        $authorizationId = $request->session()->get($this->authorizationSessionKey($precinct));
        abort_unless(is_string($authorizationId) && $authorizations->isClaimed($authorizationId), 403);
        $configuration = $storage->readJson('runtime/active-precinct.json');

        return Inertia::render('Election/VoterBallot', [
            'ballot' => [
                'election_id' => $configuration['election_id'] ?? null,
                'precinct_id' => $configuration['precinct_id'] ?? null,
                'ballot_style_id' => $configuration['ballot_style_id'] ?? null,
                'contests' => $configuration['contests'] ?? [],
            ],
            'finalizeAction' => route('election.public-simulation.voter.finalize', [$round, $precinct]),
            'publicSimulation' => true,
        ]);
    }

    public function finalize(FinalizePrivateBallotRequest $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, AnonymousVoterAuthorization $authorizations, PrivateBallotRelease $releases, LifecycleState $lifecycle, PublicSimulationVotingGate $voting): RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);
        $authorizationId = $request->session()->get($this->authorizationSessionKey($precinct));
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

        $request->session()->forget($this->authorizationSessionKey($precinct));
        $request->session()->put($this->releaseSessionKey($precinct), $release);

        return to_route('election.public-simulation.voter.complete', [$round, $precinct]);
    }

    public function complete(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations): Response
    {
        $this->scope($round, $precinct, $simulations);
        $release = $request->session()->get($this->releaseSessionKey($precinct));
        abort_unless(is_array($release), 404);

        return Inertia::render('Election/VoterComplete', [
            'release' => $release,
            'publicSimulation' => true,
        ]);
    }

    public function printStation(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PrivateBallotRelease $releases, LifecycleState $lifecycle): Response
    {
        $this->scope($round, $precinct, $simulations);
        abort_unless($lifecycle->current() === Lifecycle::Voting, 409);
        $releaseId = $request->session()->get($this->printSessionKey($precinct));

        return Inertia::render('Election/PrintStation', [
            'release' => is_string($releaseId) ? $releases->find($releaseId) : [],
            'depositFeedback' => $request->session()->get($this->depositSessionKey($precinct)),
            'actions' => [
                'redeem' => route('election.public-simulation.print.redeem', [$round, $precinct]),
                'print' => route('election.public-simulation.print.print', [$round, $precinct]),
                'deposit' => route('election.public-simulation.print.deposit', [$round, $precinct]),
            ],
            'publicSimulation' => true,
        ]);
    }

    public function redeem(RedeemPrintReleaseRequest $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PrivateBallotRelease $releases, PublicSimulationVotingGate $voting): RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);

        try {
            $release = $voting->execute(fn (): array => $releases->redeem($request->validated('code')));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        $request->session()->put($this->printSessionKey($precinct), $release['release_id']);

        return to_route('election.public-simulation.print.station', [$round, $precinct]);
    }

    public function print(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PrivateBallotRelease $releases, BallotPrinter $printer, PublicSimulationVotingGate $voting): RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);

        try {
            $voting->execute(fn (): array => $releases->print($this->printReleaseId($request, $precinct), $printer));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['printer' => $exception->getMessage()]);
        }

        return to_route('election.public-simulation.print.station', [$round, $precinct]);
    }

    public function deposit(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, SealedBallotBox $ballotBox, PublicSimulationVotingGate $voting): RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);

        try {
            $record = $voting->execute(fn (): array => $ballotBox->deposit($this->printReleaseId($request, $precinct)));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['deposit' => $exception->getMessage()]);
        }

        $request->session()->forget($this->printSessionKey($precinct));
        $request->session()->flash($this->depositSessionKey($precinct), [
            'status' => 'accepted',
            'paper_ballot_serial' => $record['paper_ballot_serial'],
        ]);

        return to_route('election.public-simulation.print.station', [$round, $precinct]);
    }

    private function scope(SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations): void
    {
        abort_unless($precinct->simulation_round_id === $round->id, 404);
        $simulations->applyScope($precinct);
    }

    private function authorizationSessionKey(SimulationPrecinct $precinct): string
    {
        return "public_simulation.{$precinct->id}.authorization";
    }

    private function releaseSessionKey(SimulationPrecinct $precinct): string
    {
        return "public_simulation.{$precinct->id}.release";
    }

    private function queueSessionKey(SimulationPrecinct $precinct): string
    {
        return "public_simulation.{$precinct->id}.admission_queue_ticket";
    }

    private function printSessionKey(SimulationPrecinct $precinct): string
    {
        return "public_simulation.{$precinct->id}.print_release";
    }

    private function depositSessionKey(SimulationPrecinct $precinct): string
    {
        return "public_simulation.{$precinct->id}.deposit_feedback";
    }

    private function printReleaseId(Request $request, SimulationPrecinct $precinct): string
    {
        $releaseId = $request->session()->get($this->printSessionKey($precinct));
        abort_unless(is_string($releaseId), 403);

        return $releaseId;
    }
}
