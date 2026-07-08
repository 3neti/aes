<?php

namespace App\Election\Custody;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Support\SimplePdf;
use App\Election\Transmission\TransmissionService;
use RuntimeException;

final class CustodyService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly TransmissionService $transmission,
        private readonly SimplePdf $pdf,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function record(): array
    {
        $transmission = $this->transmission->latestReport();

        if ($transmission === []) {
            throw new RuntimeException('Cannot record custody before transmission exists.');
        }

        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $precinct = (string) ($configuration['precinct_id'] ?? '0421-A');

        $record = [
            'schema_version' => 'custody-record-1',
            'custody_id' => 'custody-'.$this->clock->now()->format('YmdHis').'-'.substr($transmission['transmission_hash'], 0, 8),
            'precinct_id' => $precinct,
            'election_id' => $configuration['election_id'] ?? ($transmission['election_id'] ?? null),
            'mapping_hash' => $configuration['mapping_hash'] ?? ($transmission['mapping_hash'] ?? null),
            'recorded_at' => $this->clock->now()->toIso8601String(),
            'status' => 'sealed',
            'artifacts' => [
                [
                    'type' => 'election_return',
                    'path' => $this->returnsArtifactPath(),
                    'required' => true,
                ],
                [
                    'type' => 'transmission_report',
                    'path' => $this->transmissionArtifactPath(),
                    'required' => true,
                ],
            ],
            'seals' => [
                [
                    'seal_number' => $this->nextSealNumber($precinct),
                    'applied_by' => 'Simulation Officer',
                    'applied_at' => $this->clock->now()->toIso8601String(),
                ],
            ],
            'recipients' => [
                [
                    'type' => 'election-board',
                    'name' => 'Election Board',
                ],
            ],
            'transmission_id' => $transmission['transmission_id'] ?? null,
            'transmission_status' => $transmission['passed'] ? 'transmission-recorded' : 'transmission-deficient',
        ];

        $record['custody_hash'] = $this->json->hash($record);

        $record['artifact_path'] = $this->storage->writeJson('custody/custody-record.json', $record);
        $this->storage->writeText('custody/custody-record.txt', $this->renderText($record));
        $this->storage->writeText('custody/custody-record.pdf', $this->pdf->render('Custody Record', $this->renderPdfLines($record)));

        $this->journal->record('custody.recorded', [
            'custody_id' => $record['custody_id'],
            'precinct_id' => $precinct,
            'custody_hash' => $record['custody_hash'],
            'status' => $record['status'],
        ]);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public function latestRecord(): array
    {
        return $this->storage->readJson('custody/custody-record.json');
    }

    private function nextSealNumber(string $precinct): string
    {
        return sprintf('SEAL-%s-%s', $precinct, $this->clock->now()->format('His'));
    }

    private function returnsArtifactPath(): string
    {
        $precinct = (string) ($this->storage->readJson('runtime/active-precinct.json')['precinct_id'] ?? '0421-A');

        return 'returns/'.$precinct.'-return.json';
    }

    private function transmissionArtifactPath(): string
    {
        return 'transmission/transmission-report.json';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function renderText(array $record): string
    {
        $text = "CUSTODY RECORD\n";
        $text .= "Custody: {$record['custody_id']}\n";
        $text .= "Precinct: {$record['precinct_id']}\n";
        $text .= "Status: {$record['status']}\n";
        $text .= "Custody Hash: {$record['custody_hash']}\n\n";

        foreach ($record['seals'] as $seal) {
            $text .= sprintf(
                "Seal: %s\nApplied by: %s\nApplied at: %s\n\n",
                $seal['seal_number'],
                $seal['applied_by'],
                $seal['applied_at'],
            );
        }

        foreach ($record['artifacts'] as $artifact) {
            $text .= "Included artifact: {$artifact['type']} ({$artifact['path']})\n";
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, string>
     */
    private function renderPdfLines(array $record): array
    {
        $lines = [
            'Custody ID: '.$record['custody_id'],
            'Precinct: '.$record['precinct_id'],
            'Status: '.$record['status'],
            'Transmission ID: '.($record['transmission_id'] ?? 'none'),
            'Custody Hash: '.$record['custody_hash'],
            '',
            'Seals:',
        ];

        foreach ($record['seals'] as $seal) {
            $lines[] = '- '.$seal['seal_number'].' (' . $seal['applied_by'] . ')';
        }

        return $lines;
    }
}
