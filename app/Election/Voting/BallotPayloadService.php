<?php

namespace App\Election\Voting;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Documents\DocumentProfileRegistry;
use App\Election\Support\ElectionOperationLock;
use App\Election\Support\ElectionStorage;
use LBHurtado\TruthQr\Contracts\RecordService;
use LBHurtado\TruthQr\Core\RecordPayload;
use RuntimeException;

final class BallotPayloadService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly StandardQrCode $qrCode,
        private readonly BallotQrPayload $qrPayload,
        private readonly BallotPayloadEnvelope $envelope,
        private readonly PaperBallotLedger $paperBallots,
        private readonly BallotSelectionValidator $selections,
        private readonly DocumentProfileRegistry $documents,
        private readonly RecordService $truthQr,
        private readonly ElectionOperationLock $operationLock,
    ) {}

    /**
     * @param  array<string, array<int, string>>  $selections
     * @return array<string, mixed>
     */
    public function finalize(array $selections, ?string $ballotId = null, bool $journal = true, ?string $testBallotSerial = null): array
    {
        return $this->operationLock->execute(
            'private-ballot-finalization',
            fn (): array => $this->finalizeWithinLock($selections, $ballotId, $journal, $testBallotSerial),
        );
    }

    /**
     * @param  array<string, array<int, string>>  $selections
     * @return array<string, mixed>
     */
    private function finalizeWithinLock(array $selections, ?string $ballotId, bool $journal, ?string $testBallotSerial): array
    {
        if ($journal && $testBallotSerial !== null) {
            throw new RuntimeException('A test ballot serial cannot be used for an issued ballot.');
        }

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
            'document_profile' => $this->documents->ballotReference(),
            'selections' => $selections,
        ];

        $paperBallotSerial = $journal
            ? $this->paperBallots->nextRequiredSerial((string) ($configuration['precinct_id'] ?? 'PRECINCT'))
            : $testBallotSerial;

        if ($paperBallotSerial !== null) {
            $payload['paper_ballot_serial'] = $paperBallotSerial;
        }

        $payload['payload_hash'] = $this->qrPayload->compactHash($payload);
        $payload['canonical_qr_payload'] = $this->qrPayload->encode($payload);
        $payload['qr_payload'] = $this->truthQr->encodeRecord(new RecordPayload('aes-election-v1', 'ballot', $payload))[0];
        $payload['qr_artifact_path'] = $this->storage->writeText(
            "ballots/{$payload['ballot_id']}-qr.png",
            $this->qrCode->renderPrintPng($payload['qr_payload']),
        );
        $payload['qr_svg_artifact_path'] = $this->storage->writeText(
            "ballots/{$payload['ballot_id']}-qr.svg",
            $this->qrCode->renderSvg($payload['qr_payload']),
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

        return $this->qrPayload->decode($this->envelope->unwrap($payload));
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
