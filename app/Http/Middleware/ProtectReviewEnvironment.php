<?php

namespace App\Http\Middleware;

use App\Election\ReviewRoom\ReviewRoomContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ProtectReviewEnvironment
{
    public function __construct(private readonly ReviewRoomContext $reviewRoom) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('election.review.access.enabled', false)) {
            return $next($request);
        }

        $username = (string) config('election.review.access.username');
        $password = (string) config('election.review.access.password');

        if ($username === '' || $password === '') {
            return $this->protectedResponse(
                response('Review access is not configured.', Response::HTTP_SERVICE_UNAVAILABLE),
            );
        }

        if ($this->hasStationAccess($request)) {
            return $this->protectedResponse($next($request));
        }

        if (! $this->credentialsMatch($request, $username, $password)) {
            return $this->protectedResponse(
                response('Review access required.', Response::HTTP_UNAUTHORIZED)
                    ->header('WWW-Authenticate', 'Basic realm="AES COMELEC Review", charset="UTF-8"'),
            );
        }

        return $this->protectedResponse($next($request));
    }

    private function hasStationAccess(Request $request): bool
    {
        if (! config('election.review_room.enabled', false)) {
            return false;
        }

        if ($request->routeIs('election.review-room.join')) {
            return $request->isMethod('GET') && $request->hasValidSignature();
        }

        return $this->reviewRoom->station($request) !== null;
    }

    private function credentialsMatch(Request $request, string $username, string $password): bool
    {
        $providedUsername = $request->getUser();
        $providedPassword = $request->getPassword();

        return is_string($providedUsername)
            && is_string($providedPassword)
            && hash_equals($username, $providedUsername)
            && hash_equals($password, $providedPassword);
    }

    private function protectedResponse(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
