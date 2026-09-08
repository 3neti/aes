<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Counting\TallyPresentation;
use App\Election\Documents\DocumentProfileRegistry;
use App\Election\Preparation\ActivatePrecinctBallotPackage;
use App\Election\Preparation\ClcCandidateImporter;
use App\Election\Preparation\PopWorkbookImporter;
use App\Election\Returns\ElectionReturnPayloadEnvelope;
use App\Election\Returns\ElectionReturnQrPayload;
use App\Election\Returns\ElectionReturnScope;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\BallotPayloadEnvelope;
use App\Election\Voting\BallotPayloadService;
use App\Election\Voting\BallotQrPayload;
use App\Election\Voting\StandardQrCode;

final class CanvassingDemoSimulation
{
    private const StatePath = 'runtime/canvassing-demo.json';

    private const MaximumBallots = 1000;

    private const DefaultReturnCount = 5;

    private const MaximumReturnCount = 20;

    private const TondoClusteredPrecinct = '39010402';

    private const TondoDistrict = 'SECOND DIST';

    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly PopWorkbookImporter $popImporter,
        private readonly ClcCandidateImporter $clcImporter,
        private readonly ActivatePrecinctBallotPackage $activatePrecinctBallot,
        private readonly BallotQrPayload $ballotQrPayload,
        private readonly BallotPayloadEnvelope $ballotEnvelope,
        private readonly BallotPayloadService $ballotPayloads,
        private readonly ElectionReturnPayloadEnvelope $returnEnvelope,
        private readonly ElectionReturnQrPayload $returnQrPayload,
        private readonly StandardQrCode $qrCode,
        private readonly TallyPresentation $presentation,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly DocumentProfileRegistry $documents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $configuration = $this->configuration();
        $state = $this->storage->readJson(self::StatePath);
        $state = ($state['mapping_hash'] ?? null) === ($configuration['mapping_hash'] ?? null)
            ? $state
            : [];
        $returnScans = (array) ($state['return_scans'] ?? []);

        if ($returnScans === [] && is_array($state['return_scan'] ?? null)) {
            $returnScans = [$state['return_scan']];
        }

