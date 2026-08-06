<?php

namespace App\Http\Middleware;

use App\Election\ReviewRoom\ReviewRoomContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ProtectReviewEnvironment
{
    public const BasicAuthenticatedAttribute = 'election_review_basic_authenticated';

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

        if (! $this->credentialsMatchAny($request, $this->credentialPairs($username, $password))) {
            return $this->protectedResponse(
                response('Review access required.', Response::HTTP_UNAUTHORIZED)
                    ->header('WWW-Authenticate', 'Basic realm="AES COMELEC Review", charset="UTF-8"'),
            );
        }

        $request->attributes->set(self::BasicAuthenticatedAttribute, true);

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

    /**
     * @return list<array{username: string, password: string}>
     */
    private function credentialPairs(string $username, string $password): array
    {
        $pairs = [
            [
                'username' => $username,
                'password' => $password,
            ],
        ];

        if (config('election.review.access.demo_credentials.enabled', false)) {
            $demoUsername = (string) config('election.review.access.demo_credentials.username');
            $demoPassword = (string) config('election.review.access.demo_credentials.password');

            if ($demoUsername !== '' && $demoPassword !== '') {
                $pairs[] = [
                    'username' => $demoUsername,
                    'password' => $demoPassword,
                ];
            }
        }

        return $pairs;
    }

    /**
     * @param  list<array{username: string, password: string}>  $credentialPairs
     */
    private function credentialsMatchAny(Request $request, array $credentialPairs): bool
    {
        $providedUsername = $request->getUser();
        $providedPassword = $request->getPassword();

        if (! is_string($providedUsername) || ! is_string($providedPassword)) {
            return false;
        }

        foreach ($credentialPairs as $credentialPair) {
            if (
                hash_equals($credentialPair['username'], $providedUsername)
                && hash_equals($credentialPair['password'], $providedPassword)
            ) {
                return true;
            }
        }

        return false;
    }

    private function protectedResponse(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
