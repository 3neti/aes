<?php

namespace App\Election\Counting;

use App\Election\Core\ActivityJournal;
use App\Election\Core\BallotConfigurationLabels;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionStorage;
use App\Election\Support\SimplePdf;
use App\Election\Voting\BallotPayloadService;

final class CountingService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly BallotPayloadService $payloads,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly SimplePdf $pdf,
        private readonly BallotConfigurationLabels $labels,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function accept(string $rawPayload): array
    {
        try {
            $payload = $this->payloads->decode($rawPayload);
            $this->validate($payload);
            $record = [
                'schema_version' => 'counting-record-1',
                'sequence' => count($this->storage->files('counting/accepted')) + 1,
                'ballot_id' => $payload['ballot_id'],
                'payload_hash' => $payload['payload_hash'],
                'selections' => $payload['selections'],
                'status' => 'accepted',
            ];

            $this->storage->writeJson("counting/accepted/{$record['sequence']}-{$payload['payload_hash']}.json", $record);
            $this->journal->record('ballot.counted', [
                'ballot_id' => $payload['ballot_id'],
                'payload_hash' => $payload['payload_hash'],
                'sequence' => $record['sequence'],
            ]);

            return $record;
        } catch (\Throwable $exception) {
            $record = [
                'schema_version' => 'counting-record-1',
                'sequence' => count($this->storage->files('counting/rejected')) + 1,
                'raw_payload_hash' => hash('sha256', $rawPayload),
                'reason' => $exception->getMessage(),
                'status' => 'rejected',
            ];

            $this->storage->writeJson("counting/rejected/{$record['sequence']}-{$record['raw_payload_hash']}.json", $record);
            $this->journal->record('ballot.rejected', $record);

            return $record;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rejectRawInput(string $rawInput, string $reason, string $adapter): array
    {
        $record = [
            'schema_version' => 'counting-record-1',
            'sequence' => count($this->storage->files('counting/rejected')) + 1,
            'adapter' => $adapter,
            'raw_payload_hash' => hash('sha256', $rawInput),
            'reason' => $reason,
            'status' => 'rejected',
        ];

        $this->storage->writeJson("counting/rejected/{$record['sequence']}-{$record['raw_payload_hash']}.json", $record);
        $this->journal->record('ballot.rejected', $record);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public function tally(): array
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $tally = [];

        foreach (($configuration['contests'] ?? []) as $contest) {
            $tally[$contest['id']] = [];

            foreach ($contest['candidates'] as $candidate) {
                $tally[$contest['id']][$candidate['id']] = 0;
            }
        }

        foreach ($this->acceptedRecords() as $record) {
            foreach ($record['selections'] as $contestId => $candidateIds) {
                foreach ($candidateIds as $candidateId) {
                    $tally[$contestId][$candidateId] = ($tally[$contestId][$candidateId] ?? 0) + 1;
                }
            }
        }

        $result = [
            'schema_version' => 'tally-1',
            'accepted_ballots' => count($this->storage->files('counting/accepted')),
            'rejected_ballots' => count($this->storage->files('counting/rejected')),
            'tally' => $tally,
        ];
        $result['tally_hash'] = $this->json->hash($result);
        $this->storage->writeJson('runtime/tally.json', $result);
        $this->writeTallySheet($configuration, $result);

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function acceptedRecords(): array
    {
        return collect($this->storage->files('counting/accepted'))
            ->map(fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validate(array $payload): void
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');

        if (($payload['mapping_hash'] ?? null) !== ($configuration['mapping_hash'] ?? null)) {
            throw new \RuntimeException('Mapping hash mismatch.');
        }

        if (($payload['payload_hash'] ?? null) !== $this->json->hash(array_diff_key($payload, [
            'qr_payload' => true,
            'qr_artifact_path' => true,
            'payload_hash' => true,
        ]))) {
            throw new \RuntimeException('Payload hash mismatch.');
        }

        if (file_exists($this->storage->path("runtime/spoiled-{$payload['payload_hash']}.json"))) {
            throw new \RuntimeException('Ballot is spoiled.');
        }

        foreach ($this->storage->files('counting/accepted') as $path) {
            $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            if (($record['payload_hash'] ?? null) === $payload['payload_hash']) {
                throw new \RuntimeException('Duplicate ballot payload.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $tally
     */
    private function writeTallySheet(array $configuration, array $tally): void
    {
        $lines = [
            'TALLY SHEET',
            'Election: '.($configuration['election_id'] ?? 'unknown'),
            'Precinct: '.($configuration['precinct_id'] ?? 'unknown'),
            'Accepted Ballots: '.($tally['accepted_ballots'] ?? 0),
            'Rejected Ballots: '.($tally['rejected_ballots'] ?? 0),
            'Tally Hash: '.($tally['tally_hash'] ?? 'unknown'),
            '',
            'Totals:',
        ];

        array_push($lines, ...$this->labels->tallyLines($tally['tally'] ?? []));

        $this->storage->writeText('runtime/tally-sheet.txt', implode(PHP_EOL, $lines).PHP_EOL);
        $this->storage->writeText('runtime/tally-sheet.pdf', $this->pdf->render('Tally Sheet', $lines));
    }
}
