<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\PublicSimulation\PublicSimulationAdmissionCapacity;
use App\Election\PublicSimulation\PublicSimulationAdmissionIntake;
use App\Election\PublicSimulation\PublicSimulationAdmissionQueue;
use App\Election\PublicSimulation\PublicSimulationCloseout;
use App\Election\PublicSimulation\PublicSimulationContentionReport;
use App\Election\PublicSimulation\PublicSimulationOperationalObservation;
use App\Election\PublicSimulation\PublicSimulationPublication;
use App\Election\PublicSimulation\PublicSimulationService;
use App\Election\PublicSimulation\PublicSimulationVotingGate;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\AnonymousVoterAuthorization;
use App\Election\Voting\StandardQrCode;
use App\Http\Controllers\Controller;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class PublicSimulationController extends Controller
{
    public function index(PublicSimulationService $simulations): Response
    {
        abort_unless(config('election.public_simulation.enabled'), 404);

        return Inertia::render('Election/PublicSimulationLobby', [
            'round' => $this->round($simulations->currentRound()),
        ]);
    }

    public function show(
        SimulationRound $round,
        SimulationPrecinct $precinct,
        PublicSimulationService $simulations,
        ElectionStorage $storage,
        ElectionSnapshot $snapshot,
        StandardQrCode $qrCode,
        PublicSimulationAdmissionCapacity $capacity,
        PublicSimulationAdmissionQueue $queue,
        PublicSimulationContentionReport $contentionReport,
        PublicSimulationOperationalObservation $observations,
        Request $request,
    ): Response {
        $this->ensurePrecinctInRound($round, $precinct);
        $simulations->applyScope($precinct);

        return Inertia::render('Election/PublicSimulationPrecinct', [
            'round' => $this->round($round),
            'precinct' => $this->precinct($precinct, $storage, $snapshot),
            'admission' => [
                ...$capacity->summary(),
                'queue' => $queue->summary(),
            ],
            'contention' => $contentionReport->summary(),
            'operationalObservations' => $observations->summary(),
            'commonVoterUrl' => route('election.public-simulation.voter.show', [$round, $precinct]),
            'commonVoterQr' => 'data:image/png;base64,'.base64_encode($qrCode->renderPng(route('election.public-simulation.voter.show', [$round, $precinct]))),
            'actions' => [
                'open' => route('election.public-simulation.open', [$round, $precinct]),
                'admit' => route('election.public-simulation.admit', [$round, $precinct]),
                'admitQueued' => route('election.public-simulation.admit-queued', [$round, $precinct]),
                'admissionIntake' => route('election.public-simulation.admission-intake', [$round, $precinct]),
                'contentionReport' => route('election.public-simulation.contention-report', [$round, $precinct]),
                'observation' => route('election.public-simulation.observation', [$round, $precinct]),
                'close' => route('election.public-simulation.close', [$round, $precinct]),
                'publish' => route('election.public-simulation.publish', [$round, $precinct]),
                'audit' => route('election.public-simulation.audit.show', [$round, $precinct]),
                'print' => route('election.public-simulation.print.station', [$round, $precinct]),
                'watch' => route('election.public-simulation.watcher.show', [$round, $precinct]),
            ],
            'officerFeedback' => $request->session()->get('public_simulation.officer_feedback'),
            'controlNumber' => $request->session()->get('public_simulation.control_number'),
        ]);
    }

    public function open(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations): RedirectResponse
    {
        $this->ensurePrecinctInRound($round, $precinct);
        $validated = $this->officerCredentials($request);

        try {
            $simulations->open($precinct, $validated['officer_code'], $validated['officer_pin']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['officer_pin' => $exception->getMessage()]);
        }

        return to_route('election.public-simulation.show', [$round, $precinct])
            ->with('public_simulation.officer_feedback', 'Polls are open. Admit a voter and hand them the four-digit control number.');
    }

    public function admit(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PublicSimulationAdmissionCapacity $capacity, AnonymousVoterAuthorization $authorizations, PublicSimulationVotingGate $voting): RedirectResponse
    {
        $this->ensurePrecinctInRound($round, $precinct);
        $validated = $this->officerCredentials($request);

        try {
            $simulations->verifyOfficer($precinct, $validated['officer_code'], $validated['officer_pin']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['officer_pin' => $exception->getMessage()]);
        }

        if ($precinct->status !== 'open') {
            throw ValidationException::withMessages(['officer_pin' => 'Open the precinct before admitting voters.']);
        }

        $simulations->applyScope($precinct);
        try {
            $authorization = $voting->execute(fn (): array => $capacity->issue($authorizations));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['officer_pin' => $exception->getMessage()]);
        }

        return to_route('election.public-simulation.show', [$round, $precinct])
            ->with('public_simulation.control_number', $authorization);
    }

    public function admitQueued(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PublicSimulationAdmissionQueue $queue, AnonymousVoterAuthorization $authorizations, PublicSimulationVotingGate $voting): RedirectResponse
    {
        $this->ensurePrecinctInRound($round, $precinct);
        $validated = $this->officerCredentials($request);

        try {
            $simulations->verifyOfficer($precinct, $validated['officer_code'], $validated['officer_pin']);

            if ($precinct->status !== 'open') {
                throw new RuntimeException('Open the precinct before admitting voters.');
            }

            $simulations->applyScope($precinct);
            $release = $voting->execute(fn (): array => $queue->releaseNext($authorizations));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['officer_pin' => $exception->getMessage()]);
        }

        return to_route('election.public-simulation.show', [$round, $precinct])
            ->with('public_simulation.control_number', $release['authorization'])
            ->with('public_simulation.officer_feedback', "Waiting ticket {$release['ticket']['ticket_number']} has been admitted. Hand over the four-digit control number.");
    }

    public function generateContentionReport(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PublicSimulationContentionReport $contentionReport): RedirectResponse
    {
        $this->ensurePrecinctInRound($round, $precinct);
        $validated = $this->officerCredentials($request);

        try {
            $simulations->verifyOfficer($precinct, $validated['officer_code'], $validated['officer_pin']);
            $simulations->applyScope($precinct);
            $report = $contentionReport->generate();
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['officer_pin' => $exception->getMessage()]);
        }

        return to_route('election.public-simulation.show', [$round, $precinct])
            ->with('public_simulation.officer_feedback', "Redacted contention report {$report['sequence']} has been recorded in the voting evidence bundle.");
    }

    public function updateAdmissionIntake(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PublicSimulationAdmissionIntake $intake): RedirectResponse
    {
        $this->ensurePrecinctInRound($round, $precinct);
        $validated = [
            ...$this->officerCredentials($request),
            ...$request->validate(['operation' => ['required', 'in:pause,resume']]),
        ];

        try {
            $simulations->verifyOfficer($precinct, $validated['officer_code'], $validated['officer_pin']);
            $simulations->applyScope($precinct);
            $status = $validated['operation'] === 'pause' ? $intake->pause() : $intake->resume();
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['officer_pin' => $exception->getMessage()]);
        }

        $message = $status['status'] === 'paused'
            ? 'New anonymous waiting tickets are paused. Existing tickets and issued control numbers remain valid.'
            : 'New anonymous waiting tickets are open again.';

        return to_route('election.public-simulation.show', [$round, $precinct])
            ->with('public_simulation.officer_feedback', $message);
    }

    public function recordOperationalObservation(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PublicSimulationOperationalObservation $observations): RedirectResponse
    {
        $this->ensurePrecinctInRound($round, $precinct);
        $validated = [
            ...$this->officerCredentials($request),
            ...$request->validate([
                'reported_role' => ['required', 'in:election_officer,voter,watcher,facilitator'],
                'ceremony' => ['required', 'in:admission,voting,printing,closeout,results,audit'],
                'assessment' => ['required', 'in:clear,needs_attention,blocking'],
                'note' => ['required', 'string', 'max:800'],
            ]),
        ];

        try {
            $simulations->verifyOfficer($precinct, $validated['officer_code'], $validated['officer_pin']);

            if ($precinct->status !== 'published') {
                throw new RuntimeException('Operational observations are recorded after watcher publication.');
            }

            $simulations->applyScope($precinct);
            $observation = $observations->record(
                $validated['reported_role'],
                $validated['ceremony'],
                $validated['assessment'],
                $validated['note'],
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['officer_pin' => $exception->getMessage()]);
        }

        return to_route('election.public-simulation.show', [$round, $precinct])
            ->with('public_simulation.officer_feedback', "Operational observation {$observation['sequence']} has been recorded in the audit evidence bundle.");
    }

    public function close(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PublicSimulationCloseout $closeout): RedirectResponse
    {
        $this->ensurePrecinctInRound($round, $precinct);
        $validated = $this->officerCredentials($request);

        try {
            $simulations->verifyOfficer($precinct, $validated['officer_code'], $validated['officer_pin']);
            $simulations->applyScope($precinct);
            $closeout->close($precinct, $validated['officer_code'], $validated['officer_pin']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['officer_pin' => $exception->getMessage()]);
        }

        return to_route('election.public-simulation.show', [$round, $precinct])
            ->with('public_simulation.officer_feedback', 'Polls are closed. Review the VVDAT freeze, tally, and Election Return before publishing the watcher package.');
    }

    public function publish(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PublicSimulationPublication $publication): RedirectResponse
    {
        $this->ensurePrecinctInRound($round, $precinct);
        $validated = $this->officerCredentials($request);

        try {
            $simulations->verifyOfficer($precinct, $validated['officer_code'], $validated['officer_pin']);
            $simulations->applyScope($precinct);
            $publication->publish($precinct);
            $precinct->forceFill(['status' => 'published'])->save();
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['officer_pin' => $exception->getMessage()]);
        }

        return to_route('election.public-simulation.show', [$round, $precinct])
            ->with('public_simulation.officer_feedback', 'The watcher publication manifest is sealed and post-close result artifacts are available.');
    }

    /** @return array{officer_code: string, officer_pin: string} */
    private function officerCredentials(Request $request): array
    {
        return $request->validate([
            'officer_code' => ['required', 'string', 'max:32'],
            'officer_pin' => ['required', 'digits:6'],
        ]);
    }

    private function ensurePrecinctInRound(SimulationRound $round, SimulationPrecinct $precinct): void
    {
        abort_unless($precinct->simulation_round_id === $round->id, 404);
    }

    /** @return array<string, mixed> */
    private function round(SimulationRound $round): array
    {
        return [
            'code' => $round->code,
            'name' => $round->name,
            'status' => $round->status,
            'precincts' => $round->precincts->map(fn (SimulationPrecinct $precinct): array => [
                'code' => $precinct->code,
                'label' => $precinct->label,
                'city_municipality' => $precinct->city_municipality,
                'province' => $precinct->province,
                'status' => $precinct->status,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function precinct(SimulationPrecinct $precinct, ElectionStorage $storage, ElectionSnapshot $snapshot): array
    {
        $configuration = $storage->readJson('runtime/active-precinct.json');
        $return = $configuration === [] ? [] : $storage->readJson("returns/{$configuration['precinct_id']}-return.json");
        $publication = $storage->readJson('returns/publication-manifest.json');

        return [
            'code' => $precinct->code,
            'label' => $precinct->label,
            'clustered_precinct' => $precinct->clustered_precinct,
            'city_municipality' => $precinct->city_municipality,
            'province' => $precinct->province,
            'status' => $precinct->status,
            'officer_name' => $precinct->officer_name,
            'snapshot' => $configuration === [] ? null : $snapshot->get(),
            'tally_available' => $publication !== [],
            'accepted_ballots' => $return['accepted_ballots'] ?? null,
        ];
    }
}
