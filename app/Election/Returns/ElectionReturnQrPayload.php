<?php

namespace App\Election\Returns;

use App\Election\Core\CanonicalJson;
use App\Election\Documents\DocumentProfileRegistry;
use App\Election\Interoperability\Eml\EmlArtifactSigner;
use App\Election\Support\ElectionStorage;
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
        private readonly ElectionReturnContestScopes $scopes,
        private readonly ElectionStorage $storage,
        private readonly EmlArtifactSigner $signer,
    ) {}

    /**
     * @param  array<string, mixed>  $return
     */
    public function encode(array $return, ElectionReturnScope $scope = ElectionReturnScope::Combined): string
    {
        $material = $this->compactMaterial($return, $scope);

        $signature = $this->signer->sign($this->json->encode($material));

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
            $this->escape((string) ($material['eml']['profile'] ?? '')),
            $this->escape((string) ($material['eml']['artifact_sha256'] ?? '')),
            $this->escape((string) ($material['eml']['signing_key_id'] ?? '')),
            $this->encodeCodeTotals((array) $material['candidate_code_totals']),
            $this->escape((string) $signature['public_key']),
            $this->escape((string) $signature['signature']),
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

        $parts = explode('|', substr($payload, strlen(self::CompactPrefix)), 20);

        if (! in_array(count($parts), [11, 15, 20], true) || $parts[0] !== 'WAESER1') {
            throw new RuntimeException('Compact election return QR payload is malformed.');
        }

        $candidateTotalsIndex = match (count($parts)) {
            20 => 17,
            15 => 14,
            default => 10,
        };
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

        if (count($parts) === 20) {
            $material['document_profile'] = [
                'type' => 'election-return',
                'id' => $this->unescape($parts[10]),
                'hash' => $this->unescape($parts[11]),
                'asset_bundle_id' => $this->unescape($parts[12]),
                'asset_bundle_hash' => $this->unescape($parts[13]),
            ];
            $material['eml'] = [
                'profile' => $this->unescape($parts[14]),
                'artifact_sha256' => $this->unescape($parts[15]),
                'signing_key_id' => $this->unescape($parts[16]),
            ];
            $material['truth_signature'] = [
                'algorithm' => 'Ed25519',
                'key_id' => $material['eml']['signing_key_id'],
                'public_key' => $this->unescape($parts[18]),
                'signature' => $this->unescape($parts[19]),
            ];
        }

        $truthSignature = (array) ($material['truth_signature'] ?? []);
        unset($material['truth_signature']);
        $signatureValid = $truthSignature !== [] && $this->signer->verify($this->json->encode($material), $truthSignature);

        return [
            ...$material,
            'payload_hash' => $this->json->hash($material),
            'truth_signature' => $truthSignature,
            'truth_signature_valid' => $signatureValid,
            'tally' => $this->candidateCodes->tallyForCodeTotals((array) $material['candidate_code_totals']),
        ];
    }

    /**
     * @param  array<string, mixed>  $return
     * @return array<string, mixed>
     */
    private function compactMaterial(array $return, ElectionReturnScope $scope): array
    {
        $eml = (array) data_get($return, "eml.scopes.{$scope->value}", []);

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
            'eml' => [
                'profile' => $return['eml']['profile'] ?? config('election.eml.profile', 'waes-eml-7-base-1'),
                'artifact_sha256' => $eml['sha256'] ?? null,
                'signing_key_id' => $eml['signing_key_id'] ?? null,
            ],
            'candidate_code_totals' => $this->candidateCodes->codeTotalsForTally(
                $this->scopedTally((array) ($return['tally'] ?? []), $scope),
            ),
        ];
    }

    /**
     * @param  array<string, array<string, int>>  $tally
     * @return array<string, array<string, int>>
     */
    private function scopedTally(array $tally, ElectionReturnScope $scope): array
    {
        if ($scope === ElectionReturnScope::Combined) {
            return $tally;
        }

        $configuration = $this->scopes->configurationFor(
            $this->storage->readJson('runtime/active-precinct.json'),
            $scope,
        );
        $contestIds = collect($configuration['contests'] ?? [])
            ->map(fn (array $contest): string => (string) ($contest['id'] ?? ''))
            ->filter()
            ->flip();

        return collect($tally)
            ->filter(fn (array $candidateTotals, string $contestId): bool => $contestIds->has($contestId))
            ->all();
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
