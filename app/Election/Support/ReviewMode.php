<?php

namespace App\Election\Support;

use Illuminate\Http\Request;

final class ReviewMode
{
    /**
     * @return array{
     *     enabled: bool,
     *     label: string|null,
     *     defaults: array<string, mixed>
     * }
     */
    public function propsFor(Request $request): array
    {
        if (! $this->enabled() || $this->isRestrictedSurface($request)) {
            return $this->disabledProps();
        }

        $setup = (array) config('election.simulation.precinct_setup', []);

        return [
            'enabled' => true,
            'label' => (string) config('election.review.label'),
            'defaults' => [
                'primary_officer' => [
                    'code' => $setup['chairperson_code'] ?? '',
                    'pin' => $setup['chairperson_pin'] ?? '',
                ],
                'chairperson' => [
                    'code' => $setup['chairperson_code'] ?? '',
                    'pin' => $setup['chairperson_pin'] ?? '',
                ],
                'poll_clerk' => [
                    'code' => $setup['poll_clerk_code'] ?? '',
                    'pin' => $setup['poll_clerk_pin'] ?? '',
                ],
                'third_member' => [
                    'code' => $setup['third_member_code'] ?? '',
                ],
                'setup' => $setup,
                'handoff' => [
                    'verification_note' => 'COMELEC review rehearsal officer verification.',
                ],
            ],
        ];
    }

    /**
     * @return array{enabled: bool, temporary_defaults_enabled: bool, label: string|null}
     */
    public function reportContext(): array
    {
        return [
            'enabled' => $this->enabled(),
            'temporary_defaults_enabled' => $this->enabled(),
            'label' => $this->enabled()
                ? (string) config('election.review.label')
                : null,
        ];
    }

    public function enabled(): bool
    {
        return (bool) config('election.review.enabled', false);
    }

    /**
     * @return array{enabled: false, label: null, defaults: array{}}
     */
    private function disabledProps(): array
    {
        return [
            'enabled' => false,
            'label' => null,
            'defaults' => [],
        ];
    }

    private function isRestrictedSurface(Request $request): bool
    {
        return $request->routeIs(
            'election.voter*',
            'election.print-station*',
            'election.watchers',
        );
    }
}
