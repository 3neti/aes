<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ProtectReviewEnvironment
{
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

        if (! $this->credentialsMatch($request, $username, $password)) {
            return $this->protectedResponse(
                response('Review access required.', Response::HTTP_UNAUTHORIZED)
                    ->header('WWW-Authenticate', 'Basic realm="AES COMELEC Review", charset="UTF-8"'),
            );
        }

        return $this->protectedResponse($next($request));
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
