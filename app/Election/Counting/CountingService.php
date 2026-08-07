<?php

namespace App\Election\Counting;

use App\Election\Core\ActivityJournal;
use App\Election\Core\BallotConfigurationLabels;
use App\Election\Core\CanonicalJson;
use App\Election\Printing\Documents\TallySheetPdf;
use App\Election\Printing\PrintFormArtifactService;
use App\Election\Support\ElectionStorage;
use App\Election\Tabulation\DeviceTabulationLedger;
use App\Election\Tabulation\TabulationProfileResolver;
use App\Election\Voting\BallotPayloadService;
use App\Election\Voting\PaperBallotLedger;
use RuntimeException;

final class CountingService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly BallotPayloadService $payloads,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly TallySheetPdf $pdf,
        private readonly PrintFormArtifactService $forms,
        private readonly BallotConfigurationLabels $labels,
        private readonly PaperBallotLedger $paperBallots,
        private readonly TabulationProfileResolver $tabulation,
        private readonly DeviceTabulationLedger $deviceLedger,
        private readonly TallyPresentation $presentation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function accept(string $rawPayload, bool $recordDeposit = true): array
    {
        try {
            $payload = $this->payloads->decode($rawPayload);
            $this->validate($payload);
            $record = [
                'schema_version' => 'counting-record-1',
                'sequence' => count($this->storage->files('counting/accepted')) + 1,
                'ballot_id' => $payload['ballot_id'],
                'payload_hash' => $payload['payload_hash'],
                'paper_ballot_serial' => $payload['paper_ballot_serial'] ?? null,
                'selections' => $payload['selections'],
                'status' => 'accepted',
            ];

            $this->storage->writeJson("counting/accepted/{$record['sequence']}-{$payload['payload_hash']}.json", $record);
            if ($recordDeposit) {
                $this->paperBallots->recordDeposited($payload['payload_hash']);
            }
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
    public function recordRoutineScanBlocked(string $rawInput): array
    {
        $record = [
            'adapter' => 'routine-scan-blocked',
            'raw_payload_hash' => hash('sha256', $rawInput),
            'reason' => 'Routine QR scanning is reserved for random manual audit under the configured tabulation profile.',
            'status' => 'rejected',
        ];
        $this->journal->record('counting.routine_scan_blocked', $record);

        return [
            ...$record,
            'sequence' => null,
            'ballot_id' => null,
            'payload_hash' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tally(): array
    {
        $profile = $this->tabulation->current();
        $records = $profile->routineScanningEnabled()
            ? $this->acceptedRecords()
            : $this->deviceLedger->recordsForTally();

        return $this->tallyRecords($records, $profile->value, $profile->tallySource());
    }

    /**
     * Certification ballots are controlled test records and never become device tabulation records.
     *
     * @return array<string, mixed>
     */
    public function tallyCertificationRecords(): array
    {
        return $this->tallyRecords(
            $this->acceptedRecords(),
            $this->tabulation->current()->value,
            'certification accepted ballot scans',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function tallyRecords(array $records, string $profile, string $source): array
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $tally = [];

        foreach (($configuration['contests'] ?? []) as $contest) {
            $tally[$contest['id']] = [];

            foreach ($contest['candidates'] as $candidate) {
                $tally[$contest['id']][$candidate['id']] = 0;
            }
        }

        foreach ($records as $record) {
            foreach ($record['selections'] as $contestId => $candidateIds) {
                foreach ($candidateIds as $candidateId) {
                    $tally[$contestId][$candidateId] = ($tally[$contestId][$candidateId] ?? 0) + 1;
                }
            }
        }

        $result = [
            'schema_version' => 'tally-1',
            'accepted_ballots' => count($records),
            'rejected_ballots' => count($this->storage->files('counting/rejected')),
            'tabulation_profile' => $profile,
            'tally_source' => $source,
            'tally' => $tally,
            'display_tally' => $this->presentation->displayTally($tally),
            'paper_ballot_accounting' => $this->paperBallots->summary(),
            'device_tabulation' => $this->deviceLedger->summary(),
        ];
        $result['display_summary'] = $this->presentation->summary($result['display_tally']);
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
            ->map(fn (string $path): array => $this->readRecord($path))
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
            throw new RuntimeException('Mapping hash mismatch.');
        }

        if (($payload['tabulation_profile'] ?? null) !== ($configuration['tabulation_profile'] ?? null)) {
            throw new RuntimeException('Tabulation profile mismatch.');
        }

        if (($payload['payload_hash'] ?? null) !== $this->payloadHash($payload)) {
            throw new RuntimeException('Payload hash mismatch.');
        }

        if (file_exists($this->storage->path("runtime/spoiled-{$payload['payload_hash']}.json"))) {
            throw new RuntimeException('Ballot is spoiled.');
        }

        foreach ($this->storage->files('counting/accepted') as $path) {
            $record = $this->readRecord($path);

            if (($record['payload_hash'] ?? null) === $payload['payload_hash']) {
                throw new RuntimeException('Duplicate ballot payload.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        if (($payload['payload_hash_profile'] ?? null) === 'compact-selection-1') {
            return $this->json->hash([
                'schema_version' => 'ballot-payload-compact-1',
                'election_id' => $payload['election_id'] ?? null,
                'precinct_id' => $payload['precinct_id'] ?? null,
                'ballot_style_id' => $payload['ballot_style_id'] ?? null,
                'mapping_hash' => $payload['mapping_hash'] ?? null,
                'tabulation_profile' => $payload['tabulation_profile'] ?? null,
                'paper_ballot_serial' => $payload['paper_ballot_serial'] ?? null,
                'candidate_codes' => $payload['candidate_codes'] ?? [],
            ]);
        }

        return $this->json->hash(array_diff_key($payload, [
            'qr_payload' => true,
            'qr_artifact_path' => true,
            'payload_hash' => true,
        ]));
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

        $displayTally = $this->presentation->forHumanArtifacts($tally);

        array_push($lines, ...$this->labels->displayTallyLines($displayTally['tally'] ?? []));

        $this->storage->writeText('runtime/tally-sheet.txt', implode(PHP_EOL, $lines).PHP_EOL);
        $this->storage->writeText(
            'runtime/tally-sheet.pdf',
            $this->pdf->render($configuration, $displayTally),
        );
        $this->forms->writeTally($configuration, $displayTally);
    }

    /**
     * @return array<string, mixed>
     */
    private function readRecord(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read counting record [{$path}].");
        }

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }
}
