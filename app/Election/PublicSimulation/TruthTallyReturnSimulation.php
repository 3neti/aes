<?php

namespace App\Election\PublicSimulation;

use App\Election\Counting\TallyPresentation;
use App\Election\Returns\ElectionReturnPayloadEnvelope;
use App\Election\Returns\ElectionReturnQrPayload;
use App\Election\Support\ElectionStorage;

final class TruthTallyReturnSimulation
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionReturnPayloadEnvelope $envelope,
        private readonly ElectionReturnQrPayload $qrPayload,
        private readonly TallyPresentation $presentation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $return = $this->currentReturn($configuration);
        $scan = $this->scanFor($return);

        return [
            'schema_version' => 'truth-tally-return-simulation-1',
            'source' => 'election-return-truth-qr',
            'canvass' => [
                'jurisdiction' => $configuration['city_municipality'] ?? 'City/Municipality canvass',
                'election_id' => $configuration['election_id'] ?? null,
                'mapping_hash' => $configuration['mapping_hash'] ?? null,
            ],
            'return' => [
                'contests' => $this->contests($configuration),
            ],
            'scanner' => [
                'returns' => $scan === [] ? [] : [$scan],
                'initial_tally' => $this->emptyTally($configuration),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function currentReturn(array $configuration): array
    {
        $precinctId = (string) ($configuration['precinct_id'] ?? '');

        if ($precinctId === '') {
            return [];
        }

        return $this->storage->readJson("returns/{$precinctId}-return.json");
    }

    /**
     * @param  array<string, mixed>  $return
     * @return array<string, mixed>
     */
    private function scanFor(array $return): array
    {
        $payloads = (array) ($return['truth_tally']['qr_payloads'] ?? []);

        if ($payloads === []) {
            return [];
        }

        $decoded = $this->qrPayload->decode($this->envelope->reassemble(array_values($payloads)));

        return [
            'sequence' => 1,
            'source' => 'official election return QR',
            'precinct_id' => (string) ($decoded['precinct_id'] ?? ''),
            'return_scope' => (string) ($decoded['return_scope'] ?? 'combined'),
            'payloads' => $payloads,
            'canonical_payload' => $this->envelope->reassemble(array_values($payloads)),
            'payload_hash' => (string) ($decoded['payload_hash'] ?? ''),
            'return_hash' => (string) ($decoded['return_hash'] ?? ''),
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
