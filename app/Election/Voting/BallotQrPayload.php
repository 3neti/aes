<?php

namespace App\Election\Voting;

use App\Election\Core\CanonicalJson;
use RuntimeException;

final class BallotQrPayload
{
    private const CompactPrefix = 'aes-ballot-compact-1:';

    private const LegacyPrefix = 'aes-ballot-zlib-1:';

    public function __construct(
        private readonly CanonicalJson $json,
        private readonly CandidateCodeMap $candidateCodes,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encode(array $payload): string
    {
        $material = $this->compactMaterial($payload);

        return self::CompactPrefix.implode('|', [
            'AES2',
            $this->escape((string) $material['election_id']),
            $this->escape((string) $material['precinct_id']),
            $this->escape((string) $material['ballot_style_id']),
            $this->escape((string) $material['mapping_hash']),
            $this->escape((string) $material['tabulation_profile']),
            $this->escape((string) ($material['paper_ballot_serial'] ?? '')),
            implode(',', $material['candidate_codes']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function compactHash(array $payload): string
    {
        return $this->json->hash($this->compactMaterial($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encodeLegacy(array $payload): string
    {
        $compressed = gzdeflate($this->json->encode($payload), 9);

        if ($compressed === false) {
            throw new RuntimeException('Unable to compact ballot QR payload.');
        }

        return self::LegacyPrefix.base64_encode($compressed);
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $payload): array
    {
        if (str_starts_with($payload, self::CompactPrefix)) {
            return $this->decodeCompact(substr($payload, strlen(self::CompactPrefix)));
        }

        if (str_starts_with($payload, self::LegacyPrefix)) {
            $compressed = base64_decode(substr($payload, strlen(self::LegacyPrefix)), true);

            if ($compressed === false) {
                throw new RuntimeException('Ballot QR payload is not valid base64 data.');
            }

            $json = gzinflate($compressed);

            if ($json === false) {
                throw new RuntimeException('Ballot QR payload cannot be decompressed.');
            }
        } else {
            $decoded = base64_decode($payload, true);
            $json = $decoded === false ? $payload : $decoded;
        }

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{schema_version: string, election_id: mixed, precinct_id: mixed, ballot_style_id: mixed, mapping_hash: mixed, tabulation_profile: mixed, paper_ballot_serial: mixed, candidate_codes: array<int, string>}
     */
    private function compactMaterial(array $payload): array
    {
        return [
            'schema_version' => 'ballot-payload-compact-1',
            'election_id' => $payload['election_id'] ?? null,
            'precinct_id' => $payload['precinct_id'] ?? null,
            'ballot_style_id' => $payload['ballot_style_id'] ?? null,
            'mapping_hash' => $payload['mapping_hash'] ?? null,
            'tabulation_profile' => $payload['tabulation_profile'] ?? null,
            'paper_ballot_serial' => $payload['paper_ballot_serial'] ?? null,
            'candidate_codes' => $this->candidateCodes->codesForPayload($payload),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeCompact(string $payload): array
    {
        $parts = explode('|', $payload, 8);

        if (count($parts) !== 8 || $parts[0] !== 'AES2') {
            throw new RuntimeException('Compact ballot QR payload is malformed.');
        }

        $candidateCodes = $parts[7] === ''
            ? []
            : array_values(array_filter(explode(',', $parts[7]), fn (string $code): bool => $code !== ''));
        $material = [
            'schema_version' => 'ballot-payload-compact-1',
            'election_id' => $this->unescape($parts[1]),
            'precinct_id' => $this->unescape($parts[2]),
            'ballot_style_id' => $this->unescape($parts[3]),
            'mapping_hash' => $this->unescape($parts[4]),
            'tabulation_profile' => $this->unescape($parts[5]),
            'paper_ballot_serial' => $this->unescape($parts[6]) === '' ? null : $this->unescape($parts[6]),
            'candidate_codes' => $candidateCodes,
        ];

        return [
            ...$material,
            'ballot_id' => 'compact-'.substr($this->json->hash($material), 0, 16),
            'payload_hash_profile' => 'compact-selection-1',
            'payload_hash' => $this->json->hash($material),
            'paper_ballot_serial' => $material['paper_ballot_serial'],
            'selections' => $this->candidateCodes->selectionsForCodes($candidateCodes),
        ];
    }

    private function escape(string $value): string
    {
        return strtr(rawurlencode($value), ['%2C' => ',', '%2D' => '-', '%2E' => '.', '%5F' => '_']);
    }

    private function unescape(string $value): string
    {
        return rawurldecode($value);
    }
}
