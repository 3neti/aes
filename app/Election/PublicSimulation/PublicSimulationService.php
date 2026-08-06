<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Preparation\ActivateConfiguredPrecinct;
use App\Election\Support\ElectionStorage;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class PublicSimulationService
{
    public function __construct(
        private readonly PublicSimulationScope $scope,
        private readonly ElectionStorage $storage,
        private readonly ActivateConfiguredPrecinct $activate,
        private readonly ActivityJournal $journal,
    ) {}

    public function currentRound(): SimulationRound
    {
        $round = SimulationRound::query()
            ->with('precincts')
            ->where('status', 'open')
            ->latest('id')
            ->first();

        return $round ?? $this->createRound();
    }

    public function createWalkthroughRound(): SimulationRound
    {
        return $this->createRound();
    }

    public function open(SimulationPrecinct $precinct, string $code, string $pin): SimulationPrecinct
    {
        $this->ensureOfficer($precinct, $code, $pin);

        if ($precinct->status === 'closed') {
            throw new RuntimeException('This precinct has already closed. Start a new public simulation round to vote again.');
        }

        $this->scope->apply($precinct);
        $this->activate->handle();
        $this->storage->writeJson('runtime/lifecycle.json', ['stage' => 'voting']);
        $this->journal->record('public_simulation.precinct_opened', [
            'round_code' => $precinct->round->code,
            'precinct_code' => $precinct->code,
            'officer_code_hash' => hash('sha256', $precinct->officer_code),
            'baseline' => 'configured precinct package activated for public simulation',
        ]);

        $precinct->forceFill([
            'status' => 'open',
            'opened_at' => $precinct->opened_at ?? now(),
        ])->save();

        return $precinct->refresh();
    }

    public function applyScope(SimulationPrecinct $precinct): void
    {
        $this->scope->apply($precinct);
    }

    public function verifyOfficer(SimulationPrecinct $precinct, string $code, string $pin): void
    {
        $this->ensureOfficer($precinct, $code, $pin);
    }

    public function archive(SimulationRound $round): SimulationRound
    {
        return DB::transaction(function () use ($round): SimulationRound {
            $round = SimulationRound::query()->with('precincts')->lockForUpdate()->findOrFail($round->id);

            if ($round->status === 'archived') {
                return $round;
            }

            if ($round->precincts->contains(fn (SimulationPrecinct $precinct): bool => $precinct->status !== 'published')) {
                throw new RuntimeException('Every precinct must publish or be explicitly resolved before this simulation round can be archived.');
            }

            $round->forceFill([
                'status' => 'archived',
                'archived_at' => now(),
            ])->save();

            return $round;
        }, attempts: 5);
    }

    /**
     * Archive a completed round before making a new public lobby available.
     *
     * @return array{archived: SimulationRound, fresh: SimulationRound}
     */
    public function reset(SimulationRound $round): array
    {
        $archived = $this->archive($round);

        $existingLiveRound = SimulationRound::query()
            ->where('status', 'open')
            ->latest('id')
            ->first();

        if ($existingLiveRound !== null) {
            throw new RuntimeException('Another public simulation round is already live. Archive or resolve it before resetting this round.');
        }

        return [
            'archived' => $archived,
            'fresh' => $this->createRound(),
        ];
    }

    /**
     * Replace the live demo set without requiring every precinct to be published.
     *
     * @return array{archived: SimulationRound|null, fresh: SimulationRound}
     */
    public function refreshDemoSet(): array
    {
        return DB::transaction(function (): array {
            $round = SimulationRound::query()
                ->where('status', 'open')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($round instanceof SimulationRound) {
                $round->forceFill([
                    'status' => 'archived',
                    'archived_at' => now(),
                ])->save();
            }

            return [
                'archived' => $round,
                'fresh' => $this->createRound(),
            ];
        }, attempts: 5);
    }

    private function createRound(): SimulationRound
    {
        return DB::transaction(function (): SimulationRound {
            $round = SimulationRound::query()->create([
                'code' => 'ROUND-'.Str::upper(Str::random(6)),
                'name' => (string) config('election.public_simulation.default_name'),
                'status' => 'open',
                'opened_at' => now(),
            ]);

            foreach (config('election.public_simulation.precincts', []) as $index => $definition) {
                $code = (string) $definition['code'];
                $round->precincts()->create([
                    'code' => $code,
                    'clustered_precinct' => (string) $definition['clustered_precinct'],
                    'district' => $definition['district'] ?? null,
                    'label' => (string) $definition['label'],
                    'city_municipality' => 'CITY OF MANILA',
                    'province' => 'NATIONAL CAPITAL REGION',
                    'status' => 'ready',
                    'officer_name' => 'Volunteer Election Officer '.($index + 1),
                    'officer_code' => 'SIM-'.($index + 1).'-'.Str::upper(Str::random(4)),
                    'officer_pin_hash' => hash('sha256', '123456'),
                ]);
            }

            return $round->load('precincts');
        }, attempts: 5);
    }

    private function ensureOfficer(SimulationPrecinct $precinct, string $code, string $pin): void
    {
        if (
            ! hash_equals($precinct->officer_code, strtoupper(trim($code)))
            || ! hash_equals($precinct->officer_pin_hash, hash('sha256', $pin))
        ) {
            throw new RuntimeException('The officer credentials do not match this precinct.');
        }
    }
}
