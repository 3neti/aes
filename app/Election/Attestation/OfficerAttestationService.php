<?php

namespace App\Election\Attestation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Validation\ValidationException;

final class OfficerAttestationService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly OfficerRegistry $officers,
    ) {}

    /**
     * @param  array{ceremony: string, stage: string, officer_code: string, officer_pin: string, signature_data: string, statement: string}  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function attest(array $data): array
    {
        $officer = $this->officers->verify($data['officer_code'], $data['officer_pin']);

        if ($officer === null) {
            throw ValidationException::withMessages([
                'officer_pin' => 'The officer code or PIN is invalid.',
            ]);
        }

        $sequence = count($this->storage->files('attestations')) + 1;
        $attestationId = sprintf('attestation-%06d', $sequence);
        $signature = $this->storeSignature($attestationId, $data['signature_data']);

        $record = [
            'attestation_id' => $attestationId,
            'attested_at' => $this->clock->now()->toIso8601String(),
            'ceremony' => $data['ceremony'],
            'officer_code_hash' => hash('sha256', $officer['code']),
            'officer_name' => $officer['name'],
            'officer_role' => $officer['role'],
            'signature_artifact_hash' => $signature['hash'],
            'signature_artifact_path' => $signature['path'],
            'stage' => $data['stage'],
            'statement' => trim($data['statement']),
        ];

        $record['attestation_hash'] = $this->json->hash($record);
        $record['artifact_path'] = $this->storage->writeJson(
            'attestations/'.$attestationId.'-'.substr($record['attestation_hash'], 0, 12).'.json',
            $record,
        );

        $this->journal->record('officer.attested', [
            'attestation_hash' => $record['attestation_hash'],
            'attestation_id' => $attestationId,
            'ceremony' => $record['ceremony'],
            'officer_name' => $record['officer_name'],
            'signature_artifact_hash' => $record['signature_artifact_hash'],
            'stage' => $record['stage'],
        ]);

        return $record;
    }

    /**
     * @return array{hash: string, path: string}
     *
     * @throws ValidationException
     */
    private function storeSignature(string $attestationId, string $signatureData): array
    {
        $encoded = substr($signatureData, strlen('data:image/png;base64,'));
        $contents = base64_decode($encoded, true);

        if ($contents === false || ! str_starts_with($contents, "\x89PNG")) {
            throw ValidationException::withMessages([
                'signature_data' => 'The officer signature must be a PNG image.',
            ]);
        }

        $hash = hash('sha256', $contents);
        $path = $this->storage->writeText(
            'attestation-signatures/'.$attestationId.'-'.substr($hash, 0, 12).'.png',
            $contents,
        );

        return [
            'hash' => $hash,
            'path' => $path,
        ];
    }
}
