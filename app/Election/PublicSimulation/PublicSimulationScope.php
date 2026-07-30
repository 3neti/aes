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
        config()->set('election.officers', [[
            'code' => $precinct->officer_code,
            'name' => $precinct->officer_name,
            'role' => 'Simulation Election Officer',
            'pin_hash' => $precinct->officer_pin_hash,
        ]]);
    }
}
