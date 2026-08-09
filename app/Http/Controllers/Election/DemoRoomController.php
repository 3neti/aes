<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\PublicSimulation\DemoRoomForceCloseout;
use App\Election\PublicSimulation\PublicSimulationAdmissionCapacity;
use App\Election\PublicSimulation\PublicSimulationAdmissionQueue;
use App\Election\PublicSimulation\PublicSimulationCloseout;
use App\Election\PublicSimulation\PublicSimulationContentionReport;
use App\Election\PublicSimulation\PublicSimulationOperationalObservation;
use App\Election\PublicSimulation\PublicSimulationOperationsBoard;
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

final class DemoRoomController extends Controller
{
    public function index(PublicSimulationService $simulations): Response
    {
        abort_unless(config('election.public_simulation.enabled'), 404);

        return Inertia::render('Election/DemoRoomLobby', [
            'round' => $this->round($simulations->currentRound()),
        ]);
    }

    public function refresh(PublicSimulationService $simulations): RedirectResponse
    {
        abort_unless(config('election.public_simulation.enabled'), 404);

        $result = $simulations->refreshDemoSet();
        $archived = $result['archived'];
        $fresh = $result['fresh'];
        $message = $archived instanceof SimulationRound
            ? "Archived {$archived->code}. Fresh demo set {$fresh->code} is ready."
            : "Fresh demo set {$fresh->code} is ready.";

        return to_route('election.demo-room.index')
            ->with('public_simulation.officer_feedback', $message);
    }

    public function show(
        SimulationRound $round,
        SimulationPrecinct $precinct,
        PublicSimulationService $simulations,
        StandardQrCode $qrCode,
    ): Response {
        $this->scope($round, $precinct, $simulations);

        return Inertia::render('Election/DemoRoomPrecinct', [
            'round' => $this->round($round),
            'precinct' => $this->precinctSummary($precinct),
            'roles' => [
                $this->role('Election Officer', 'Open, admit, close, publish, and hand off.', route('election.demo-room.officer', [$round, $precinct]), $qrCode),
                $this->role('Voter', 'Enter the officer-issued control number and cast the ballot in a booth.', route('election.public-simulation.voter.show', [$round, $precinct]), $qrCode),
                $this->role('Printing Station', 'Enable the printer once, redeem voter print PINs, then print closeout forms.', route('election.demo-room.print.station', [$round, $precinct]), $qrCode),
                $this->role('Poll Watcher', 'Inspect public tally, Election Return, VVDAT export, and logs after publication.', route('election.public-simulation.watcher.show', [$round, $precinct]), $qrCode),
                $this->role('Auditor', 'Run the random manual audit and record discrepancies.', route('election.public-simulation.audit.show', [$round, $precinct]), $qrCode),
            ],
            'officerDefaults' => $this->officerDefaults($precinct),
        ]);
    }

    public function officer(
        SimulationRound $round,
        SimulationPrecinct $precinct,
        PublicSimulationService $simulations,
        ElectionStorage $storage,
        ElectionSnapshot $snapshot,
        PublicSimulationAdmissionCapacity $capacity,
        PublicSimulationAdmissionQueue $queue,
        PublicSimulationContentionReport $contentionReport,
        PublicSimulationOperationalObservation $observations,
        PublicSimulationOperationsBoard $operationsBoard,
        StandardQrCode $qrCode,
        Request $request,
    ): Response {
        $this->scope($round, $precinct, $simulations);
        $controlNumber = $request->session()->get($this->controlNumberSessionKey($precinct));

        return Inertia::render('Election/DemoRoomOfficer', [
            'round' => $this->round($round),
            'precinct' => $this->precinct($precinct, $storage, $snapshot),
            'admission' => [
                ...$capacity->summary(),
                'queue' => $queue->summary(),
            ],
            'contention' => $contentionReport->summary(),
            'operationalObservations' => $observations->summary(),
            'operationsBoard' => $operationsBoard->summary(),
            'actions' => [
                'open' => route('election.demo-room.open', [$round, $precinct]),
                'admit' => route('election.demo-room.admit', [$round, $precinct]),
                'dismissControlNumber' => route('election.demo-room.dismiss-control-number', [$round, $precinct]),
                'admitQueued' => route('election.public-simulation.admit-queued', [$round, $precinct]),
                'admissionIntake' => route('election.public-simulation.admission-intake', [$round, $precinct]),
                'contentionReport' => route('election.public-simulation.contention-report', [$round, $precinct]),
                'close' => route('election.demo-room.close', [$round, $precinct]),
                'forceClose' => route('election.demo-room.force-close', [$round, $precinct]),
                'publish' => route('election.demo-room.publish', [$round, $precinct]),
                'roles' => route('election.demo-room.show', [$round, $precinct]),
                'print' => route('election.demo-room.print.station', [$round, $precinct]),
                'watch' => route('election.public-simulation.watcher.show', [$round, $precinct]),
                'handoff' => route('election.demo-room.handoff', [$round, $precinct]),
            ],
            'officerDefaults' => $this->officerDefaults($precinct),
            'officerFeedback' => $request->session()->get('public_simulation.officer_feedback'),
            'controlNumber' => $this->controlNumber(
                is_array($controlNumber) ? $controlNumber : null,
                $round,
                $precinct,
                $qrCode,
            ),
        ]);
    }

