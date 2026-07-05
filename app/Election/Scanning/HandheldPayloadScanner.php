<?php

namespace App\Election\Scanning;

use App\Election\Core\ActivityJournal;

final class HandheldPayloadScanner implements BallotScanner
{
    public function __construct(
        private readonly ActivityJournal $journal,
        private readonly string $deviceName = 'keyboard-wedge',
    ) {}

    public function scan(string $rawInput): array
    {
        $payload = $this->normalize($rawInput);
        $record = [
            'payload' => $payload,
            'adapter' => 'handheld-keyboard-wedge',
            'raw_payload_hash' => hash('sha256', $rawInput),
        ];

        $this->journal->record('ballot.scan_captured', [
            'adapter' => $record['adapter'],
            'device' => $this->deviceName,
            'raw_payload_hash' => $record['raw_payload_hash'],
        ]);

        return $record;
    }

    private function normalize(string $rawInput): string
    {
        $input = trim($rawInput);

        if (str_starts_with($input, 'AES-SCAN:')) {
            $input = substr($input, strlen('AES-SCAN:'));
        }

        return trim(str_replace(["\r", "\n", "\t"], '', $input));
    }
}
