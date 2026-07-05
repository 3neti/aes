<?php

namespace App\Election\Scanning;

use App\Election\Core\ActivityJournal;

final class ManualPayloadScanner implements BallotScanner
{
    public function __construct(
        private readonly ActivityJournal $journal,
    ) {}

    public function scan(string $rawInput): array
    {
        $payload = trim($rawInput);
        $record = [
            'payload' => $payload,
            'adapter' => 'manual-payload',
            'raw_payload_hash' => hash('sha256', $rawInput),
        ];

        $this->journal->record('ballot.scan_captured', [
            'adapter' => $record['adapter'],
            'raw_payload_hash' => $record['raw_payload_hash'],
        ]);

        return $record;
    }
}
