<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\ReviewRoom\ReviewRoomPresenter;
use App\Election\ReviewRoom\ReviewRoomService;
use App\Election\Support\ElectionStorage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Election\CreateReviewRoomRequest;
use App\Models\ReviewRoom;
use App\Models\ReviewStation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class ReviewRoomController extends Controller
{
    public function index(Request $request, ReviewRoomPresenter $presenter): Response
    {
        $this->ensureEnabled();
        $room = ReviewRoom::query()
            ->with(['stations', 'events'])
            ->latest('opened_at')
            ->first();
        $isFacilitator = $room !== null
            && (
                $request->session()->get('election_review_facilitator_room_id') === $room->id
                || $request->session()->get('election_review_station_role') === 'officer'
            );

        return Inertia::render('Election/ReviewRoom', [
            'room' => $room === null
                ? null
                : ($isFacilitator ? $presenter->facilitator($room) : $presenter->presentation($room)),
            'isFacilitator' => $isFacilitator,
            'defaults' => [
                'name' => (string) config('election.review_room.default_name', 'COMELEC Multi-Tablet Review'),
                'voter_stations' => (int) config('election.review_room.default_voter_stations', 5),
                'max_voter_stations' => (int) config('election.review_room.max_voter_stations', 10),
            ],
        ]);
    }

    public function store(
        CreateReviewRoomRequest $request,
        ReviewRoomService $rooms,
        ElectionSnapshot $snapshot,
        ElectionStorage $storage,
    ): RedirectResponse {
        $configuration = $snapshot->get()['configuration'];
        $currentRun = $storage->currentRun();
        try {
            $room = $rooms->create(
                (string) $request->validated('name'),
                (int) $request->validated('voter_stations'),
                $configuration['precinct_id'] ?? null,
                $currentRun['run_id'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['room' => $exception->getMessage()]);
        }
        $request->session()->put('election_review_facilitator_room_id', $room->id);

        return to_route('election.review-room.index')
            ->with('success', "Review room {$room->code} is ready.");
    }

    public function join(
        Request $request,
        ReviewRoom $room,
        ReviewStation $station,
        ReviewRoomService $rooms,
    ): RedirectResponse {
        $this->ensureEnabled();

        if ($station->review_room_id !== $room->id) {
            abort(404);
        }

        $token = (string) $request->query('token');

        if ($request->session()->get('election_review_station_id') !== $station->id) {
            $request->session()->regenerate();
        }

        try {
            $station = $rooms->join($station, $token, $request->session()->getId());
        } catch (RuntimeException $exception) {
            abort(403, $exception->getMessage());
        }

        $request->session()->put([
            'election_review_room_id' => $room->id,
            'election_review_station_id' => $station->id,
            'election_review_station_role' => $station->role->value,
        ]);

        return to_route($station->role->destinationRoute());
    }

    public function presentation(
        Request $request,
        ReviewRoomPresenter $presenter,
        ElectionSnapshot $snapshot,
    ): Response {
        /** @var ReviewStation|null $station */
        $station = $request->attributes->get('election_review_station');

        abort_if($station === null, 403);

        return Inertia::render('Election/ReviewRoomPresentation', [
            'snapshot' => $snapshot->get(),
            'room' => $presenter->presentation($station->room),
        ]);
    }

    public function close(
        Request $request,
        ReviewRoom $room,
        ReviewRoomService $rooms,
    ): RedirectResponse {
        $this->ensureEnabled();
        $isFacilitator = $request->session()->get('election_review_facilitator_room_id') === $room->id;
        $isOfficer = $request->session()->get('election_review_station_role') === 'officer';
        abort_unless($isFacilitator || $isOfficer, 403);

        $rooms->close($room);

        return to_route('election.review-room.index')
            ->with('success', "Review room {$room->code} is closed.");
    }

    private function ensureEnabled(): void
    {
        abort_unless(
            config('election.review.enabled', false)
            && config('election.review_room.enabled', false),
            404,
        );
    }
}