        return [
            'schema_version' => 'canvassing-demo-summary-1',
            'maximum_ballots' => self::MaximumBallots,
            'maximum_returns' => self::MaximumReturnCount,
            'default_ballots' => self::MaximumBallots,
            'default_returns' => self::DefaultReturnCount,
            'configuration' => $this->configurationSummary($configuration),
            'ballot_definition' => $this->ballotDefinitionSummary(),
            'ballot' => [
                'contests' => $this->contests($configuration),
            ],
            'run' => $state === [] ? null : $state,
            'scanner' => [
                'returns' => $state === [] ? [] : $returnScans,
                'initial_tally' => $this->emptyTally($configuration),
            ],
            'document_rendering' => $this->documents->renderingKit(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(int $ballotCount, int $returnCount = self::DefaultReturnCount): array
    {
        $ballotCount = min(self::MaximumBallots, max(1, $ballotCount));
        $returnCount = min(self::MaximumReturnCount, max(1, $returnCount));

        $this->storage->startRun('canvassing-demo', self::TondoClusteredPrecinct, now()->format('Ymd-His'), creationSource: 'canvassing-demo');
        $configuration = $this->activateTondoPackage();
        $sampleBallots = [];
        $returnScans = [];
        $returnHashes = [];
        $tallyHashes = [];

        for ($returnIndex = 1; $returnIndex <= $returnCount; $returnIndex++) {
            $tally = $this->emptyTally($configuration);
            $precinctId = sprintf('%s-CV%03d', (string) ($configuration['precinct_id'] ?? '0421-A'), $returnIndex);

            for ($ballotIndex = 1; $ballotIndex <= $ballotCount; $ballotIndex++) {
                $sequence = (($returnIndex - 1) * $ballotCount) + $ballotIndex;
                $payload = $this->ballotPayload($configuration, $sequence, $precinctId);
                $payload['payload_hash'] = $this->ballotQrPayload->compactHash($payload);
                $canonicalPayload = $this->ballotQrPayload->encode($payload);
                $qrPayload = $this->ballotEnvelope->wrap($canonicalPayload);
                $decoded = $this->ballotPayloads->decode($qrPayload);
                $this->addSelections($tally, (array) ($decoded['selections'] ?? []));

                if (count($sampleBallots) < 10) {
                    $sampleBallots[] = [
                        'sequence' => $sequence,
                        'precinct_id' => $precinctId,
                        'paper_ballot_serial' => $payload['paper_ballot_serial'],
                        'payload_hash' => $decoded['payload_hash'] ?? null,
                        'qr_payload' => $qrPayload,
                        'canonical_payload' => $canonicalPayload,
                    ];
                }
            }

            $tallyResult = $this->tallyResult($configuration, $tally, $ballotCount);
            $return = $this->electionReturn($configuration, $tallyResult, $precinctId);
            $returnScans[] = $this->returnScan($return, $returnIndex);
            $returnHashes[] = $return['return_hash'] ?? null;
            $tallyHashes[] = $tallyResult['tally_hash'];
        }

        $state = [
            'schema_version' => 'canvassing-demo-run-1',
            'ballots_per_return' => $ballotCount,
            'return_count' => $returnCount,
            'total_ballots' => $ballotCount * $returnCount,
            'ballot_count' => $ballotCount,
            'generated_at' => now()->toIso8601String(),
            'election_id' => $configuration['election_id'] ?? null,
            'precinct_id' => $configuration['precinct_id'] ?? null,
            'ballot_style_id' => $configuration['ballot_style_id'] ?? null,
            'mapping_hash' => $configuration['mapping_hash'] ?? null,
            'candidate_count' => $this->candidateCount($configuration),
            'contest_count' => count((array) ($configuration['contests'] ?? [])),
            'tally_hashes' => $tallyHashes,
            'return_hashes' => $returnHashes,
            'tally_hash' => $tallyHashes[0] ?? null,
            'return_hash' => $returnHashes[0] ?? null,
            'truth_tally_payload_hash' => $returnScans[0]['payload_hash'] ?? null,
            'return_qr_count' => collect($returnScans)->sum(fn (array $scan): int => count((array) ($scan['payloads'] ?? []))),
            'sample_ballots' => $sampleBallots,
            'return_scans' => $returnScans,
            'return_scan' => $returnScans[0] ?? null,
        ];

        $this->storage->writeJson(self::StatePath, $state);
        $this->journal->record('canvassing_demo.generated', [
            'ballots_per_return' => $ballotCount,
            'return_count' => $returnCount,
            'total_ballots' => $ballotCount * $returnCount,
        ]);

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function configuration(): array
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');

        if (
            ($configuration['precinct_id'] ?? null) !== self::TondoClusteredPrecinct
            || $this->candidateCount($configuration) === 0
        ) {
            $this->storage->startRun('canvassing-demo', self::TondoClusteredPrecinct, now()->format('Ymd-His'), creationSource: 'canvassing-demo');

            return $this->activateTondoPackage();
        }

        return $configuration;
    }

    /**
     * @return array<string, mixed>
     */
    private function activateTondoPackage(): array
    {
        $this->popImporter->import(
            (string) config('election.pop.source_path'),
            (string) config('election.pop.profile'),
        );
        $this->clcImporter->import();

        return $this->activatePrecinctBallot
            ->handle(self::TondoClusteredPrecinct, self::TondoDistrict)['configuration'];
    }

    /**
     * @return array<string, mixed>
     */
    private function ballotDefinitionSummary(): array
    {
        return $this->storage->readJson('precinct-candidates/active-ballot-definition.json');
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function configurationSummary(array $configuration): array
    {
        return [
            'election_id' => $configuration['election_id'] ?? null,
            'precinct_id' => $configuration['precinct_id'] ?? null,
            'ballot_style_id' => $configuration['ballot_style_id'] ?? null,
            'mapping_hash' => $configuration['mapping_hash'] ?? null,
            'tabulation_profile' => $configuration['tabulation_profile'] ?? null,
            'city_municipality' => $configuration['city_municipality'] ?? 'City/Municipality canvass',
            'province' => $configuration['province'] ?? null,
            'candidate_count' => $this->candidateCount($configuration),
            'contest_count' => count((array) ($configuration['contests'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function candidateCount(array $configuration): int
    {
        return collect((array) ($configuration['contests'] ?? []))
            ->sum(fn (array $contest): int => count((array) ($contest['candidates'] ?? [])));
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function ballotPayload(array $configuration, int $index, string $precinctId): array
    {
        return [
            'schema_version' => 'ballot-payload-1',
            'ballot_id' => sprintf('canvassing-demo-ballot-%06d', $index),
            'election_id' => $configuration['election_id'] ?? null,
            'precinct_id' => $precinctId,
            'ballot_style_id' => $configuration['ballot_style_id'] ?? null,
            'mapping_hash' => $configuration['mapping_hash'] ?? null,
            'tabulation_profile' => $configuration['tabulation_profile'] ?? null,
            'payload_hash_profile' => 'compact-selection-1',
            'document_profile' => $this->documents->ballotReference(),
            'paper_ballot_serial' => sprintf('CANVASS-DEMO-%06d', $index),
            'selections' => $this->selections($configuration, $index),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, array<string, int>>  $tally
     * @return array<string, mixed>
     */
    private function tallyResult(array $configuration, array $tally, int $ballotCount): array
    {
        $result = [
            'schema_version' => 'tally-1',
            'accepted_ballots' => $ballotCount,
            'rejected_ballots' => 0,
            'tabulation_profile' => $configuration['tabulation_profile'] ?? null,
            'tally_source' => 'canvassing demo ballot QR payloads',
            'tally' => $tally,
            'display_tally' => $this->presentation->displayTally($tally),
            'paper_ballot_accounting' => [
                'source' => 'simulated-ballot-payloads',
                'deposited' => $ballotCount,
            ],
            'device_tabulation' => [
                'source' => 'not-used-for-canvassing-demo',
            ],
        ];
        $result['display_summary'] = $this->presentation->summary($result['display_tally']);
        $result['tally_hash'] = $this->json->hash($result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $tally
     * @return array<string, mixed>
     */
    private function electionReturn(array $configuration, array $tally, string $precinctId): array
    {
        $return = [
            'schema_version' => 'election-return-1',
            'election_id' => $configuration['election_id'] ?? null,
            'precinct_id' => $precinctId,
            'mapping_hash' => $configuration['mapping_hash'] ?? null,
            'tabulation_profile' => $tally['tabulation_profile'] ?? null,
            'tally_source' => $tally['tally_source'] ?? null,
            'accepted_ballots' => $tally['accepted_ballots'],
            'rejected_ballots' => $tally['rejected_ballots'],
            'tally' => $tally['tally'],
            'tally_hash' => $tally['tally_hash'],
            'document_profile' => $this->documents->electionReturnReference(),
        ];
        $return['return_hash'] = $this->json->hash($return);
        $return['truth_tally'] = $this->truthTallyArtifacts($return, $precinctId);

        $this->storage->writeJson("returns/canvassing-demo/{$precinctId}-return.json", $return);

        return $return;
    }

    /**
     * @param  array<string, mixed>  $return
     * @return array<string, mixed>
     */
    private function truthTallyArtifacts(array $return, string $precinctId): array
    {
        $canonicalPayload = $this->returnQrPayload->encode($return, ElectionReturnScope::Combined);
        $qrPayloads = $this->returnEnvelope->wrap($canonicalPayload);
        $payloadHash = $this->returnQrPayload->compactHash($return, ElectionReturnScope::Combined);
        $artifacts = [];

        foreach ($qrPayloads as $index => $payload) {
            $sequence = $index + 1;
            $relativePath = count($qrPayloads) === 1
                ? "returns/canvassing-demo/{$precinctId}-truth-tally-qr.png"
                : "returns/canvassing-demo/{$precinctId}-truth-tally-qr-{$sequence}-of-".count($qrPayloads).'.png';
            $artifacts[] = [
                'sequence' => $sequence,
                'total' => count($qrPayloads),
                'payload' => $payload,
                'path' => $relativePath,
                'artifact_path' => $this->storage->path($relativePath),
                'sha256' => hash('sha256', $payload),
            ];
            $this->storage->writeText($relativePath, $this->qrCode->renderPng($payload));
        }

        return [
            'schema_version' => 'truth-tally-election-return-1',
            'payload_version' => ElectionReturnQrPayload::PayloadVersion,
            'payload_type' => ElectionReturnPayloadEnvelope::PayloadType,
            'payload_hash' => $payloadHash,
            'canonical_payload' => $canonicalPayload,
            'qr_count' => count($qrPayloads),
            'qr_payloads' => $qrPayloads,
            'qr_artifacts' => $artifacts,
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, array<int, string>>
     */
    private function selections(array $configuration, int $ballotNumber): array
    {
        return collect($configuration['contests'] ?? [])
            ->filter(fn (mixed $contest): bool => is_array($contest))
            ->mapWithKeys(function (array $contest) use ($ballotNumber): array {
                $contestId = (string) ($contest['id'] ?? '');
                $candidateIds = collect($contest['candidates'] ?? [])
                    ->filter(fn (mixed $candidate): bool => is_array($candidate) && isset($candidate['id']))
                    ->pluck('id')
                    ->map(fn (mixed $candidateId): string => (string) $candidateId)
                    ->values();
                $maximumSelections = max(0, min((int) ($contest['max_selections'] ?? 1), $candidateIds->count()));

                if ($contestId === '' || $maximumSelections === 0 || $candidateIds->isEmpty()) {
                    return [$contestId => []];
                }

                $offset = ($ballotNumber - 1) % $candidateIds->count();
                $selected = [];

                foreach (range(0, $maximumSelections - 1) as $selectionOffset) {
                    $selected[] = $candidateIds[($offset + $selectionOffset) % $candidateIds->count()];
                }

                return [$contestId => $selected];
            })
            ->all();
    }

    /**
     * @param  array<string, array<string, int>>  $tally
     * @param  array<string, array<int, string>>  $selections
     */
    private function addSelections(array &$tally, array $selections): void
    {
        foreach ($selections as $contestId => $candidateIds) {
            foreach ($candidateIds as $candidateId) {
                $tally[(string) $contestId][(string) $candidateId] = ($tally[(string) $contestId][(string) $candidateId] ?? 0) + 1;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $return
     * @return array<string, mixed>
     */
    private function returnScan(array $return, int $sequence): array
    {
        $payloads = (array) ($return['truth_tally']['qr_payloads'] ?? []);
        $canonicalPayload = $this->returnEnvelope->reassemble(array_values($payloads));
        $decoded = $this->returnQrPayload->decode($canonicalPayload);

        return [
            'sequence' => $sequence,
            'source' => 'official election return QR',
            'precinct_id' => (string) ($decoded['precinct_id'] ?? ''),
            'return_scope' => (string) ($decoded['return_scope'] ?? 'combined'),
            'payloads' => $payloads,
            'canonical_payload' => $canonicalPayload,
            'payload_hash' => (string) ($decoded['payload_hash'] ?? ''),
            'return_hash' => (string) ($decoded['return_hash'] ?? ''),
            'document_profile' => $decoded['document_profile'] ?? null,
            'accepted_ballots' => (int) ($decoded['accepted_ballots'] ?? 0),
            'rejected_ballots' => (int) ($decoded['rejected_ballots'] ?? 0),
            'tally' => $decoded['tally'] ?? [],
            'display_tally' => $this->presentation->displayTally((array) ($decoded['tally'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<int, array{id: string, title: string, max_selections: int, candidates: array<int, array{id: string, name: string}>}>
     */
    private function contests(array $configuration): array
    {
        return collect($configuration['contests'] ?? [])
            ->filter(fn (mixed $contest): bool => is_array($contest))
            ->map(fn (array $contest): array => [
                'id' => (string) ($contest['id'] ?? ''),
                'title' => (string) ($contest['title'] ?? $contest['id'] ?? ''),
                'max_selections' => (int) ($contest['max_selections'] ?? 1),
                'candidates' => collect($contest['candidates'] ?? [])
                    ->filter(fn (mixed $candidate): bool => is_array($candidate))
                    ->map(fn (array $candidate): array => [
                        'id' => (string) ($candidate['id'] ?? ''),
                        'name' => trim(implode(' ', array_filter([
                            $candidate['ballot_number'] ?? null,
                            $candidate['name'] ?? $candidate['id'] ?? '',
                        ]))),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, array<string, int>>
     */
    private function emptyTally(array $configuration): array
    {
        return collect($configuration['contests'] ?? [])
            ->filter(fn (mixed $contest): bool => is_array($contest))
            ->mapWithKeys(fn (array $contest): array => [
                (string) ($contest['id'] ?? '') => collect($contest['candidates'] ?? [])
                    ->filter(fn (mixed $candidate): bool => is_array($candidate))
                    ->mapWithKeys(fn (array $candidate): array => [(string) ($candidate['id'] ?? '') => 0])
                    ->all(),
            ])
            ->all();
    }
}
