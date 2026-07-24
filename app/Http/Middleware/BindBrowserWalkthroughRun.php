<?php

namespace App\Http\Middleware;

use App\Election\Lifecycle\ElectionRunType;
use App\Election\Scenarios\BrowserWalkthroughControl;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class BindBrowserWalkthroughRun
{
    public function __construct(
        private readonly BrowserWalkthroughControl $control,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header(BrowserWalkthroughControl::Header);

        if (! is_string($token) || $token === '') {
            return $next($request);
        }

        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);

        try {
            $this->control->authorize($token);
        } catch (RuntimeException) {
            abort(403, 'Browser walkthrough authorization failed.');
        }

        $previousRunType = config('election.runtime.run_type');
        config()->set('election.runtime.run_type', ElectionRunType::Rehearsal->value);

        try {
            return $next($request);
        } finally {
            config()->set('election.runtime.run_type', $previousRunType);
        }
    }
}
