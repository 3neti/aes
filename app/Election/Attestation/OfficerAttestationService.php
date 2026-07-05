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
     * @param  array{ceremony: string, stage: string, officer_code: string, officer_pin: string, statement: string}  $data
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

        $record = [
            'attestation_id' => $attestationId,
            'attested_at' => $this->clock->now()->toIso8601String(),
            'ceremony' => $data['ceremony'],
            'officer_code_hash' => hash('sha256', $officer['code']),
            'officer_name' => $officer['name'],
            'officer_role' => $officer['role'],
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
