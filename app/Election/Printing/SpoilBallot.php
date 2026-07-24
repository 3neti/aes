<?php

namespace App\Election\Printing;

use App\Election\Core\ActivityJournal;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\PaperBallotLedger;

final class SpoilBallot
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ActivityJournal $journal,
        private readonly PaperBallotLedger $paperBallots,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $payloadHash, string $reason = 'simulation spoilage'): array
    {
        $record = [
            'schema_version' => 'spoiled-ballot-1',
            'payload_hash' => $payloadHash,
            'reason' => $reason,
        ];

        $this->storage->writeJson("runtime/spoiled-{$payloadHash}.json", $record);
        $this->paperBallots->recordSpoiled($payloadHash);
        $this->journal->record('ballot.spoiled', $record);

        return $record;
    }
}
