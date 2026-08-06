<?php

namespace App\Http\Controllers\Election;

use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Printing\BallotPrinter;
use App\Election\Voting\PrivateBallotRelease;
use App\Election\Voting\SealedBallotBox;
use App\Http\Controllers\Controller;
use App\Http\Requests\RedeemPrintReleaseRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class PrintStationController extends Controller
{
    public function show(
        Request $request,
        PrivateBallotRelease $releases,
        LifecycleState $lifecycle,
    ): Response {
        abort_unless($lifecycle->current() === Lifecycle::Voting, 409);

        $releaseId = $request->session()->get('election.print_station_release_id');
        $release = is_string($releaseId) ? $releases->find($releaseId) : [];

        return Inertia::render('Election/PrintStation', [
            'release' => $release,
            'ballotPreview' => is_string($releaseId) ? $releases->printedBallotPreview($releaseId) : null,
            'depositFeedback' => $request->session()->get('deposit_feedback'),
            'printPinDigits' => min(6, max(4, (int) config('election.voter.print_pin_digits', 4))),
        ]);
    }

    public function redeem(
        RedeemPrintReleaseRequest $request,
        PrivateBallotRelease $releases,
    ): RedirectResponse {
        try {
            $release = $releases->redeem($request->validated('code'));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        $request->session()->put('election.print_station_release_id', $release['release_id']);

        return redirect()->route('election.print-station');
    }

    public function print(
        Request $request,
        PrivateBallotRelease $releases,
        BallotPrinter $printer,
    ): RedirectResponse {
        $releaseId = $this->releaseId($request);

        try {
            $releases->print($releaseId, $printer);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['printer' => $exception->getMessage()]);
        }

        return redirect()->route('election.print-station');
    }

    public function deposit(
        Request $request,
        SealedBallotBox $ballotBox,
    ): RedirectResponse {
        $releaseId = $this->releaseId($request);

        try {
            $record = $ballotBox->deposit($releaseId);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['deposit' => $exception->getMessage()]);
        }

        $request->session()->forget('election.print_station_release_id');

        return redirect()
            ->route('election.print-station')
            ->with('deposit_feedback', [
                'status' => 'accepted',
                'paper_ballot_serial' => $record['paper_ballot_serial'],
            ]);
    }

    private function releaseId(Request $request): string
    {
        $releaseId = $request->session()->get('election.print_station_release_id');

        abort_unless(is_string($releaseId), 403);

        return $releaseId;
    }
}
