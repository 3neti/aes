<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Support\ElectionStorage;

final class PublicSimulationOperationsBoard
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $authorizations = $this->records('voter-authorizations');
        $releases = $this->records('print-releases');
        $authorizationStatuses = $this->statusCounts($authorizations);
        $releaseStatuses = $this->statusCounts($releases);

        $activeBooths = (int) ($authorizationStatuses['claimed'] ?? 0);
        $pendingPins = (int) ($releaseStatuses['pending'] ?? 0);
        $redeemedPins = (int) ($releaseStatuses['redeemed'] ?? 0);
        $printedAwaitingDeposit = (int) ($releaseStatuses['printed'] ?? 0);

        return [
            'schema_version' => 'public-simulation-operations-board-1',
            'booths' => [
                'active' => $activeBooths,
                'completed' => (int) ($authorizationStatuses['completed'] ?? 0),
                'issued_unclaimed' => (int) ($authorizationStatuses['issued'] ?? 0),
                'expired' => (int) ($authorizationStatuses['expired'] ?? 0),
            ],
            'print_station' => [
                'pending_pins' => $pendingPins,
                'redeemed_pins' => $redeemedPins,
                'printed_awaiting_deposit' => $printedAwaitingDeposit,
                'deposited' => (int) ($releaseStatuses['deposited'] ?? 0),
                'expired' => (int) ($releaseStatuses['expired'] ?? 0),
            ],
            'closeout' => [
                'unresolved_voter_work' => $activeBooths + $pendingPins + $redeemedPins + $printedAwaitingDeposit,
                'can_close' => ($activeBooths + $pendingPins + $redeemedPins + $printedAwaitingDeposit) === 0,
                'next_required_action' => $this->nextRequiredAction($activeBooths, $pendingPins, $redeemedPins, $printedAwaitingDeposit),
            ],
            'timeline' => $this->timeline(),
            'privacy_notice' => 'Shows aggregate booth, print PIN, paper ballot, and journal states only. It excludes voter identity, control numbers, raw PINs, ballot selections, paper serials, and QR payloads.',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function records(string $directory): array
    {
        return collect($this->storage->files($directory))
            ->map(fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, int>
     */
    private function statusCounts(array $records): array
    {
        return collect($records)
            ->countBy(fn (array $record): string => (string) ($record['status'] ?? 'missing'))
            ->all();
    }

    /**
     * @return array<int, array{event_type: string, occurred_at: string, label: string}>
     */
    private function timeline(): array
    {
        $labels = [
            'voter.authorization_issued' => 'Officer issued a voter control number',
            'voter.authorization_claimed' => 'Voter entered a control number at a booth tablet',
            'ballot.finalized_privately' => 'Voter confirmed selections and generated a print PIN',
            'voting.print_pin.generated' => 'Print PIN became available at the booth',
            'voting.print_pin.consumed' => 'Central print station claimed a print PIN',
            'printing.ballot.generated_from_pin' => 'Central print station generated the paper ballot',
            'paper_ballot.deposited' => 'Verified paper ballot entered the sealed ballot box',
            'public_simulation.close_blocked_pending_voters' => 'Closeout was blocked by unfinished voter work',
        ];

        return collect($this->journal->entries())
            ->filter(fn (array $event): bool => array_key_exists((string) ($event['event_type'] ?? ''), $labels))
            ->take(-8)
            ->map(fn (array $event): array => [
                'event_type' => (string) $event['event_type'],
                'occurred_at' => (string) $event['occurred_at'],
                'label' => $labels[(string) $event['event_type']],
            ])
            ->values()
            ->all();
    }

    private function nextRequiredAction(
        int $activeBooths,
        int $pendingPins,
        int $redeemedPins,
        int $printedAwaitingDeposit,
    ): string {
        if ($activeBooths > 0) {
            return 'Wait for every voter booth to finalize or reset.';
        }

        if ($pendingPins > 0) {
            return 'Send voters with print PINs to the central print station.';
        }

        if ($redeemedPins > 0) {
            return 'Print the claimed paper ballots at the central print station.';
        }

        if ($printedAwaitingDeposit > 0) {
            return 'Verify and deposit every printed paper ballot.';
        }

        return 'No unresolved voter work. The officer may close polls when ready.';
    }
}
