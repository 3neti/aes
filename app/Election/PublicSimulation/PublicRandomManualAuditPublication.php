<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Support\SimplePdf;
use RuntimeException;

final class PublicRandomManualAuditPublication
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly ElectionClock $clock,
        private readonly SimplePdf $pdf,
        private readonly PublicSimulationPublication $results,
    ) {}

    /**
     * Publish a deliberately redacted audit summary after post-close results are public.
     *
     * @return array<string, mixed>
     */
    public function publish(): array
    {
        $existing = $this->storage->readJson('returns/public-rma-audit-summary.json');

        if ($existing !== []) {
            return $existing;
        }

        $results = $this->results->summary();
        $reconciliation = $this->storage->readJson('rma/reconciliation-report.json');
        $evidencePack = $this->storage->readJson('rma/evidence-pack.json');

        if ($results === []) {
            throw new RuntimeException('Publish the post-close watcher results before publishing the Random Manual Audit summary.');
        }

        if ($reconciliation === [] || ! ($reconciliation['complete'] ?? false) || $evidencePack === []) {
            throw new RuntimeException('Complete reconciliation and build the officer audit evidence pack before publishing its watcher-safe summary.');
        }

        $summary = [
            'schema_version' => 'public-simulation-rma-summary-1',
            'published_at' => $this->clock->now()->toIso8601String(),
            'publication_manifest_hash' => $results['manifest_hash'],
            'sample_hash' => $reconciliation['sample_hash'],
            'sample_size' => $reconciliation['sample_size'],
            'source_record_count' => $reconciliation['source_record_count'],
            'verified_ballots' => $reconciliation['verified_ballots'],
            'discrepancy_ballots' => $reconciliation['discrepancy_ballots'],
            'pending_ballots' => $reconciliation['pending_ballots'],
            'device_record_issues' => $reconciliation['device_record_issues'],
            'complete' => $reconciliation['complete'],
            'passed' => $reconciliation['passed'],
            'outcome' => $reconciliation['passed'] ? 'verified' : 'attention-required',
            'officer_evidence_pack_hash' => $evidencePack['evidence_pack_hash'],
            'privacy_notice' => 'This public summary excludes ballot serials, QR payloads, selections, officer identities, and individual audit entries.',
        ];
        $summary['summary_hash'] = $this->json->hash($summary);
        $summary['artifact_path'] = $this->storage->path('returns/public-rma-audit-summary.json');

        $this->storage->writeJson('returns/public-rma-audit-summary.json', $summary);
        $this->storage->writeText('returns/public-rma-audit-summary.pdf', $this->pdf->render(
            'Public Random Manual Audit Summary',
            $this->pdfLines($summary),
        ));

        $this->journal->record('public_simulation.rma_summary_published', [
            'summary_hash' => $summary['summary_hash'],
            'publication_manifest_hash' => $summary['publication_manifest_hash'],
            'sample_size' => $summary['sample_size'],
            'verified_ballots' => $summary['verified_ballots'],
            'discrepancy_ballots' => $summary['discrepancy_ballots'],
            'passed' => $summary['passed'],
        ]);

        return $summary;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return $this->storage->readJson('returns/public-rma-audit-summary.json');
    }

    /** @return array<string, mixed> */
    public function watcherSummary(): array
    {
        $summary = $this->summary();

        if ($summary === []) {
            return [];
        }

        return [
            'sample_hash' => $summary['sample_hash'],
            'sample_size' => $summary['sample_size'],
            'source_record_count' => $summary['source_record_count'],
            'verified_ballots' => $summary['verified_ballots'],
            'discrepancy_ballots' => $summary['discrepancy_ballots'],
            'pending_ballots' => $summary['pending_ballots'],
            'device_record_issues' => $summary['device_record_issues'],
            'complete' => $summary['complete'],
            'passed' => $summary['passed'],
            'outcome' => $summary['outcome'],
            'summary_hash' => $summary['summary_hash'],
            'privacy_notice' => $summary['privacy_notice'],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<int, string>
     */
    private function pdfLines(array $summary): array
    {
        return [
            'PUBLIC RANDOM MANUAL AUDIT SUMMARY',
            '',
            'Summary Hash: '.$summary['summary_hash'],
            'Published Result Manifest: '.$summary['publication_manifest_hash'],
            'Sample Hash: '.$summary['sample_hash'],
            'Sample Size: '.$summary['sample_size'].' of '.$summary['source_record_count'].' sealed VVDAT records',
            'Verified Comparisons: '.$summary['verified_ballots'],
            'Recorded Paper Discrepancies: '.$summary['discrepancy_ballots'],
            'Pending Comparisons: '.$summary['pending_ballots'],
            'Device Record Issues: '.$summary['device_record_issues'],
            'Audit Complete: '.($summary['complete'] ? 'YES' : 'NO'),
            'Audit Outcome: '.($summary['passed'] ? 'VERIFIED' : 'ATTENTION REQUIRED'),
            '',
            'This public simulation audit summary does not disclose ballot serials, QR payloads, voter selections, officer identities, or individual audit entries.',
        ];
    }
}
