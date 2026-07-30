<?php

namespace App\Election\PublicSimulation;

use App\Models\SimulationPrecinct;
use Illuminate\Support\Str;

final class PublicSimulationScope
{
    public function apply(SimulationPrecinct $precinct): void
    {
        $baseDirectory = trim((string) config('election.storage.directory', 'election'), '/');
        $baseDirectory = str_contains($baseDirectory, '/public-simulations/')
            ? Str::before($baseDirectory, '/public-simulations/')
            : $baseDirectory;

        config()->set(
            'election.storage.directory',
            "{$baseDirectory}/public-simulations/{$precinct->round->code}/{$precinct->code}",
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
