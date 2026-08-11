<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ActivityJournal;
use App\Election\Printing\BallotPrinter;
use App\Election\Printing\CloseoutArtifactPrinter;
use App\Election\Printing\PrintFormProfile;
use App\Election\Printing\PrintFormProfileResolver;
use App\Election\PublicSimulation\PublicSimulationService;
use App\Election\PublicSimulation\PublicSimulationVotingGate;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\PrivateBallotRelease;
use App\Election\Voting\SealedBallotBox;
use App\Http\Controllers\Controller;
use App\Http\Requests\RedeemPrintReleaseRequest;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class DemoRoomPrintStationController extends Controller
{
    public function show(
        Request $request,
        SimulationRound $round,
        SimulationPrecinct $precinct,
        PublicSimulationService $simulations,
        PrivateBallotRelease $releases,
        ElectionStorage $storage,
        PrintFormProfileResolver $printProfiles,
        CloseoutArtifactPrinter $closeoutPrinter,
    ): Response {
        $this->scope($round, $precinct, $simulations);
        $releaseId = $request->session()->get($this->printSessionKey($precinct));
        $publication = $storage->readJson('returns/publication-manifest.json');
        $configuration = $storage->readJson('runtime/active-precinct.json');
        $precinctId = (string) ($configuration['precinct_id'] ?? '');
        $release = is_string($releaseId) ? $releases->find($releaseId) : [];
        $profiles = $this->printProfiles($round, $precinct, $storage, $printProfiles, $precinctId);

        return Inertia::render('Election/DemoRoomPrintStation', [
            'round' => $this->round($round),
            'precinct' => $this->precinct($precinct),
            'enabled' => (bool) $request->session()->get($this->enabledSessionKey($precinct), false),
            'isVoting' => $precinct->status === 'open',
            'isCloseoutReady' => $this->isCloseoutReady($precinct),
            'isPublished' => $publication !== [],
            'artifacts' => [
                'tally_sheet_pdf' => is_file($storage->path('runtime/tally-sheet.pdf')),
                'election_return_pdf' => $precinctId !== '' && is_file($storage->path("returns/{$precinctId}-return.pdf")),
            ],
            'printProfiles' => $profiles,
            'closeoutPrinter' => $closeoutPrinter->status(),
            'release' => $release,
            'ballotPreview' => is_string($releaseId) ? $releases->printedBallotPreview($releaseId) : null,
            'ballotPreviewUrl' => is_string($releaseId) && $releases->printedBallotPdfPath($releaseId) !== null
                ? route('election.demo-room.print.ballot-preview', [$round, $precinct])
                : null,
            'depositFeedback' => $request->session()->get($this->depositSessionKey($precinct)),
            'closeoutFeedback' => $request->session()->get($this->closeoutSessionKey($precinct)),
            'officerDefaults' => [
                'officer_code' => $precinct->officer_code,
                'officer_pin' => '123456',
            ],
            'actions' => [
                'enable' => route('election.demo-room.print.enable', [$round, $precinct]),
                'redeem' => route('election.demo-room.print.redeem', [$round, $precinct]),
                'print' => route('election.demo-room.print.print', [$round, $precinct]),
                'deposit' => route('election.demo-room.print.deposit', [$round, $precinct]),
                'officer' => route('election.demo-room.officer', [$round, $precinct]),
                'handoff' => route('election.demo-room.handoff', [$round, $precinct]),
                'watch' => route('election.public-simulation.watcher.show', [$round, $precinct]),
                'tally' => route('election.demo-room.print.tally-sheet', [$round, $precinct]),
                'return' => route('election.demo-room.print.election-return', [$round, $precinct]),
            ],
            'printPinDigits' => min(6, max(4, (int) config('election.voter.print_pin_digits', 4))),
        ]);
    }

    public function enable(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, ActivityJournal $journal): RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);
        $validated = $request->validate([
            'officer_code' => ['required', 'string', 'max:32'],
            'officer_pin' => ['required', 'digits:6'],
        ]);

        try {
            $simulations->verifyOfficer($precinct, $validated['officer_code'], $validated['officer_pin']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['officer_pin' => $exception->getMessage()]);
        }

        $request->session()->put($this->enabledSessionKey($precinct), true);
        $journal->record('demo_room.print_station_enabled', [
            'round_code' => $round->code,
            'precinct_code' => $precinct->code,
            'officer_code_hash' => hash('sha256', strtoupper(trim($validated['officer_code']))),
            'mode' => 'central-print-station',
        ]);

        return to_route('election.demo-room.print.station', [$round, $precinct])
            ->with('public_simulation.officer_feedback', 'Central print station enabled. It can now redeem voter print PINs.');
    }

    public function redeem(RedeemPrintReleaseRequest $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PrivateBallotRelease $releases, PublicSimulationVotingGate $voting): RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);
        $this->ensureEnabled($request, $precinct);

        try {
            $release = $voting->execute(fn (): array => $releases->redeem($request->validated('code')));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        $request->session()->put($this->printSessionKey($precinct), $release['release_id']);

        return to_route('election.demo-room.print.station', [$round, $precinct]);
    }

    public function tallySheet(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, ElectionStorage $storage, PrintFormProfileResolver $printProfiles, ?string $profile = null): BinaryFileResponse|RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);
        if (! $this->isEnabled($request, $precinct)) {
            return $this->stationRedirect($round, $precinct, 'Enable the central print station before opening closeout forms.');
        }

        if (! $this->isCloseoutReady($precinct)) {
            return $this->stationRedirect($round, $precinct, 'Close the precinct first. The tally sheet is generated after closeout.');
        }

        $resolved = $printProfiles->from($profile ?? PrintFormProfile::A4->value);
        $path = $storage->path("print-forms/tally-sheet/{$resolved->value}.pdf");
        if (! is_file($path)) {
            return $this->stationRedirect($round, $precinct, 'The tally sheet PDF is not available yet. Close the precinct again or refresh the Printing Station.');
        }

        return response()->file($path, [
            'Content-Disposition' => 'inline; filename="'.$precinct->code.'-'.$resolved->value.'-tally-sheet.pdf"',
        ]);
    }

    public function electionReturn(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, ElectionStorage $storage, PrintFormProfileResolver $printProfiles, ?string $profile = null): BinaryFileResponse|RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);
        if (! $this->isEnabled($request, $precinct)) {
            return $this->stationRedirect($round, $precinct, 'Enable the central print station before opening closeout forms.');
        }

        if (! $this->isCloseoutReady($precinct)) {
            return $this->stationRedirect($round, $precinct, 'Close the precinct first. The Election Return is generated after closeout.');
        }

        $configuration = $storage->readJson('runtime/active-precinct.json');
        $precinctId = (string) ($configuration['precinct_id'] ?? '');
        $resolved = $printProfiles->from($profile ?? PrintFormProfile::A4->value);
        $path = $storage->path("print-forms/election-return/{$precinctId}/{$resolved->value}.pdf");
        if ($precinctId === '' || ! is_file($path)) {
            return $this->stationRedirect($round, $precinct, 'The Election Return PDF is not available yet. Close the precinct again or refresh the Printing Station.');
        }

        return response()->file($path, [
            'Content-Disposition' => 'inline; filename="'.$precinct->code.'-'.$resolved->value.'-election-return.pdf"',
        ]);
    }

    public function submitTallySheet(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, ElectionStorage $storage, PrintFormProfileResolver $printProfiles, CloseoutArtifactPrinter $printer, ?string $profile = null): RedirectResponse
    {
        return $this->submitCloseoutArtifact($request, $round, $precinct, $simulations, $storage, $printProfiles, $printer, 'tally-sheet', $profile);
    }

    public function submitElectionReturn(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, ElectionStorage $storage, PrintFormProfileResolver $printProfiles, CloseoutArtifactPrinter $printer, ?string $profile = null): RedirectResponse
    {
        return $this->submitCloseoutArtifact($request, $round, $precinct, $simulations, $storage, $printProfiles, $printer, 'election-return', $profile);
    }

    public function print(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PrivateBallotRelease $releases, BallotPrinter $printer, PublicSimulationVotingGate $voting): RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);
        $this->ensureEnabled($request, $precinct);

        try {
            $voting->execute(fn (): array => $releases->print($this->printReleaseId($request, $precinct), $printer));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['printer' => $exception->getMessage()]);
        }

        return to_route('election.demo-room.print.station', [$round, $precinct]);
    }

    public function ballotPreview(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PrivateBallotRelease $releases): BinaryFileResponse|RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);
        $this->ensureEnabled($request, $precinct);

        $path = $releases->printedBallotPdfPath($this->printReleaseId($request, $precinct));
        if ($path === null) {
            return $this->stationRedirect($round, $precinct, 'The printed ballot preview is not available yet. Print the paper ballot first.');
        }

        return response()->file($path, [
            'Content-Disposition' => 'inline; filename="'.$precinct->code.'-printed-ballot-preview.pdf"',
        ]);
    }

    public function deposit(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, SealedBallotBox $ballotBox, PublicSimulationVotingGate $voting): RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);
        $this->ensureEnabled($request, $precinct);

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

        return to_route('election.demo-room.print.station', [$round, $precinct]);
    }

    private function scope(SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations): void
    {
        abort_unless($precinct->simulation_round_id === $round->id, 404);
        $simulations->applyScope($precinct);
    }

    private function enabledSessionKey(SimulationPrecinct $precinct): string
    {
        return "demo_room.{$precinct->id}.print_station_enabled";
    }

    private function printSessionKey(SimulationPrecinct $precinct): string
    {
        return "demo_room.{$precinct->id}.print_release";
    }

    private function depositSessionKey(SimulationPrecinct $precinct): string
    {
        return "demo_room.{$precinct->id}.deposit_feedback";
    }

    private function closeoutSessionKey(SimulationPrecinct $precinct): string
    {
        return "demo_room.{$precinct->id}.closeout_feedback";
    }

    private function ensureEnabled(Request $request, SimulationPrecinct $precinct): void
    {
        abort_unless($this->isEnabled($request, $precinct), 403);
    }

    private function isEnabled(Request $request, SimulationPrecinct $precinct): bool
    {
        return (bool) $request->session()->get($this->enabledSessionKey($precinct), false);
    }

    private function printReleaseId(Request $request, SimulationPrecinct $precinct): string
    {
        $releaseId = $request->session()->get($this->printSessionKey($precinct));
        abort_unless(is_string($releaseId), 403);

        return $releaseId;
    }

    private function isCloseoutReady(SimulationPrecinct $precinct): bool
    {
        return in_array($precinct->status, ['results_ready', 'published'], true);
    }

    private function stationRedirect(SimulationRound $round, SimulationPrecinct $precinct, string $message): RedirectResponse
    {
        return to_route('election.demo-room.print.station', [$round, $precinct])
            ->with($this->closeoutSessionKey($precinct), $message);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function printProfiles(SimulationRound $round, SimulationPrecinct $precinct, ElectionStorage $storage, PrintFormProfileResolver $printProfiles, string $precinctId): array
    {
        return collect($printProfiles->available())
            ->map(fn (PrintFormProfile $profile): array => [
                'profile' => $profile->value,
                'label' => $profile->label(),
                'description' => $profile->description(),
                'width_mm' => $profile->widthMillimetres(),
                'thermal' => $profile->isThermal(),
                'tally_available' => is_file($storage->path("print-forms/tally-sheet/{$profile->value}.pdf")),
                'return_available' => $precinctId !== '' && is_file($storage->path("print-forms/election-return/{$precinctId}/{$profile->value}.pdf")),
                'tally_url' => route('election.demo-room.print.tally-sheet', [$round, $precinct, $profile->value]),
                'return_url' => route('election.demo-room.print.election-return', [$round, $precinct, $profile->value]),
                'tally_submit_url' => route('election.demo-room.print.tally-sheet.submit', [$round, $precinct, $profile->value]),
                'return_submit_url' => route('election.demo-room.print.election-return.submit', [$round, $precinct, $profile->value]),
            ])
            ->values()
            ->all();
    }

    private function submitCloseoutArtifact(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, ElectionStorage $storage, PrintFormProfileResolver $printProfiles, CloseoutArtifactPrinter $printer, string $artifact, ?string $profile = null): RedirectResponse
    {
        $this->scope($round, $precinct, $simulations);
        if (! $this->isEnabled($request, $precinct)) {
            return $this->stationRedirect($round, $precinct, 'Enable the central print station before submitting closeout forms.');
        }

        if (! $this->isCloseoutReady($precinct)) {
            return $this->stationRedirect($round, $precinct, 'Close the precinct first. Closeout forms are generated after closeout.');
        }

        $configuration = $storage->readJson('runtime/active-precinct.json');
        $precinctId = (string) ($configuration['precinct_id'] ?? '');
        $resolved = $printProfiles->from($profile ?? PrintFormProfile::A4->value);
        $result = $printer->print($artifact, $resolved, $precinct->code, $precinctId);

        return $this->stationRedirect($round, $precinct, (string) $result['message']);
    }

    /** @return array<string, mixed> */
    private function round(SimulationRound $round): array
    {
        return [
            'code' => $round->code,
            'name' => $round->name,
            'status' => $round->status,
        ];
    }

    /** @return array<string, mixed> */
    private function precinct(SimulationPrecinct $precinct): array
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
}
