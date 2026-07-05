<?php

namespace App\Election\Attestation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;

final class OfficerAttestationService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @param  array{ceremony: string, stage: string, officer_name: string, officer_code?: string|null, statement: string}  $data
     * @return array<string, mixed>
     */
    public function attest(array $data): array
    {
        $sequence = count($this->storage->files('attestations')) + 1;
        $attestationId = sprintf('attestation-%06d', $sequence);

        $record = [
            'attestation_id' => $attestationId,
            'attested_at' => $this->clock->now()->toIso8601String(),
            'ceremony' => $data['ceremony'],
            'officer_code_hash' => filled($data['officer_code'] ?? null)
                ? hash('sha256', trim((string) $data['officer_code']))
                : null,
            'officer_name' => trim($data['officer_name']),
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
            'stage' => $record['stage'],
        ]);

        return $record;
    }
}
