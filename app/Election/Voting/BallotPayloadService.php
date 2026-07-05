<?php

namespace App\Election\Voting;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionStorage;
use RuntimeException;

final class BallotPayloadService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @param  array<string, array<int, string>>  $selections
     * @return array<string, mixed>
     */
    public function finalize(array $selections, ?string $ballotId = null, bool $journal = true): array
    {
        $configuration = $this->configuration();
        $this->validateSelections($configuration, $selections);

        $payload = [
            'schema_version' => 'ballot-payload-1',
            'ballot_id' => $ballotId ?? 'ballot-'.substr(hash('sha256', $this->json->encode($selections).microtime()), 0, 12),
            'election_id' => $configuration['election_id'],
            'precinct_id' => $configuration['precinct_id'],
            'ballot_style_id' => $configuration['ballot_style_id'],
            'mapping_hash' => $configuration['mapping_hash'],
            'selections' => $selections,
        ];

        $payload['payload_hash'] = $this->json->hash($payload);
        $payload['qr_payload'] = base64_encode($this->json->encode($payload));
        $this->storage->writeJson("ballots/{$payload['ballot_id']}.json", $payload);

        if ($journal) {
            $this->journal->record('ballot.finalized', [
                'ballot_id' => $payload['ballot_id'],
                'payload_hash' => $payload['payload_hash'],
            ]);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $payload): array
    {
        $decoded = base64_decode($payload, true);
        $json = $decoded === false ? $payload : $decoded;

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function configuration(): array
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');

        if ($configuration === []) {
            throw new RuntimeException('No active precinct configuration.');
        }

        return $configuration;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, array<int, string>>  $selections
     */
    private function validateSelections(array $configuration, array $selections): void
    {
        foreach ($configuration['contests'] as $contest) {
            $selected = $selections[$contest['id']] ?? [];
            $candidateIds = collect($contest['candidates'])->pluck('id')->all();

            if (count($selected) > $contest['max_selections']) {
                throw new RuntimeException("Too many selections for {$contest['title']}.");
            }

            foreach ($selected as $candidateId) {
                if (! in_array($candidateId, $candidateIds, true)) {
                    throw new RuntimeException("Invalid candidate [{$candidateId}].");
                }
            }
        }
    }
}
