<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\ReviewRoom\ReviewRoomContext;
use App\Election\ReviewRoom\ReviewRoomPresenter;
use App\Election\ReviewRoom\ReviewRoomService;
use App\Election\ReviewRoom\ReviewStationRole;
use App\Election\ReviewRoom\StartFreshReviewPresentation;
use App\Election\Support\ElectionStorage;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ProtectReviewEnvironment;
use App\Http\Requests\Election\CreateReviewRoomRequest;
use App\Models\ReviewRoom;
use App\Models\ReviewStation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class ReviewRoomController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const StationSessionKeys = [
        'election_review_room_id',
        'election_review_station_id',
        'election_review_station_role',
        'election_review_station_pairing_key',
    ];

    public function index(
        Request $request,
        ReviewRoomPresenter $presenter,
        ReviewRoomContext $context,
    ): Response {
        $this->ensureEnabled();
        $room = $this->latestRoom(withRelations: true);

        if ($room !== null) {
            $this->restoreBasicFacilitatorAccess($request, $room, $context);
        }

        $isFacilitator = $room !== null && $this->isFacilitator($request, $room, $context);

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
            'canStartFresh' => $this->canStartFresh($request, $room, $context),
        ]);
    }

    public function store(
        CreateReviewRoomRequest $request,
        ReviewRoomService $rooms,
        ReviewRoomContext $context,
        ElectionSnapshot $snapshot,
        ElectionStorage $storage,
    ): RedirectResponse {
        $station = $context->station($request);

        abort_if(
            $station !== null && $station->role !== ReviewStationRole::Officer,
            403,
        );

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

    public function startFresh(
        Request $request,
        StartFreshReviewPresentation $startFresh,
        ReviewRoomContext $context,
    ): RedirectResponse {
        $this->ensureEnabled();
        $room = $this->latestRoom();

        abort_unless($this->canStartFresh($request, $room, $context), 403);

        $result = $startFresh->handle();
        $this->establishFacilitatorSession($request, $result['room']);

        return to_route('election.review-room.index')
            ->with('success', 'A fresh presentation run and review room are ready.');
    }

    public function join(
        Request $request,
        ReviewRoom $room,
        ReviewStation $station,
        ReviewRoomService $rooms,
        ReviewRoomContext $context,
    ): RedirectResponse {
        $this->ensureEnabled();

        if ($station->review_room_id !== $room->id) {
            abort(404);
        }

        $currentStation = $context->station($request);

        abort_if(
            $currentStation !== null && $currentStation->id !== $station->id,
            409,
            'This browser is already assigned to another review station.',
        );

        $token = (string) $request->query('token');

        if ($request->session()->get('election_review_station_id') !== $station->id) {
            $request->session()->regenerate();
        }

        $pairingKey = $request->session()->get('election_review_station_pairing_key');

        if (! is_string($pairingKey) || $pairingKey === '') {
            $pairingKey = Str::random(64);
        }

        try {
            $station = $rooms->join($station, $token, $pairingKey);
        } catch (RuntimeException $exception) {
            abort(403, $exception->getMessage());
        }

        $request->session()->put([
            'election_review_room_id' => $room->id,
            'election_review_station_id' => $station->id,
            'election_review_station_role' => $station->role->value,
            'election_review_station_pairing_key' => $pairingKey,
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

    public function stationQr(
        Request $request,
        ReviewRoom $room,
        ReviewStation $station,
        ReviewRoomPresenter $presenter,
        ReviewRoomContext $context,
    ): HttpResponse {
        $this->ensureEnabled();
        $this->ensureStationBelongsToRoom($room, $station);
        abort_unless($this->isFacilitator($request, $room, $context), 403);

        return response($presenter->joinQr($room, $station), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline',
        ]);
    }

    public function releaseStation(
        Request $request,
        ReviewRoom $room,
        ReviewStation $station,
        ReviewRoomService $rooms,
        ReviewRoomContext $context,
    ): RedirectResponse {
        $this->ensureEnabled();
        $this->ensureStationBelongsToRoom($room, $station);
        abort_unless($this->isFacilitator($request, $room, $context), 403);

        $rooms->release($station, 'Facilitator released a failed or reassigned browser pairing.');

        return to_route('election.review-room.index')
            ->with('success', "{$station->label} is ready to pair again.");
    }

    public function close(
        Request $request,
        ReviewRoom $room,
        ReviewRoomService $rooms,
        ReviewRoomContext $context,
    ): RedirectResponse {
        $this->ensureEnabled();
        abort_unless($this->isFacilitator($request, $room, $context), 403);

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

    private function isFacilitator(
        Request $request,
        ReviewRoom $room,
        ReviewRoomContext $context,
    ): bool {
        if ($request->session()->get('election_review_facilitator_room_id') === $room->id) {
            return true;
        }

        $station = $context->station($request);

        return $station !== null
            && $station->review_room_id === $room->id
            && $station->role === ReviewStationRole::Officer;
    }

    private function canStartFresh(
        Request $request,
        ?ReviewRoom $room,
        ReviewRoomContext $context,
    ): bool {
        if ($this->hasBasicFacilitatorAccess($request, $context)) {
            return true;
        }

        return $room !== null && $this->isFacilitator($request, $room, $context);
    }

    private function restoreBasicFacilitatorAccess(
        Request $request,
        ReviewRoom $room,
        ReviewRoomContext $context,
    ): void {
        if (
            $room->status !== 'open'
            || ! $this->hasBasicFacilitatorAccess($request, $context)
            || $request->session()->get('election_review_facilitator_room_id') === $room->id
        ) {
            return;
        }

        $this->establishFacilitatorSession($request, $room);
    }

    private function hasBasicFacilitatorAccess(Request $request, ReviewRoomContext $context): bool
    {
        return $request->attributes->get(ProtectReviewEnvironment::BasicAuthenticatedAttribute) === true
            && $context->station($request) === null;
    }

    private function establishFacilitatorSession(Request $request, ReviewRoom $room): void
    {
        $request->session()->regenerate();
        $request->session()->forget(self::StationSessionKeys);
        $request->session()->put('election_review_facilitator_room_id', $room->id);
    }

    private function latestRoom(bool $withRelations = false): ?ReviewRoom
    {
        $query = ReviewRoom::query();

        if ($withRelations) {
            $query->with(['stations', 'events']);
        }

        return (clone $query)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first()
            ?? $query->latest('opened_at')->first();
    }

    private function ensureStationBelongsToRoom(ReviewRoom $room, ReviewStation $station): void
    {
        abort_unless($station->review_room_id === $room->id, 404);
    }
}
