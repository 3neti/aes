<?php

namespace App\Http\Middleware;

use App\Election\Scenarios\BrowserWalkthroughControl;
use App\Election\Support\ElectionRunScopeLock;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LockElectionRunScope
{
    public const VerifiedWalkthrough = 'election.verified_walkthrough_control_hash';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function __construct(private readonly ElectionRunScopeLock $lock) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('election.voting_lock_driver') !== 'local' || ! $request->is('election', 'election/*')) {
            return $next($request);
        }

        $started = false;
        try {
            return $this->lock->execute(function () use ($request, $next, &$started): Response {
                $walkthrough = app(BrowserWalkthroughControl::class);
                try {
                    $control = $walkthrough->read();
                } catch (Throwable $exception) {
                    try {
                        report($exception);
                    } catch (Throwable) {
                    }

                    return response('The browser walkthrough reservation cannot be verified. Operator investigation is required.', 503);
                }
                $verifiedHash = null;
                if (($control['status'] ?? null) === 'active') {
                    $token = $request->header(BrowserWalkthroughControl::Header);
                    $recordedHash = $control['token_hash'] ?? null;
                    if (! is_string($token) || $token === '' || ! is_string($recordedHash)
                        || ! in_array($request->ip(), ['127.0.0.1', '::1'], true)
                        || ! hash_equals($recordedHash, hash('sha256', $token))) {
                        return response('An active browser walkthrough reserves the election run. Resolve it before starting another operation.', 503);
                    }
                    try {
                        $verified = $walkthrough->authorize($token);
                        if (array_key_exists('simulation_scope', $verified)) {
                            return response('Scoped walkthrough callbacks are unavailable until both runs can be finalized together.', 503);
                        }
                        $verifiedHash = $verified['control_hash'];
                    } catch (\RuntimeException) {
                        return response('Browser walkthrough authorization failed.', 403);
                    }
                } elseif ($control !== [] && ! in_array($control['status'] ?? null, ['passed', 'failed'], true)) {
                    return response('The browser walkthrough reservation is inconsistent. Operator investigation is required.', 503);
                }
                $started = true;
                $request->attributes->remove(self::VerifiedWalkthrough);
                if (is_string($verifiedHash)) {
                    $request->attributes->set(self::VerifiedWalkthrough, $verifiedHash);
                }

                try {
                    return $next($request);
                } finally {
                    $request->attributes->remove(self::VerifiedWalkthrough);
                }
            });
        } catch (LockTimeoutException $exception) {
            if ($started) {
                throw $exception;
            }

            $message = 'The election system is busy. Please wait a moment and try again.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 503, ['Retry-After' => '5'])
                : response($message, 503, ['Retry-After' => '5']);
        }
    }
}
