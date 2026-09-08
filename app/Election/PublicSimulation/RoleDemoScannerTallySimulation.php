<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\CanonicalJson;
use App\Election\Counting\TallyPresentation;
use App\Election\Documents\DocumentProfileRegistry;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\BallotPayloadEnvelope;
use App\Election\Voting\BallotQrPayload;
use Illuminate\Support\Facades\Crypt;

final class RoleDemoScannerTallySimulation
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly BallotQrPayload $qrPayload,
        private readonly BallotPayloadEnvelope $envelope,
        private readonly TallyPresentation $presentation,
        private readonly CanonicalJson $json,
        private readonly DocumentProfileRegistry $documents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $ballots = $this->sealedBallots();

        if ($ballots === []) {
            $ballots = $this->sampleBallots($configuration);
        }

        return [
            'schema_version' => 'role-demo-scanner-tally-simulation-1',
            'source' => $this->storage->files('counting/sealed') === [] ? 'generated-demo-ballot-payloads' : 'sealed-role-demo-ballots',
            'precinct' => [
                'election_id' => $configuration['election_id'] ?? null,
                'precinct_id' => $configuration['precinct_id'] ?? null,
                'ballot_style_id' => $configuration['ballot_style_id'] ?? null,
                'mapping_hash' => $configuration['mapping_hash'] ?? null,
            ],
            'ballot' => [
                'contests' => $this->contests($configuration),
            ],
            'scanner' => [
                'ballots' => $ballots,
                'initial_tally' => $this->emptyTally($configuration),
            ],
            'document_rendering' => $this->documents->renderingKit(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sealedBallots(): array
    {
        return collect($this->storage->files('counting/sealed'))
            ->map(fn (string $path): array => json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
            ->sortBy([
                ['deposited_at', 'asc'],
                ['paper_ballot_serial', 'asc'],
                ['payload_hash', 'asc'],
            ])
            ->values()
            ->map(function (array $record, int $index): array {
                $rawPayload = Crypt::decryptString((string) ($record['encrypted_payload'] ?? ''));
                $payload = $this->qrPayload->decode($this->envelope->unwrap($rawPayload));

                return $this->scannerBallot($payload, $rawPayload, $index + 1, 'sealed ballot box');
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<int, array<string, mixed>>
     */
    private function sampleBallots(array $configuration): array
    {
        return collect(range(1, 8))
            ->map(function (int $sequence) use ($configuration): array {
                $payload = [
                    'schema_version' => 'ballot-payload-1',
                    'ballot_id' => sprintf('scanner-demo-ballot-%03d', $sequence),
                    'election_id' => $configuration['election_id'] ?? null,
                    'precinct_id' => $configuration['precinct_id'] ?? null,
                    'ballot_style_id' => $configuration['ballot_style_id'] ?? null,
                    'mapping_hash' => $configuration['mapping_hash'] ?? null,
                    'tabulation_profile' => $configuration['tabulation_profile'] ?? null,
                    'payload_hash_profile' => 'compact-selection-1',
                    'document_profile' => $this->documents->ballotReference(),
                    'paper_ballot_serial' => sprintf('SIM-%03d', $sequence),
                    'selections' => $this->sampleSelections($configuration, $sequence),
                ];
                $payload['payload_hash'] = $this->qrPayload->compactHash($payload);
                $payload['canonical_qr_payload'] = $this->qrPayload->encode($payload);
                $payload['qr_payload'] = $this->envelope->wrap($payload['canonical_qr_payload']);

                return $this->scannerBallot($payload, $payload['qr_payload'], $sequence, 'generated ballot payload');
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, array<int, string>>
     */
    private function sampleSelections(array $configuration, int $sequence): array
    {
        return collect($configuration['contests'] ?? [])
            ->filter(fn (mixed $contest): bool => is_array($contest))
            ->mapWithKeys(function (array $contest) use ($sequence): array {
                $candidates = collect($contest['candidates'] ?? [])->values();
                $maximum = max(0, (int) ($contest['max_selections'] ?? 1));
                $selectionCount = min($maximum, $maximum > 1 ? 2 : 1, $candidates->count());

                if ($selectionCount === 0) {
                    return [(string) ($contest['id'] ?? '') => []];
                }

                $offset = ($sequence - 1) % max(1, $candidates->count());
                $selected = collect(range(0, $selectionCount - 1))
                    ->map(fn (int $step): string => (string) ($candidates[($offset + $step) % $candidates->count()]['id'] ?? ''))
                    ->filter()
                    ->values()
                    ->all();

                return [(string) ($contest['id'] ?? '') => $selected];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function scannerBallot(array $payload, string $rawPayload, int $sequence, string $source): array
    {
        $selections = (array) ($payload['selections'] ?? []);

        return [
            'sequence' => $sequence,
            'source' => $source,
            'ballot_id' => (string) ($payload['ballot_id'] ?? ''),
            'precinct_id' => (string) ($payload['precinct_id'] ?? ''),
            'paper_ballot_serial' => $payload['paper_ballot_serial'] ?? null,
            'payload' => $rawPayload,
            'canonical_payload' => $this->envelope->unwrap($rawPayload),
            'payload_hash' => (string) ($payload['payload_hash'] ?? $this->json->hash($payload)),
            'document_profile' => $payload['document_profile'] ?? null,
            'selections' => $selections,
            'this_ballot_tally' => $this->presentation->displayTally($this->tallyFromSelections($selections)),
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

    /**
     * @param  array<string, array<int, string>>  $selections
     * @return array<string, array<string, int>>
     */
    private function tallyFromSelections(array $selections): array
    {
        $tally = [];

        foreach ($selections as $contestId => $candidateIds) {
            foreach ($candidateIds as $candidateId) {
                $tally[(string) $contestId][(string) $candidateId] = ($tally[(string) $contestId][(string) $candidateId] ?? 0) + 1;
            }
        }

        return $tally;
    }
}