    public function open(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations): RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);
        $validated = $this->officerCredentials($request);

        try {
            $simulations->open($precinct, $validated['officer_code'], $validated['officer_pin']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['officer_pin' => $exception->getMessage()]);
        }

        return to_route('election.demo-room.officer', [$round, $precinct])
            ->with('public_simulation.officer_feedback', 'Polls are open. Admit a voter and hand them the four-digit control number.');
    }

    public function admit(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PublicSimulationAdmissionCapacity $capacity, AnonymousVoterAuthorization $authorizations, PublicSimulationVotingGate $voting): RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);
        $validated = $this->officerCredentials($request);

        try {
            $simulations->verifyOfficer($precinct, $validated['officer_code'], $validated['officer_pin']);

            if ($precinct->status !== 'open') {
                throw new RuntimeException('Open the precinct before admitting voters.');
            }

            $authorization = $voting->execute(fn (): array => $capacity->issue($authorizations));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['officer_pin' => $exception->getMessage()]);
        }

        $request->session()->put($this->controlNumberSessionKey($precinct), $authorization);
        $request->session()->put('public_simulation.control_number', $authorization);

        return to_route('election.demo-room.officer', [$round, $precinct]);
    }

    public function dismissControlNumber(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations): RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);
        $request->session()->forget([
            $this->controlNumberSessionKey($precinct),
            'public_simulation.control_number',
        ]);

        return to_route('election.demo-room.officer', [$round, $precinct]);
    }

    public function close(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PublicSimulationCloseout $closeout): RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);
        $validated = $this->officerCredentials($request);

        try {
            $simulations->verifyOfficer($precinct, $validated['officer_code'], $validated['officer_pin']);
            $closeout->close($precinct, $validated['officer_code'], $validated['officer_pin']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['officer_pin' => $exception->getMessage()]);
        }

        return to_route('election.demo-room.officer', [$round, $precinct])
            ->with('public_simulation.officer_feedback', 'Polls are closed. The VVDAT ledger is frozen and the tally/Election Return are ready for publication.');
    }

    public function forceClose(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, DemoRoomForceCloseout $closeout): RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);
        $validated = [
            ...$this->officerCredentials($request),
            ...$request->validate([
                'confirm_force_closeout' => ['required', 'in:FINALIZE'],
            ]),
        ];

        try {
            $simulations->verifyOfficer($precinct, $validated['officer_code'], $validated['officer_pin']);
            $result = $closeout->close($precinct, $validated['officer_code'], $validated['officer_pin']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['officer_pin' => $exception->getMessage()]);
        }

        $resolved = $result['resolved'];

        return to_route('election.demo-room.officer', [$round, $precinct])
            ->with('public_simulation.officer_feedback', "Polls are closed after finalizing demo work: {$resolved['cancelled_unfinished_booths']} unfinished booth(s) cancelled, {$resolved['auto_printed_ballots']} finalized ballot(s) printed, and {$resolved['auto_deposited_ballots']} paper ballot(s) deposited. The tally and Election Return are ready.");
    }

    public function publish(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PublicSimulationPublication $publication): RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);
        $validated = $this->officerCredentials($request);

        try {
            $simulations->verifyOfficer($precinct, $validated['officer_code'], $validated['officer_pin']);
            $publication->publish($precinct);
            $precinct->forceFill(['status' => 'published'])->save();
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['officer_pin' => $exception->getMessage()]);
        }

        return to_route('election.demo-room.officer', [$round, $precinct])
            ->with('public_simulation.officer_feedback', 'Watcher results are published. Print or download the tally, Election Return, and handoff packet.');
    }

    public function handoff(SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations): Response
    {
        $this->scope($round, $precinct, $simulations);

        return Inertia::render('Election/DemoRoomHandoff', [
            'round' => $this->round($round),
            'precinct' => $this->precinctSummary($precinct),
            'downloads' => [
                'tally' => route('election.public-simulation.watcher.tally', [$round, $precinct]),
                'return' => route('election.public-simulation.watcher.return', [$round, $precinct]),
                'vvdatAuditExport' => route('election.public-simulation.watcher.vvdat-audit-export', [$round, $precinct]),
                'watcher' => route('election.public-simulation.watcher.show', [$round, $precinct]),
            ],
        ]);
    }

    private function scope(SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations): void
    {
        abort_unless($precinct->simulation_round_id === $round->id, 404);
        $simulations->applyScope($precinct);
    }

    private function controlNumberSessionKey(SimulationPrecinct $precinct): string
    {
        return "public_simulation.{$precinct->id}.control_number";
    }

    /**
     * @param  array<string, mixed>|null  $authorization
     * @return array<string, mixed>|null
     */
    private function controlNumber(?array $authorization, SimulationRound $round, SimulationPrecinct $precinct, StandardQrCode $qrCode): ?array
    {
        if ($authorization === null) {
            return null;
        }

        $controlNumber = [
            'code' => $authorization['code'],
            'expires_at' => $authorization['expires_at'],
            'voter_entry' => null,
        ];

        if (! config('election.public_simulation.demo_control_number_share.enabled')) {
            return $controlNumber;
        }

        $voterUrl = route('election.public-simulation.voter.show', [
            $round,
            $precinct,
            'code' => $authorization['code'],
        ]);

        return [
            ...$controlNumber,
            'voter_entry' => [
                'url' => $voterUrl,
                'qr' => 'data:image/png;base64,'.base64_encode($qrCode->renderPng($voterUrl)),
            ],
        ];
    }

    /** @return array{officer_code: string, officer_pin: string} */
    private function officerCredentials(Request $request): array
    {
        return $request->validate([
            'officer_code' => ['required', 'string', 'max:32'],
            'officer_pin' => ['required', 'digits:6'],
        ]);
    }

    /** @return array<string, string> */
    private function officerDefaults(SimulationPrecinct $precinct): array
    {
        return [
            'officer_code' => $precinct->officer_code,
            'officer_pin' => '123456',
        ];
    }

    /** @return array<string, mixed> */
    private function round(SimulationRound $round): array
    {
        return [
            'code' => $round->code,
            'name' => $round->name,
            'status' => $round->status,
            'precincts' => $round->precincts->map(fn (SimulationPrecinct $precinct): array => $this->precinctSummary($precinct))->all(),
        ];
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
            'officer_name' => $precinct->officer_name,
        ];
    }

    /** @return array<string, mixed> */
    private function precinct(SimulationPrecinct $precinct, ElectionStorage $storage, ElectionSnapshot $snapshot): array
    {
        $configuration = $storage->readJson('runtime/active-precinct.json');
        $return = $configuration === [] ? [] : $storage->readJson("returns/{$configuration['precinct_id']}-return.json");
        $publication = $storage->readJson('returns/publication-manifest.json');

        return [
            ...$this->precinctSummary($precinct),
            'snapshot' => $configuration === [] ? null : $snapshot->get(),
            'tally_available' => $publication !== [],
            'accepted_ballots' => $return['accepted_ballots'] ?? null,
        ];
    }

    /** @return array{label: string, description: string, url: string, qr: string} */
    private function role(string $label, string $description, string $url, StandardQrCode $qrCode): array
    {
        return [
            'label' => $label,
            'description' => $description,
            'url' => $url,
            'qr' => 'data:image/png;base64,'.base64_encode($qrCode->renderPng($url)),
        ];
    }
}
