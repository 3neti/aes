<?php

namespace App\Election\Returns;

use App\Election\Core\CanonicalJson;
use App\Election\Documents\DocumentProfileRegistry;
use App\Election\Voting\CandidateCodeMap;
use RuntimeException;

final class ElectionReturnQrPayload
{
    public const PayloadVersion = 'waes-er-compact-1';

    private const CompactPrefix = self::PayloadVersion.':';

    public function __construct(
        private readonly CanonicalJson $json,
        private readonly CandidateCodeMap $candidateCodes,
        private readonly DocumentProfileRegistry $documents,
    ) {}

    /**
     * @param  array<string, mixed>  $return
     */
    public function encode(array $return, ElectionReturnScope $scope = ElectionReturnScope::Combined): string
    {
        $material = $this->compactMaterial($return, $scope);

        return self::CompactPrefix.implode('|', [
            'WAESER1',
            $this->escape((string) $material['election_id']),
            $this->escape((string) $material['precinct_id']),
            $this->escape((string) $material['return_scope']),
            $this->escape((string) $material['mapping_hash']),
            $this->escape((string) $material['tabulation_profile']),
            (string) $material['accepted_ballots'],
            (string) $material['rejected_ballots'],
            $this->escape((string) $material['tally_hash']),
            $this->escape((string) $material['return_hash']),
            $this->escape((string) ($material['document_profile']['id'] ?? '')),
            $this->escape((string) ($material['document_profile']['hash'] ?? '')),
            $this->escape((string) ($material['document_profile']['asset_bundle_id'] ?? '')),
            $this->escape((string) ($material['document_profile']['asset_bundle_hash'] ?? '')),
            $this->encodeCodeTotals((array) $material['candidate_code_totals']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $return
     */
    public function compactHash(array $return, ElectionReturnScope $scope = ElectionReturnScope::Combined): string
    {
        return $this->json->hash($this->compactMaterial($return, $scope));
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $payload): array
    {
        if (! str_starts_with($payload, self::CompactPrefix)) {
            throw new RuntimeException('Election return QR payload has an unsupported format.');
        }

        $parts = explode('|', substr($payload, strlen(self::CompactPrefix)), 15);

        if (! in_array(count($parts), [11, 15], true) || $parts[0] !== 'WAESER1') {
            throw new RuntimeException('Compact election return QR payload is malformed.');
        }

        $candidateTotalsIndex = count($parts) === 15 ? 14 : 10;
        $material = [
            'schema_version' => 'election-return-payload-compact-1',
            'election_id' => $this->unescape($parts[1]),
            'precinct_id' => $this->unescape($parts[2]),
            'return_scope' => $this->unescape($parts[3]),
            'mapping_hash' => $this->unescape($parts[4]),
            'tabulation_profile' => $this->unescape($parts[5]),
            'accepted_ballots' => (int) $parts[6],
            'rejected_ballots' => (int) $parts[7],
            'tally_hash' => $this->unescape($parts[8]),
            'return_hash' => $this->unescape($parts[9]),
            'candidate_code_totals' => $this->decodeCodeTotals($parts[$candidateTotalsIndex]),
        ];

        if (count($parts) === 15) {
            $material['document_profile'] = [
                'type' => 'election-return',
                'id' => $this->unescape($parts[10]),
                'hash' => $this->unescape($parts[11]),
                'asset_bundle_id' => $this->unescape($parts[12]),
                'asset_bundle_hash' => $this->unescape($parts[13]),
            ];
        }

        return [
            ...$material,
            'payload_hash' => $this->json->hash($material),
            'tally' => $this->candidateCodes->tallyForCodeTotals((array) $material['candidate_code_totals']),
        ];
    }

    /**
     * @param  array<string, mixed>  $return
     * @return array<string, mixed>
     */
    private function compactMaterial(array $return, ElectionReturnScope $scope): array
    {
        return [
            'schema_version' => 'election-return-payload-compact-1',
            'election_id' => $return['election_id'] ?? null,
            'precinct_id' => $return['precinct_id'] ?? null,
            'return_scope' => $scope->value,
            'mapping_hash' => $return['mapping_hash'] ?? null,
            'tabulation_profile' => $return['tabulation_profile'] ?? null,
            'accepted_ballots' => (int) ($return['accepted_ballots'] ?? 0),
            'rejected_ballots' => (int) ($return['rejected_ballots'] ?? 0),
            'tally_hash' => $return['tally_hash'] ?? null,
            'return_hash' => $return['return_hash'] ?? null,
            'document_profile' => $return['document_profile'] ?? $this->documents->electionReturnReference(),
            'candidate_code_totals' => $this->candidateCodes->codeTotalsForTally((array) ($return['tally'] ?? [])),
        ];
    }

    /** @param array<string, int> $totals */
    private function encodeCodeTotals(array $totals): string
    {
        ksort($totals);

        return collect($totals)
            ->map(fn (int $votes, string $code): string => $code.'='.$votes)
            ->implode(',');
    }

    /** @return array<string, int> */
    private function decodeCodeTotals(string $totals): array
    {
        if ($totals === '') {
            return [];
        }

        return collect(explode(',', $totals))
            ->mapWithKeys(function (string $pair): array {
                $parts = explode('=', $pair, 2);

                if (count($parts) !== 2 || $parts[0] === '' || ! ctype_digit($parts[1])) {
                    throw new RuntimeException('Compact election return QR vote totals are malformed.');
                }

                return [$parts[0] => (int) $parts[1]];
            })
            ->all();
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
