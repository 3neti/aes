<?php

namespace App\Http\Middleware;

use App\Election\ReviewRoom\ReviewRoomContext;
use App\Election\Support\ReviewMode;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly ReviewMode $reviewMode,
        private readonly ReviewRoomContext $reviewRoom,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'electionReview' => fn (): array => $this->reviewMode->propsFor($request),
            'electionReviewRoom' => fn (): array => $this->reviewRoom->forRequest($request),
        ];
    }
}
