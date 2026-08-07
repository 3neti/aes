<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Printing\BallotPrinter;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\PrivateBallotRelease;
use App\Election\Voting\SealedBallotBox;
use App\Models\SimulationPrecinct;

final class DemoRoomForceCloseout
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly ActivityJournal $journal,
        private readonly PrivateBallotRelease $releases,
        private readonly BallotPrinter $printer,
        private readonly SealedBallotBox $ballotBox,
        private readonly PublicSimulationCloseout $closeout,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function close(SimulationPrecinct $precinct, string $officerCode, string $officerPin): array
    {
        $resolved = $this->resolveVoterWork($precinct, $officerCode);
        $closeout = $this->closeout->close($precinct, $officerCode, $officerPin);

        $this->journal->record('demo_room.force_closeout_completed', [
            'precinct_code' => $precinct->code,
            'officer_code_hash' => hash('sha256', strtoupper(trim($officerCode))),
            'cancelled_unfinished_booths' => $resolved['cancelled_unfinished_booths'],
            'auto_printed_ballots' => $resolved['auto_printed_ballots'],
            'auto_deposited_ballots' => $resolved['auto_deposited_ballots'],
            'accepted_ballots' => $closeout['tally']['accepted_ballots'] ?? null,
            'tally_hash' => $closeout['tally']['tally_hash'] ?? null,
        ]);

        return [
            'resolved' => $resolved,
            'closeout' => $closeout,
        ];
    }

    /**
     * @return array{
     *     cancelled_unfinished_booths: int,
     *     auto_printed_ballots: int,
     *     auto_deposited_ballots: int,
     *     cancelled_authorization_ids: array<int, string>,
     *     deposited_paper_ballot_serials: array<int, string>
     * }
     */
    private function resolveVoterWork(SimulationPrecinct $precinct, string $officerCode): array
    {
        $cancelled = $this->cancelUnfinishedBooths($precinct, $officerCode);
        $printed = 0;
        $deposited = [];

        foreach ($this->releaseRecords() as $record) {
            $releaseId = (string) ($record['release_id'] ?? '');
            $status = (string) ($record['status'] ?? '');

            if ($releaseId === '' || ! in_array($status, ['pending', 'redeemed', 'printed'], true)) {
                continue;
            }

            if (in_array($status, ['pending', 'redeemed'], true)) {
                $this->releases->print($releaseId, $this->printer);
                $printed++;
            }

            $deposit = $this->ballotBox->deposit($releaseId);
            $deposited[] = (string) ($deposit['paper_ballot_serial'] ?? $releaseId);
        }

        $this->journal->record('demo_room.force_closeout_requested', [
            'precinct_code' => $precinct->code,
            'officer_code_hash' => hash('sha256', strtoupper(trim($officerCode))),
            'cancelled_authorization_ids' => $cancelled,
            'auto_printed_ballots' => $printed,
            'deposited_paper_ballot_serials' => $deposited,
            'mode' => 'presentation-demo-finalizer',
        ]);

        return [
            'cancelled_unfinished_booths' => count($cancelled),
            'auto_printed_ballots' => $printed,
            'auto_deposited_ballots' => count($deposited),
            'cancelled_authorization_ids' => $cancelled,
            'deposited_paper_ballot_serials' => $deposited,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function cancelUnfinishedBooths(SimulationPrecinct $precinct, string $officerCode): array
    {
        $cancelled = [];

        foreach ($this->storage->files('voter-authorizations') as $path) {
            $file = basename($path);
            $record = $this->storage->readJson("voter-authorizations/{$file}");

            if (($record['status'] ?? null) !== 'claimed') {
                continue;
            }

            $record['status'] = 'cancelled_by_officer_closeout';
            $record['cancelled_at'] = $this->clock->now()->toIso8601String();
            $record['cancelled_reason'] = 'Demo closeout finalized unfinished booth work.';
            $record['cancelled_by_officer_code_hash'] = hash('sha256', strtoupper(trim($officerCode)));
            $this->storage->writeJson("voter-authorizations/{$file}", $record);

            $authorizationId = (string) ($record['authorization_id'] ?? $file);
            $cancelled[] = $authorizationId;
            $this->journal->record('demo_room.unfinished_booth_cancelled', [
                'precinct_code' => $precinct->code,
                'authorization_id' => $authorizationId,
            ]);
        }

        return $cancelled;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function releaseRecords(): array
    {
        return collect($this->storage->files('print-releases'))
            ->map(fn (string $path): array => $this->storage->readJson('print-releases/'.basename($path)))
            ->filter(fn (array $record): bool => $record !== [])
            ->values()
            ->all();
    }
}
