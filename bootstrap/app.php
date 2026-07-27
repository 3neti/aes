<?php

use App\Console\Commands\ElectionBrowserWalkthroughCommand;
use App\Console\Commands\ElectionScenarioCommand;
use App\Http\Middleware\BindBrowserWalkthroughRun;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ProtectReviewEnvironment;
use App\Http\Middleware\RequireReviewRoomRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        ElectionBrowserWalkthroughCommand::class,
        ElectionScenarioCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'review-room-role' => RequireReviewRoomRole::class,
        ]);

        $middleware->web(prepend: [
            ProtectReviewEnvironment::class,
        ]);

        $middleware->web(append: [
            BindBrowserWalkthroughRun::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
