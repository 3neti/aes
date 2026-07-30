<?php

namespace App\Http\Controllers\Election;

use App\Election\Audit\RandomManualAuditService;
use App\Election\Core\ElectionSnapshot;
use App\Election\Counting\CountingLegalEvidenceService;
use App\Election\Counting\CountingReconciliationService;
use App\Election\Counting\CountingService;
use App\Election\Lifecycle\CeremonyActions;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Scanning\BallotScanner;
use App\Election\Tabulation\TabulationProfileResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class CountingController extends Controller
{
    public function show(
        Request $request,
        ElectionSnapshot $snapshot,
        CountingService $counting,
        CountingLegalEvidenceService $legalEvidence,
        CountingReconciliationService $reconciliation,
        RandomManualAuditService $randomManualAudit,
    ): Response {
        return Inertia::render('Election/Counting', [
            'snapshot' => $snapshot->get(),
            'tally' => $counting->tally(),
            'closePollsLegalEvidence' => $legalEvidence->closePollsSummary(),
            'countingLegalEvidence' => $legalEvidence->countingSummary(),
            'scanFeedback' => $request->session()->get('scan_feedback'),
            'rmaFeedback' => $request->session()->get('rma_feedback'),
            'reconciliation' => $reconciliation->summary(),
            'randomManualAudit' => $randomManualAudit->summary(),
        ]);
    }

    public function recordPhysicalCount(Request $request, CountingReconciliationService $reconciliation): RedirectResponse
    {
        $validated = $request->validate([
            'physical_count' => ['required', 'integer', 'min:0'],
            'officer_code' => ['required', 'string'],
            'officer_pin' => ['required', 'digits:6'],
        ]);
        $reconciliation->recordPhysicalCount(
            (int) $validated['physical_count'],
            $validated['officer_code'],
            $validated['officer_pin'],
        );

        return redirect()->route('election.counting');
    }

    public function adjudicate(Request $request, CountingReconciliationService $reconciliation): RedirectResponse
    {
        $validated = $request->validate([
            'sequence' => ['required', 'integer', 'min:1'],
            'disposition' => ['required', 'in:excluded-paper-ballot,duplicate-scan,not-a-paper-ballot,spoiled-ballot-separated'],
            'reason' => ['required', 'string', 'max:500'],
            'officer_code' => ['required', 'string'],
            'officer_pin' => ['required', 'digits:6'],
        ]);
        $reconciliation->adjudicate(
            (int) $validated['sequence'],
            $validated['disposition'],
            $validated['reason'],
            $validated['officer_code'],
            $validated['officer_pin'],
        );

        return redirect()->route('election.counting');
    }

    public function scan(
        Request $request,
        CountingService $counting,
        BallotScanner $scanner,
        TabulationProfileResolver $tabulation,
    ): RedirectResponse {
        $validated = $request->validate(['payload' => ['required', 'string']]);
        $rawInput = $validated['payload'];

        if (! $tabulation->current()->routineScanningEnabled()) {
            return redirect()
                ->route('election.counting')
                ->with('scan_feedback', $counting->recordRoutineScanBlocked($rawInput));
        }

        try {
            $scan = $scanner->scan($rawInput);
        } catch (Throwable $exception) {
            $record = $counting->rejectRawInput($rawInput, $exception->getMessage(), 'scanner-decode');

            return redirect()
                ->route('election.counting')
                ->with('scan_feedback', [
                    'status' => 'rejected',
                    'adapter' => $record['adapter'],
                    'sequence' => $record['sequence'],
                    'ballot_id' => null,
                    'payload_hash' => null,
                    'raw_payload_hash' => $record['raw_payload_hash'],
                    'reason' => $record['reason'],
                ]);
        }

        $record = $counting->accept($scan['payload']);

        return redirect()
            ->route('election.counting')
            ->with('scan_feedback', $this->scanFeedback($scan, $record));
    }

    public function proposeRandomManualAudit(
        Request $request,
        BallotScanner $scanner,
        RandomManualAuditService $randomManualAudit,
    ): RedirectResponse {
        $validated = $request->validate(['payload' => ['required', 'string']]);

        try {
            $scan = $scanner->scan($validated['payload']);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'payload' => $exception->getMessage(),
            ]);
        }

        $proposal = $randomManualAudit->propose($scan);

        return redirect()
            ->route('election.counting')
            ->with('rma_feedback', [
                'status' => 'proposed',
                'ballot_id' => $proposal['ballot_id'],
                'payload_hash' => $proposal['payload_hash'],
            ]);
    }

    public function approveRandomManualAudit(
        Request $request,
        RandomManualAuditService $randomManualAudit,
    ): RedirectResponse {
        $validated = $request->validate([
            'payload_hash' => ['required', 'string', 'size:64'],
            'paper_matches_payload' => ['accepted'],
            'first_officer_code' => ['required', 'string'],
            'first_officer_pin' => ['required', 'digits:6'],
            'second_officer_code' => ['required', 'string'],
            'second_officer_pin' => ['required', 'digits:6'],
        ]);

        $record = $randomManualAudit->approve(
            $validated['payload_hash'],
            $validated['first_officer_code'],
            $validated['first_officer_pin'],
            $validated['second_officer_code'],
            $validated['second_officer_pin'],
        );

        return redirect()
            ->route('election.counting')
            ->with('rma_feedback', [
                'status' => 'approved',
                'ballot_id' => $record['ballot_id'],
                'payload_hash' => $record['payload_hash'],
            ]);
    }

    public function complete(
        CeremonyActions $ceremonies,
        CountingService $counting,
        CountingLegalEvidenceService $legalEvidence,
        LifecycleState $lifecycle,
        CountingReconciliationService $reconciliation,
    ): RedirectResponse {
        if ($lifecycle->current() !== Lifecycle::Counting) {
            throw ValidationException::withMessages([
                'lifecycle' => 'Cannot complete counting unless the current stage is counting.',
            ]);
        }

        if (! $reconciliation->summary()['passed']) {
            throw ValidationException::withMessages([
                'reconciliation' => 'Physical paper ballots and the configured tabulation record do not reconcile.',
            ]);
        }

        $tally = $counting->tally();
        $legalEvidence->writeForCompletion($tally);
        $ceremonies->moveToReturns();

        return redirect()->route('election.returns');
    }

    /**
     * @param  array{payload: string, adapter: string, raw_payload_hash: string}  $scan
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function scanFeedback(array $scan, array $record): array
    {
        return [
            'status' => $record['status'],
            'adapter' => $scan['adapter'],
            'sequence' => $record['sequence'] ?? null,
            'ballot_id' => $record['ballot_id'] ?? null,
            'payload_hash' => $record['payload_hash'] ?? null,
            'raw_payload_hash' => $record['raw_payload_hash'] ?? $scan['raw_payload_hash'],
            'reason' => $record['reason'] ?? null,
        ];
    }
}
