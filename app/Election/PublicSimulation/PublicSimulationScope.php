<?php

namespace App\Election\PublicSimulation;

use App\Election\Scenarios\BrowserWalkthroughControl;
use App\Http\Middleware\LockElectionRunScope;
use App\Models\SimulationPrecinct;
use Illuminate\Support\Str;
use RuntimeException;

final class PublicSimulationScope
{
    public function __construct(private readonly BrowserWalkthroughControl $walkthrough) {}

    public function apply(SimulationPrecinct $precinct): void
    {
        $baseDirectory = trim((string) config('election.storage.directory', 'election'), '/');
        $baseDirectory = str_contains($baseDirectory, '/public-simulations/')
            ? Str::before($baseDirectory, '/public-simulations/')
            : $baseDirectory;

        $directory = "{$baseDirectory}/public-simulations/{$precinct->round->code}/{$precinct->code}";
        if (config('election.voting_lock_driver') === 'local') {
            $this->assertWalkthroughScope($precinct, $directory);
        }

        config()->set(
            'election.storage.directory',
            $directory,
        );
        config()->set('election.pop.clustered_precinct', $precinct->clustered_precinct);
        config()->set('election.pop.district', $precinct->district ?? config('election.pop.district'));
        $officers = [
            ...$this->simulationBoard(),
            [
                'code' => $precinct->officer_code,
                'name' => $precinct->officer_name,
                'role' => 'Simulation Election Officer',
                'pin_hash' => $precinct->officer_pin_hash,
            ],
        ];

        config()->set('election.officers', collect($officers)
            ->unique('code')
            ->values()
            ->all());
    }

    private function assertWalkthroughScope(SimulationPrecinct $precinct, string $directory): void
    {
        $control = $this->walkthrough->read();
        if ($control === [] || in_array($control['status'] ?? null, ['passed', 'failed'], true)) {
            return;
        }

        $request = request();
        $token = $request->header(BrowserWalkthroughControl::Header);
        $hash = $request->attributes->get(LockElectionRunScope::VerifiedWalkthrough);
        if (! is_string($token) || ! is_string($hash) || ! hash_equals($control['control_hash'], $hash)
            || ! in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            throw new RuntimeException('The active walkthrough does not authorize this public simulation scope.');
        }

        $verified = $this->walkthrough->authorize($token);
        $scope = $verified['simulation_scope'] ?? [];
        if (! hash_equals($hash, $verified['control_hash'])
            || ($scope['round_id'] ?? null) !== $precinct->round->id
            || ($scope['precinct_id'] ?? null) !== $precinct->id
            || ($scope['clustered_precinct'] ?? null) !== $precinct->clustered_precinct
            || ($scope['root'] ?? null) !== storage_path('app/'.$directory)) {
            throw new RuntimeException('The active walkthrough does not authorize this public simulation scope.');
        }
    }

    /**
     * @return array<int, array{code: string, name: string, role: string, pin_hash: string}>
     */
    private function simulationBoard(): array
    {
        $setup = config('election.simulation.precinct_setup');

        return [
            [
                'code' => (string) $setup['chairperson_code'],
                'name' => 'Simulation Officer',
                'role' => 'Election Board Chairperson',
                'pin_hash' => hash('sha256', (string) $setup['chairperson_pin']),
            ],
            [
                'code' => (string) $setup['poll_clerk_code'],
                'name' => 'Simulation Poll Clerk',
                'role' => 'Poll Clerk',
                'pin_hash' => hash('sha256', (string) $setup['poll_clerk_pin']),
            ],
            [
                'code' => (string) $setup['third_member_code'],
                'name' => 'Simulation EB Member',
                'role' => 'Third Member',
                'pin_hash' => hash('sha256', (string) $setup['chairperson_pin']),
            ],
        ];
    }
}
