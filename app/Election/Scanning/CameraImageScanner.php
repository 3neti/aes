<?php

namespace App\Election\Scanning;

use App\Election\Core\ActivityJournal;
use App\Election\Voting\StandardQrCode;
use RuntimeException;

final class CameraImageScanner implements BallotScanner
{
    public function __construct(
        private readonly ActivityJournal $journal,
        private readonly StandardQrCode $qrCode,
        private readonly string $deviceName = 'camera',
    ) {}

    public function scan(string $rawInput): array
    {
        $payload = $this->qrCode->decodePngBytes($this->imageBytes($rawInput));
        $record = [
            'payload' => $payload,
            'adapter' => 'camera-image',
            'raw_payload_hash' => hash('sha256', $rawInput),
        ];

        $this->journal->record('ballot.scan_captured', [
            'adapter' => $record['adapter'],
            'device' => $this->deviceName,
            'raw_payload_hash' => $record['raw_payload_hash'],
        ]);

        return $record;
    }

    private function imageBytes(string $rawInput): string
    {
        $input = trim($rawInput);

        if (str_starts_with($input, 'data:image/png;base64,')) {
            $decoded = base64_decode(substr($input, strlen('data:image/png;base64,')), true);

            if ($decoded === false) {
                throw new RuntimeException('Camera scanner image payload is not valid base64.');
            }

            return $decoded;
        }

        if (str_starts_with($rawInput, "\x89PNG")) {
            return $rawInput;
        }

        if (str_starts_with($input, "\x89PNG")) {
            return $input;
        }

        throw new RuntimeException('Camera scanner input must be a PNG image or PNG data URI.');
    }
}
