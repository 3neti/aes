<?php

namespace App\Election\Interoperability\Eml;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Returns\ElectionReturnContestScopes;
use App\Election\Returns\ElectionReturnScope;
use App\Election\Support\ElectionStorage;
use RuntimeException;

final class EmlArtifactService
{
    public function __construct(
        private readonly WaesEml7Profile $profile,
        private readonly EmlSchemaRegistry $schemas,
        private readonly SecureXml $xml,
        private readonly EmlArtifactSigner $signer,
        private readonly ElectionStorage $storage,
        private readonly CanonicalJson $json,
        private readonly ElectionReturnContestScopes $scopes,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function export(EmlMessageType $messageType, array $context, string $relativePath): EmlArtifact
    {
        $xml = $this->profile->serialize($messageType, $context);
        $validation = $this->schemas->validate($messageType, $xml);

        if (! ($validation['valid'] ?? false)) {
            $message = (string) data_get($validation, 'errors.0.message', 'Unknown schema validation error.');

            throw new RuntimeException("Generated EML {$messageType->value} failed schema validation: {$message}");
        }

        $canonicalXml = $this->xml->canonicalize($xml);
        $sha256 = hash('sha256', $canonicalXml);
        $signature = [
            'schema_version' => 'waes-eml-detached-signature-1',
            'profile' => $this->profile->id(),
            'message_type' => $messageType->value,
            'artifact_sha256' => $sha256,
            'schema_profile_hash' => $validation['schema_profile_hash'],
            'source_binding' => array_filter([
                'scope' => $context['scope'] ?? null,
                'mapping_hash' => $context['mapping_hash'] ?? data_get($context, 'configuration.mapping_hash'),
                'tally_hash' => $context['tally_hash'] ?? data_get($context, 'result.tally_hash'),
                'return_hash' => $context['return_hash'] ?? data_get($context, 'result.return_hash'),
                'canonical_source_hash' => $this->json->hash($context),
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            ...$this->signer->sign($canonicalXml),
        ];
        $relativePath = ltrim($relativePath, '/');
        $signatureRelativePath = $relativePath.'.signature.json';
        $absolutePath = $this->storage->writeText($relativePath, $xml);
        $this->storage->writeJson($signatureRelativePath, $signature);

        $this->journal->record('eml.artifact_exported', [
            'profile' => $this->profile->id(),
            'message_type' => $messageType->value,
            'artifact_sha256' => $sha256,
            'relative_path' => $relativePath,
        ]);

        return new EmlArtifact(
            $messageType,
            $this->profile->id(),
            $xml,
            $canonicalXml,
            $sha256,
            $signature,
            $validation,
            $relativePath,
            $absolutePath,
            $signatureRelativePath,
        );
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $return
     * @return array<string, array<string, mixed>>
     */
    public function precinctCountArtifacts(array $configuration, array $return): array
    {
        $precinctId = (string) ($return['precinct_id'] ?? 'unknown-precinct');
        $configuration['precinct_id'] = $precinctId;

        return collect(ElectionReturnScope::cases())
            ->mapWithKeys(function (ElectionReturnScope $scope) use ($configuration, $return, $precinctId): array {
                $scopedConfiguration = $this->scopes->configurationFor($configuration, $scope);
                $scopedReturn = $this->scopedResult($scopedConfiguration, $return);
                $artifact = $this->export(EmlMessageType::PrecinctCount, [
                    'configuration' => $scopedConfiguration,
                    'result' => $scopedReturn,
                    'scope' => $scope->value,
                    'mapping_hash' => $return['mapping_hash'] ?? null,
                    'tally_hash' => $return['tally_hash'] ?? null,
                    'return_hash' => $return['return_hash'] ?? null,
                    'final' => (bool) ($return['final'] ?? false),
                ], "returns/eml/{$precinctId}-{$scope->value}-510.xml");

                return [$scope->value => $artifact->reference()];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, array<string, mixed>>
     */
    public function configurationArtifacts(array $configuration): array
    {
        return collect([
            EmlMessageType::ElectionEvent,
            EmlMessageType::CandidateList,
            EmlMessageType::BallotDefinition,
        ])->mapWithKeys(function (EmlMessageType $type) use ($configuration): array {
            $artifact = $this->export($type, [
                'configuration' => $configuration,
                'mapping_hash' => $configuration['mapping_hash'] ?? null,
            ], "interoperability/eml/configuration/{$type->value}-{$type->slug()}.xml");

            return [$type->value => $artifact->reference()];
        })->all();
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<int, array<string, mixed>>  $acceptedReturns
     * @return array<string, array<string, mixed>>
     */
    public function canvassArtifacts(array $configuration, array $acceptedReturns): array
    {
        $result = $this->aggregateReturns($configuration, $acceptedReturns);

        return collect([EmlMessageType::CanvassResult, EmlMessageType::Statistics])
            ->mapWithKeys(function (EmlMessageType $type) use ($configuration, $result): array {
                $artifact = $this->export($type, [
                    'configuration' => $configuration,
                    'result' => $result,
                    'scope' => 'city-municipality',
                    'mapping_hash' => $configuration['mapping_hash'] ?? null,
                    'tally_hash' => $result['tally_hash'],
                    'final' => true,
                ], "canvassing/eml/{$type->value}-{$type->slug()}.xml");

                return [$type->value => $artifact->reference()];
            })->all();
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    public function auditArtifact(array $configuration, array $entries): array
    {
        return $this->export(EmlMessageType::AuditLog, [
            'configuration' => $configuration,
            'audit_entries' => $entries,
            'mapping_hash' => $configuration['mapping_hash'] ?? null,
        ], 'audit/eml/480-audit-log.xml')->reference();
    }

    /** @return array<string, mixed> */
    public function verifyStored(string $relativePath): array
    {
        $xml = $this->storage->readText($relativePath);
        $signature = $this->storage->readJson($relativePath.'.signature.json');

        return $this->verifyContents($xml, $signature);
    }

    /**
     * @param  array<string, mixed>  $signature
     * @return array<string, mixed>
     */
    public function verifyContents(string $xml, array $signature = []): array
    {
        $messageType = EmlMessageType::tryFrom((string) ($signature['message_type'] ?? ''));

        if ($xml === '') {
            return ['valid' => false, 'reason' => 'artifact_missing'];
        }

        if ($messageType === null) {
            $messageType = EmlMessageType::tryFrom((string) $this->xml->load($xml)->documentElement?->getAttribute('Id'));
        }

        if ($messageType === null) {
            return ['valid' => false, 'reason' => 'unsupported_message_type'];
        }

        $canonical = $this->xml->canonicalize($xml);
        $artifactHash = hash('sha256', $canonical);
        $schema = $this->schemas->validate($messageType, $xml);
        $signatureSupplied = $signature !== [];
        $hashValid = $signatureSupplied && hash_equals((string) ($signature['artifact_sha256'] ?? ''), $artifactHash);
        $signatureValid = $signatureSupplied && $this->signer->verify($canonical, $signature);

        return [
            'valid' => ($schema['valid'] ?? false) && $hashValid && $signatureValid,
            'schema_only_valid' => (bool) ($schema['valid'] ?? false),
            'signature_supplied' => $signatureSupplied,
            'profile' => $signature['profile'] ?? null,
            'message_type' => $messageType->value,
            'artifact_sha256' => $artifactHash,
            'schema_valid' => $schema['valid'] ?? false,
            'hash_valid' => $hashValid,
            'signature_valid' => $signatureValid,
            'signing_key_id' => $signature['key_id'] ?? null,
            'schema_profile_hash' => $schema['schema_profile_hash'] ?? null,
            'errors' => $schema['errors'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $return
     * @return array<string, mixed>
     */
    private function scopedResult(array $configuration, array $return): array
    {
        $contestIds = collect($configuration['contests'] ?? [])->pluck('id')->map(fn (mixed $id): string => (string) $id)->flip();

        return [
            ...$return,
            'tally' => collect((array) ($return['tally'] ?? []))
                ->filter(fn (mixed $candidateTotals, string $contestId): bool => $contestIds->has($contestId))
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<int, array<string, mixed>>  $returns
     * @return array<string, mixed>
     */
    private function aggregateReturns(array $configuration, array $returns): array
    {
        $tally = collect((array) ($configuration['contests'] ?? []))->mapWithKeys(fn (array $contest): array => [
            (string) $contest['id'] => collect((array) ($contest['candidates'] ?? []))->mapWithKeys(
                fn (array $candidate): array => [(string) $candidate['id'] => 0],
            )->all(),
        ])->all();

        foreach ($returns as $return) {
            foreach ((array) ($return['tally'] ?? []) as $contestId => $candidateTotals) {
                foreach ((array) $candidateTotals as $candidateId => $votes) {
                    $tally[(string) $contestId][(string) $candidateId] = ($tally[(string) $contestId][(string) $candidateId] ?? 0) + (int) $votes;
                }
            }
        }

        $result = [
            'accepted_ballots' => collect($returns)->sum(fn (array $return): int => (int) ($return['accepted_ballots'] ?? 0)),
            'rejected_ballots' => collect($returns)->sum(fn (array $return): int => (int) ($return['rejected_ballots'] ?? 0)),
            'accepted_returns' => count($returns),
            'tally' => $tally,
        ];
        $result['tally_hash'] = $this->json->hash($result);

        return $result;
    }
}
