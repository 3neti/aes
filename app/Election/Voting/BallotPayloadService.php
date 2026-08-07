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
        private readonly StandardQrCode $qrCode,
        private readonly BallotQrPayload $qrPayload,
        private readonly PaperBallotLedger $paperBallots,
        private readonly BallotSelectionValidator $selections,
    ) {}

    /**
     * @param  array<string, array<int, string>>  $selections
     * @return array<string, mixed>
     */
    public function finalize(array $selections, ?string $ballotId = null, bool $journal = true): array
    {
        $configuration = $this->configuration();
        $this->selections->validate($configuration, $selections);

        $payload = [
            'schema_version' => 'ballot-payload-1',
            'ballot_id' => $ballotId ?? 'ballot-'.substr(hash('sha256', $this->json->encode($selections).microtime()), 0, 12),
            'election_id' => $configuration['election_id'],
            'precinct_id' => $configuration['precinct_id'],
            'ballot_style_id' => $configuration['ballot_style_id'],
            'mapping_hash' => $configuration['mapping_hash'],
            'tabulation_profile' => $configuration['tabulation_profile'],
            'payload_hash_profile' => 'compact-selection-1',
            'selections' => $selections,
        ];

        $paperBallotSerial = $journal ? $this->paperBallots->nextSerial() : null;

        if ($paperBallotSerial !== null) {
            $payload['paper_ballot_serial'] = $paperBallotSerial;
        }

        $payload['payload_hash'] = $this->qrPayload->compactHash($payload);
        $payload['qr_payload'] = $this->qrPayload->encode($payload);
        $payload['qr_artifact_path'] = $this->storage->writeText(
            "ballots/{$payload['ballot_id']}-qr.png",
            $this->qrCode->renderPng($payload['qr_payload']),
        );
        $this->storage->writeJson("ballots/{$payload['ballot_id']}.json", $payload);

        if ($journal) {
            if ($paperBallotSerial !== null) {
                $this->paperBallots->recordIssued($paperBallotSerial, $payload['ballot_id'], $payload['payload_hash']);
            }

            $this->journal->record('ballot.finalized', [
                'ballot_id' => $payload['ballot_id'],
                'payload_hash' => $payload['payload_hash'],
                'paper_ballot_serial' => $paperBallotSerial,
            ]);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $payload): array
    {
        if (str_starts_with($payload, "\x89PNG")) {
            $payload = $this->qrCode->decodePngBytes($payload);
        }

        return $this->qrPayload->decode($payload);
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
}
