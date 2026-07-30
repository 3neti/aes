<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\SealedBallotBox;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class WatcherController extends Controller
{
    public function __invoke(
        ElectionSnapshot $snapshot,
        LifecycleState $lifecycle,
        ElectionStorage $storage,
        SealedBallotBox $ballotBox,
    ): Response {
        $resultsAvailable = in_array($lifecycle->current(), [
            Lifecycle::ElectionReturn,
            Lifecycle::Transmission,
            Lifecycle::FinalBackup,
            Lifecycle::Custody,
            Lifecycle::ClosePrecinct,
            Lifecycle::Audit,
        ], true);
        $auditAvailable = $this->auditAvailable($lifecycle);
        $sample = $auditAvailable ? $storage->readJson('rma/sample-selection.json') : [];
        $reconciliation = $auditAvailable ? $storage->readJson('rma/reconciliation-report.json') : [];
        $evidencePack = $auditAvailable ? $storage->readJson('rma/evidence-pack.json') : [];

        return Inertia::render('Election/Watcher', [
            'snapshot' => $snapshot->get(),
            'operations' => $ballotBox->operationalSummary(),
            'resultsAvailable' => $resultsAvailable,
            'tally' => $resultsAvailable ? $storage->readJson('runtime/tally.json') : [],
            'electionReturn' => $resultsAvailable ? $storage->readJson('returns/election-return.json') : [],
            'randomManualAudit' => [
                'available' => $auditAvailable,
                'sample_selected' => $sample !== [],
                'sample_size' => $sample['sample_size'] ?? null,
                'source_record_count' => $sample['source_record_count'] ?? null,
                'reconciliation' => $reconciliation === [] ? [] : [
                    'complete' => (bool) ($reconciliation['complete'] ?? false),
                    'passed' => (bool) ($reconciliation['passed'] ?? false),
                    'verified_ballots' => (int) ($reconciliation['verified_ballots'] ?? 0),
                    'discrepancy_ballots' => (int) ($reconciliation['discrepancy_ballots'] ?? 0),
                    'pending_ballots' => (int) ($reconciliation['pending_ballots'] ?? 0),
                    'device_record_issues' => (int) ($reconciliation['device_record_issues'] ?? 0),
                    'report_hash' => $reconciliation['report_hash'] ?? null,
                ],
                'evidence_pack_available' => $evidencePack !== [],
                'evidence_pack_hash' => $evidencePack['evidence_pack_hash'] ?? null,
            ],
        ]);
    }

    public function downloadRandomManualAuditEvidencePack(
        LifecycleState $lifecycle,
        ElectionStorage $storage,
    ): BinaryFileResponse {
        $this->ensureAuditEvidenceAvailable($lifecycle, $storage, 'rma/evidence-pack.json');

        return response()->download(
            $storage->path('rma/evidence-pack.json'),
            'random-manual-audit-evidence-pack.json',
        );
    }

    public function downloadRandomManualAuditEvidencePackPdf(
        LifecycleState $lifecycle,
        ElectionStorage $storage,
    ): BinaryFileResponse {
        $this->ensureAuditEvidenceAvailable($lifecycle, $storage, 'rma/evidence-pack.pdf');

        return response()->download(
            $storage->path('rma/evidence-pack.pdf'),
            'random-manual-audit-evidence-pack.pdf',
        );
    }

    private function ensureAuditEvidenceAvailable(
        LifecycleState $lifecycle,
        ElectionStorage $storage,
        string $relativePath,
    ): void {
        abort_unless($this->auditAvailable($lifecycle) && file_exists($storage->path($relativePath)), 404);
    }

    private function auditAvailable(LifecycleState $lifecycle): bool
    {
        return in_array($lifecycle->current(), [
            Lifecycle::Counting,
            Lifecycle::ElectionReturn,
            Lifecycle::Transmission,
            Lifecycle::FinalBackup,
            Lifecycle::Custody,
            Lifecycle::ClosePrecinct,
            Lifecycle::Audit,
        ], true);
    }
}
