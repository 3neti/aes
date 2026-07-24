<?php

namespace App\Election\Voting;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Printing\BallotPrinter;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

final class PrivateBallotRelease
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly BallotSelectionValidator $selections,
        private readonly PaperBallotLedger $paperBallots,
        private readonly StandardQrCode $qrCode,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @param  array<string, array<int, string>>  $selections
     * @return array<string, mixed>
     */
    public function create(string $authorizationId, array $selections): array
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');

        if ($configuration === []) {
            throw new RuntimeException('No active precinct configuration.');
        }

        $this->selections->validate($configuration, $selections);

        $releaseId = (string) Str::uuid();
        $releaseCode = $this->code();
        $ballotId = 'ballot-'.Str::lower(Str::random(12));
        $paperBallotSerial = $this->paperBallots->nextSerial();
        $payload = [
            'schema_version' => 'ballot-payload-1',
            'ballot_id' => $ballotId,
            'election_id' => $configuration['election_id'],
            'precinct_id' => $configuration['precinct_id'],
            'ballot_style_id' => $configuration['ballot_style_id'],
            'mapping_hash' => $configuration['mapping_hash'],
            'selections' => $selections,
            'paper_ballot_serial' => $paperBallotSerial,
        ];
        $payload['payload_hash'] = $this->json->hash($payload);
        $payload['qr_payload'] = base64_encode($this->json->encode($payload));
        $expiresAt = $this->clock->now()->addSeconds(
            (int) config('election.voter.print_release_ttl_seconds', 600),
        );
        $record = [
            'schema_version' => 'private-print-release-1',
            'release_id' => $releaseId,
            'authorization_id' => $authorizationId,
            'release_code_hash' => $this->hash($releaseCode),
            'ballot_id' => $ballotId,
            'paper_ballot_serial' => $paperBallotSerial,
            'payload_hash' => $payload['payload_hash'],
            'encrypted_payload' => Crypt::encryptString($this->json->encode($payload)),
            'status' => 'pending',
            'created_at' => $this->clock->now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        $this->storage->writeJson("print-releases/{$releaseId}.json", $record);

        if ($paperBallotSerial !== null) {
            $this->paperBallots->recordIssued($paperBallotSerial, $ballotId, $payload['payload_hash']);
        }

        $this->journal->record('ballot.finalized_privately', [
            'authorization_id' => $authorizationId,
            'release_id' => $releaseId,
            'ballot_id' => $ballotId,
            'payload_hash' => $payload['payload_hash'],
            'paper_ballot_serial' => $paperBallotSerial,
        ]);

        return [
            'release_id' => $releaseId,
            'release_code' => $releaseCode,
            'release_qr_data_uri' => 'data:image/png;base64,'.base64_encode(
                $this->qrCode->renderPng('aes-print-release:'.$releaseCode),
            ),
            'paper_ballot_serial' => $paperBallotSerial,
            'expires_at' => $record['expires_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function redeem(string $code): array
    {
        $normalized = Str::after(Str::lower(trim($code)), 'aes-print-release:');
        $record = collect($this->storage->files('print-releases'))
            ->map(fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
            ->first(fn (array $candidate): bool => hash_equals(
                (string) ($candidate['release_code_hash'] ?? ''),
                $this->hash($normalized),
            ));

        if (! is_array($record) || ! in_array($record['status'], ['pending', 'printed'], true)) {
            throw new RuntimeException('The print release is invalid or no longer available.');
        }

        if ($this->clock->now()->isAfter($record['expires_at'])) {
            throw new RuntimeException('The print release has expired.');
        }

        return $this->publicRecord($record);
    }

    /**
     * @return array<string, mixed>
     */
    public function find(string $releaseId): array
    {
        $record = $this->storage->readJson("print-releases/{$releaseId}.json");

        if ($record === []) {
            throw new RuntimeException('The print release was not found.');
        }

        return $this->publicRecord($record);
    }

    /**
     * @return array<string, mixed>
     */
    public function print(string $releaseId, BallotPrinter $printer): array
    {
        $record = $this->record($releaseId);

        if ($record['status'] !== 'pending') {
            throw new RuntimeException('This paper ballot has already been printed.');
        }

        $payload = $this->decryptPayload($record);
        $payload['qr_artifact_path'] = $this->storage->writeText(
            "ballots/{$payload['ballot_id']}-qr.png",
            $this->qrCode->renderPng($payload['qr_payload']),
        );
        $job = $printer->print($payload);
        $record['status'] = 'printed';
        $record['printed_at'] = $this->clock->now()->toIso8601String();
        $this->storage->writeJson("print-releases/{$releaseId}.json", $record);

        return $job;
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadForDeposit(string $releaseId): array
    {
        $record = $this->record($releaseId);

        if ($record['status'] !== 'printed') {
            throw new RuntimeException('The paper ballot must be printed before it can be deposited.');
        }

        return $this->decryptPayload($record);
    }

    public function markDeposited(string $releaseId): void
    {
        $record = $this->record($releaseId);
        $record['status'] = 'deposited';
        $record['deposited_at'] = $this->clock->now()->toIso8601String();
        $this->storage->writeJson("print-releases/{$releaseId}.json", $record);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(string $releaseId): array
    {
        $record = $this->storage->readJson("print-releases/{$releaseId}.json");

        if ($record === []) {
            throw new RuntimeException('The print release was not found.');
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function decryptPayload(array $record): array
    {
        return json_decode(
            Crypt::decryptString($record['encrypted_payload']),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', Str::upper(str_replace(' ', '', trim($code))), (string) config('app.key'));
    }

    private function code(): string
    {
        return implode('-', [
            (string) random_int(1000, 9999),
            (string) random_int(1000, 9999),
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function publicRecord(array $record): array
    {
        return collect($record)->except([
            'authorization_id',
            'release_code_hash',
            'encrypted_payload',
            'payload_hash',
        ])->all();
    }
}
