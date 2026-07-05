<?php

namespace App\Election\Scanning;

interface BallotScanner
{
    /**
     * @return array{payload: string, adapter: string, raw_payload_hash: string}
     */
    public function scan(string $rawInput): array;
}
