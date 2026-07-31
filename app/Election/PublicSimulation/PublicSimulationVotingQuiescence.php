<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Support\ElectionStorage;
use RuntimeException;

final class PublicSimulationVotingQuiescence
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ActivityJournal $journal,
    ) {}

    public function assertReadyForClose(): void
    {
        $claimedAuthorizations = collect($this->storage->files('voter-authorizations'))
            ->map(fn (string $path): array => $this->storage->readJson('voter-authorizations/'.basename($path)))
            ->where('status', 'claimed')
            ->count();
        $unresolvedReleases = collect($this->storage->files('print-releases'))
            ->map(fn (string $path): array => $this->storage->readJson('print-releases/'.basename($path)))
            ->whereIn('status', ['pending', 'redeemed', 'printed'])
            ->count();

        if ($claimedAuthorizations === 0 && $unresolvedReleases === 0) {
            return;
        }

        $this->journal->record('public_simulation.close_blocked_pending_voters', [
            'claimed_authorizations' => $claimedAuthorizations,
            'unresolved_print_releases' => $unresolvedReleases,
        ]);

        throw new RuntimeException("Cannot close polls while {$claimedAuthorizations} voter session(s) or {$unresolvedReleases} paper print release(s) remain unresolved.");
    }
}
