<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Tabulation\DeviceTabulationLedger;
use RuntimeException;

final class PublicVvdatAuditExport
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly DeviceTabulationLedger $ledger,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly ElectionClock $clock,
        private readonly PublicSimulationPublication $publication,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $publication = $this->releasePublication();
        $existing = $this->storage->readJson('returns/vvdat-audit-export.json');

        if ($existing !== []) {
            return $existing;
        }

        $records = collect($this->ledger->recordsForTally())
            ->map(fn (array $record): array => [
                'record_hash' => $record['record_hash'],
                'selections' => $record['selections'],
            ])
            ->sortBy(fn (array $record): string => hash('sha256', $publication['manifest_hash'].'|'.$record['record_hash']))
            ->values()
            ->all();
        $export = [
            'schema_version' => 'public-simulation-vvdat-audit-export-1',
            'generated_at' => $this->clock->now()->toIso8601String(),
            'publication_manifest_hash' => $publication['manifest_hash'],
            'ledger_root' => $publication['vvdat_ledger_root'],
            'record_count' => count($records),
            'records' => $records,
            'published_tally' => $this->storage->readJson('runtime/tally.json')['tally'] ?? [],
            'privacy_transform' => [
                'removed_fields' => ['ballot_id', 'paper_ballot_serial', 'recorded_at', 'authorization_id', 'release_id'],
                'ordering' => 'deterministic-record-hash-order',
            ],
        ];
        $export['export_hash'] = $this->json->hash($export);
        $export['artifact_path'] = $this->storage->path('returns/vvdat-audit-export.json');
        $this->storage->writeJson('returns/vvdat-audit-export.json', $export);

        $this->journal->record('public_simulation.vvdat_audit_export_generated', [
            'record_count' => $export['record_count'],
            'export_hash' => $export['export_hash'],
            'publication_manifest_hash' => $publication['manifest_hash'],
        ]);

        return $export;
    }

    public function isAvailable(): bool
    {
        if (! config('election.public_simulation.vvdat_audit_export.enabled', true)) {
            return false;
        }

        if ($this->publication->summary() === []) {
            return false;
        }

        return $this->ledger->summary()['recorded_ballots'] >= $this->minimumRecordCount();
    }

    /**
     * @return array<string, mixed>
     */
    private function releasePublication(): array
    {
        if (! config('election.public_simulation.vvdat_audit_export.enabled', true)) {
            throw new RuntimeException('The public VVDAT audit export is disabled by the release policy.');
        }

        $publication = $this->publication->summary();

        if ($publication === []) {
            throw new RuntimeException('Publish the post-close results before generating a public VVDAT audit export.');
        }

        if ($this->ledger->summary()['recorded_ballots'] < $this->minimumRecordCount()) {
            throw new RuntimeException("The public VVDAT audit export requires at least {$this->minimumRecordCount()} deposited ballot records.");
        }

        return $publication;
    }

    private function minimumRecordCount(): int
    {
        return max(1, (int) config('election.public_simulation.vvdat_audit_export.minimum_records', 1));
    }
}
