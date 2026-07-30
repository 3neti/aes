<?php

namespace App\Election\Printing;

final class NullBallotPrinter implements BallotPrinter
{
    public function print(array $payload, ?PrintFormProfile $profile = null): array
    {
        return [
            'schema_version' => 'print-job-1',
            'ballot_id' => $payload['ballot_id'],
            'payload_hash' => $payload['payload_hash'],
            'printer' => 'null',
            'status' => 'printed',
            'artifact_path' => null,
            'print_form_profile' => ($profile ?? PrintFormProfile::A4)->value,
        ];
    }
}
