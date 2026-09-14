<?php

namespace App\Election\Truth;

use RuntimeException;

final class TruthQrEnvelope
{
    public const Scheme = 'truth';

    public const Version = 'v1';

    public const BallotType = 'waes-ballot';

    public const LegacyBallotType = 'vaes-ballot';

    public const ElectionReturnType = 'waes-election-return';

    public const BallotPayloadVersion = 'aes-ballot-compact-1';

    public const ElectionReturnPayloadVersion = 'waes-er-compact-1';

    public const ElectionReturnFragmentVersion = 'waes-er-fragment-1';

    /**
     * @return array<string, mixed>
     */
    public function inspect(string $payload): array
    {
        $payload = trim($payload);

        if ($payload === '') {
            throw new RuntimeException('No QR payload received.');
        }

        if (! str_starts_with($payload, self::Scheme.'://')) {
            throw new RuntimeException('Payload is not a truth:// URI.');
        }

        $parts = parse_url($payload);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== self::Scheme || ($parts['host'] ?? null) !== self::Version) {
            throw new RuntimeException('Truth QR envelope has an unsupported header.');
        }

        $segments = array_values(array_filter(
            explode('/', (string) ($parts['path'] ?? '')),
            fn (string $segment): bool => $segment !== '',
        ));

        return match ($segments[0] ?? null) {
            self::BallotType, self::LegacyBallotType => $this->inspectBallot($segments, (string) ($parts['query'] ?? '')),
            self::ElectionReturnType => $this->inspectElectionReturn($segments, (string) ($parts['query'] ?? '')),
            default => throw new RuntimeException('Truth QR envelope has an unsupported document type.'),
        };
    }

    public function canonicalPayload(string $payload): string
    {
        $metadata = $this->inspect($payload);

        if (($metadata['canonical_payload'] ?? null) === null) {
            throw new RuntimeException('Truth QR fragment set must be reassembled before decoding.');
        }

        return (string) $metadata['canonical_payload'];
    }

    /**
     * @param  array<int, string>  $payloads
     */
    public function reassemble(array $payloads): string
    {
        $parts = collect($payloads)->map(fn (string $payload): array => $this->inspect($payload));

        if ($parts->contains(fn (array $part): bool => ($part['part_kind'] ?? null) === 'complete')) {
            return (string) $parts->first(fn (array $part): bool => ($part['part_kind'] ?? null) === 'complete')['canonical_payload'];
        }

        $first = $parts->first();

        if (! is_array($first)) {
            throw new RuntimeException('No Truth QR payloads were provided.');
        }

        $documentType = (string) ($first['document_type'] ?? '');
        $payloadVersion = (string) ($first['payload_version'] ?? '');
        $groupId = (string) ($first['group_id'] ?? '');
        $totalParts = (int) ($first['total_parts'] ?? 0);
        $chunks = [];

        foreach ($parts as $part) {
            if (
                ($part['part_kind'] ?? null) !== 'fragment'
                || ($part['document_type'] ?? null) !== $documentType
                || ($part['payload_version'] ?? null) !== $payloadVersion
                || ($part['group_id'] ?? null) !== $groupId
                || (int) ($part['total_parts'] ?? 0) !== $totalParts
            ) {
                throw new RuntimeException('Truth QR fragments do not belong to the same payload set.');
            }

            $chunks[(int) $part['part_number']] = (string) ($part['encoded_fragment'] ?? '');
        }

        ksort($chunks);

        if (count($chunks) !== $totalParts || array_keys($chunks) !== range(1, $totalParts)) {
            throw new RuntimeException('Truth QR fragment set is incomplete.');
        }

        $canonicalPayload = $this->base64UrlDecode(implode('', $chunks));

        if (hash('sha256', $canonicalPayload) !== $groupId) {
            throw new RuntimeException('Truth QR fragment hash mismatch.');
        }

        return $canonicalPayload;
    }

    /**
     * @param  array<int, string>  $segments
     * @return array<string, mixed>
     */
    private function inspectBallot(array $segments, string $queryString): array
    {
        if (count($segments) !== 2 || ($segments[1] ?? null) !== self::BallotPayloadVersion) {
            throw new RuntimeException('Ballot payload envelope has an unsupported payload type.');
        }

        $canonicalPayload = $this->payloadFromQuery($queryString);

        if (! str_starts_with($canonicalPayload, self::BallotPayloadVersion.':')) {
            throw new RuntimeException('Ballot payload envelope does not contain a compact ballot payload.');
        }

        return [
            'scheme' => self::Scheme,
            'version' => self::Version,
            'document_type' => self::BallotType,
            'payload_version' => self::BallotPayloadVersion,
            'part_kind' => 'complete',
            'part_number' => 1,
            'total_parts' => 1,
            'group_id' => hash('sha256', $canonicalPayload),
            'canonical_payload' => $canonicalPayload,
        ];
    }

    /**
     * @param  array<int, string>  $segments
     * @return array<string, mixed>
     */
    private function inspectElectionReturn(array $segments, string $queryString): array
    {
        if (($segments[1] ?? null) === self::ElectionReturnPayloadVersion) {
            $canonicalPayload = $this->payloadFromQuery($queryString);

            if (! str_starts_with($canonicalPayload, self::ElectionReturnPayloadVersion.':')) {
                throw new RuntimeException('Election return envelope does not contain a compact payload.');
            }

            return [
                'scheme' => self::Scheme,
                'version' => self::Version,
                'document_type' => self::ElectionReturnType,
                'payload_version' => self::ElectionReturnPayloadVersion,
                'part_kind' => 'complete',
                'part_number' => 1,
                'total_parts' => 1,
                'group_id' => hash('sha256', $canonicalPayload),
                'canonical_payload' => $canonicalPayload,
            ];
        }

        if (($segments[1] ?? null) !== self::ElectionReturnFragmentVersion || count($segments) !== 4) {
            throw new RuntimeException('Election return envelope has an unsupported payload type.');
        }

        $partNumber = filter_var($segments[2] ?? null, FILTER_VALIDATE_INT);
        $totalParts = filter_var($segments[3] ?? null, FILTER_VALIDATE_INT);
        parse_str($queryString, $query);
        $groupId = is_string($query['h'] ?? null) ? $query['h'] : '';
        $encodedFragment = is_string($query['p'] ?? null) ? $query['p'] : '';

        if (
            ! is_int($partNumber)
            || ! is_int($totalParts)
            || $partNumber < 1
            || $totalParts < 2
            || $partNumber > $totalParts
            || $groupId === ''
            || $encodedFragment === ''
        ) {
            throw new RuntimeException('Election return QR fragment metadata is malformed.');
        }

        return [
            'scheme' => self::Scheme,
            'version' => self::Version,
            'document_type' => self::ElectionReturnType,
            'payload_version' => self::ElectionReturnFragmentVersion,
            'part_kind' => 'fragment',
            'part_number' => $partNumber,
            'total_parts' => $totalParts,
            'group_id' => $groupId,
            'canonical_payload' => null,
            'encoded_fragment' => $encodedFragment,
        ];
    }

    private function payloadFromQuery(string $queryString): string
    {
        parse_str($queryString, $query);
        $encoded = $query['p'] ?? null;

        if (! is_string($encoded) || $encoded === '') {
            throw new RuntimeException('Truth QR envelope is missing its payload.');
        }

        return $this->base64UrlDecode($encoded);
    }

    private function base64UrlDecode(string $payload): string
    {
        $remainder = strlen($payload) % 4;

        if ($remainder !== 0) {
            $payload .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('Truth QR envelope contains invalid payload encoding.');
        }

        return $decoded;
    }
}
