<?php

namespace App\Http\Middleware;

use App\Election\ReviewRoom\ReviewRoomContext;
use App\Election\ReviewRoom\ReviewRoomService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireReviewRoomRole
{
    public function __construct(
        private readonly ReviewRoomContext $context,
        private readonly ReviewRoomService $rooms,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$allowedRoles): Response
    {
        if (! config('election.review_room.enabled', false)) {
            return $next($request);
        }

        $station = $this->context->station($request);

        if ($station === null || $station->room->status !== 'open') {
            $request->session()->forget([
                'election_review_room_id',
                'election_review_station_id',
                'election_review_station_role',
                'election_review_station_pairing_key',
            ]);

            if ($request->isMethod('GET')) {
                return redirect()->route('election.review-room.index');
            }

            abort(403, 'Join an active review station before performing this action.');
        }

        abort_unless(in_array($station->role->value, $allowedRoles, true), 403);

        $this->rooms->heartbeat($station);
        $request->attributes->set('election_review_station', $station);

        return $next($request);
    }
}
