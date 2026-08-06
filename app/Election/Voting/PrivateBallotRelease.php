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
        private readonly BallotQrPayload $qrPayload,
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
        $digits = $this->digits();
        $ballotId = 'ballot-'.Str::lower(Str::random(12));
        $paperBallotSerial = $this->paperBallots->nextSerial();
        $payload = [
            'schema_version' => 'ballot-payload-1',
            'ballot_id' => $ballotId,
            'election_id' => $configuration['election_id'],
            'precinct_id' => $configuration['precinct_id'],
            'ballot_style_id' => $configuration['ballot_style_id'],
            'mapping_hash' => $configuration['mapping_hash'],
            'tabulation_profile' => $configuration['tabulation_profile'],
            'selections' => $selections,
            'paper_ballot_serial' => $paperBallotSerial,
        ];
        $payload['payload_hash'] = $this->json->hash($payload);
        $payload['qr_payload'] = $this->qrPayload->encode($payload);
        $expiresAt = $this->clock->now()->addSeconds(
            (int) config('election.voter.print_release_ttl_seconds', 600),
        );
        $record = [
            'schema_version' => 'private-print-release-1',
            'release_id' => $releaseId,
            'authorization_id' => $authorizationId,
            'release_code_hash' => $this->hash($releaseCode),
            'pin_digits' => $digits,
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
        $this->journal->record('voting.print_pin.generated', [
            'authorization_id' => $authorizationId,
            'release_id' => $releaseId,
            'ballot_id' => $ballotId,
            'pin_digits' => $digits,
            'payload_hash' => $payload['payload_hash'],
            'expires_at' => $record['expires_at'],
        ]);

        return [
            'release_id' => $releaseId,
            'release_code' => $releaseCode,
            'pin_digits' => $digits,
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
        $record = $this->recordForCode($normalized);

        if (! is_array($record) || $record['status'] !== 'pending') {
            $this->journal->record('printing.pin.rejected', [
                'reason' => 'invalid-or-unavailable',
            ]);

            throw new RuntimeException('The print release is invalid or no longer available.');
        }

        if ($this->clock->now()->isAfter($record['expires_at'])) {
            $record['status'] = 'expired';
            $record['expired_at'] = $this->clock->now()->toIso8601String();
            $this->storage->writeJson("print-releases/{$record['release_id']}.json", $record);
            $this->journal->record('voting.print_pin.expired', [
                'release_id' => $record['release_id'],
                'ballot_id' => $record['ballot_id'],
                'payload_hash' => $record['payload_hash'],
            ]);
            $this->journal->record('printing.pin.rejected', [
                'release_id' => $record['release_id'],
                'reason' => 'expired',
            ]);

            throw new RuntimeException('The print release has expired.');
        }

        $record['status'] = 'redeemed';
        $record['redeemed_at'] = $this->clock->now()->toIso8601String();
        $this->storage->writeJson("print-releases/{$record['release_id']}.json", $record);
        $this->journal->record('voting.print_pin.consumed', [
            'release_id' => $record['release_id'],
            'ballot_id' => $record['ballot_id'],
            'payload_hash' => $record['payload_hash'],
        ]);

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

        if (! in_array($record['status'], ['pending', 'redeemed'], true)) {
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
        $this->journal->record('printing.ballot.generated_from_pin', [
            'release_id' => $releaseId,
            'ballot_id' => $payload['ballot_id'],
            'payload_hash' => $payload['payload_hash'],
            'paper_ballot_serial' => $payload['paper_ballot_serial'] ?? null,
            'print_job_path' => $job['artifact_path'] ?? null,
        ]);

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

    /**
     * @return array<string, mixed>|null
     */
    public function printedBallotPreview(string $releaseId): ?array
    {
        $record = $this->record($releaseId);

        if ($record['status'] !== 'printed') {
            return null;
        }

        $payload = $this->decryptPayload($record);
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $selections = $payload['selections'] ?? [];

        if (! is_array($selections)) {
            return null;
        }

        $rows = collect($configuration['contests'] ?? [])
            ->filter(fn (mixed $contest): bool => is_array($contest))
            ->map(function (array $contest) use ($selections): array {
                $contestId = (string) ($contest['id'] ?? '');
                $candidates = collect($contest['candidates'] ?? [])
                    ->filter(fn (mixed $candidate): bool => is_array($candidate))
                    ->keyBy(fn (array $candidate): string => (string) ($candidate['id'] ?? ''));
                $selectedCandidateIds = $selections[$contestId] ?? [];

                $selected = collect(is_array($selectedCandidateIds) ? $selectedCandidateIds : [])
                    ->map(function (mixed $candidateId) use ($candidates): string {
                        $candidate = $candidates->get((string) $candidateId, []);

                        return trim(implode(' ', array_filter([
                            $candidate['ballot_number'] ?? null,
                            $candidate['name'] ?? $candidateId,
                        ])));
                    })
                    ->values()
                    ->all();

                return [
                    'contest' => (string) ($contest['title'] ?? $contestId),
                    'selections' => $selected === [] ? ['UNDERVOTE - No selection'] : $selected,
                ];
            })
            ->values()
            ->all();

        return [
            'paper_ballot_serial' => $payload['paper_ballot_serial'] ?? null,
            'ballot_id' => $payload['ballot_id'] ?? null,
            'rows' => $rows,
        ];
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
        $digits = $this->digits();
        $maximum = (10 ** $digits) - 1;

        foreach (range(1, 20) as $_) {
            $code = str_pad((string) random_int(0, $maximum), $digits, '0', STR_PAD_LEFT);

            if (! $this->activeHashExists($this->hash($code))) {
                return $code;
            }
        }

        throw new RuntimeException('A unique print PIN could not be generated. Try again.');
    }

    private function digits(): int
    {
        return min(6, max(4, (int) config('election.voter.print_pin_digits', 4)));
    }

    private function activeHashExists(string $hash): bool
    {
        return collect($this->storage->files('print-releases'))
            ->map(fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
            ->contains(fn (array $candidate): bool => in_array($candidate['status'] ?? null, ['pending', 'redeemed', 'printed'], true)
                && hash_equals((string) ($candidate['release_code_hash'] ?? ''), $hash));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recordForCode(string $code): ?array
    {
        return collect($this->storage->files('print-releases'))
            ->map(fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
            ->first(fn (array $candidate): bool => hash_equals(
                (string) ($candidate['release_code_hash'] ?? ''),
                $this->hash($code),
            ));
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
