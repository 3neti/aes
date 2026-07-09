<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Counting\CountingLegalEvidenceService;
use App\Election\Counting\CountingService;
use App\Election\Lifecycle\CeremonyActions;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Scanning\BallotScanner;
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
    ): Response {
        return Inertia::render('Election/Counting', [
            'snapshot' => $snapshot->get(),
            'tally' => $counting->tally(),
            'closePollsLegalEvidence' => $legalEvidence->closePollsSummary(),
            'countingLegalEvidence' => $legalEvidence->countingSummary(),
            'scanFeedback' => $request->session()->get('scan_feedback'),
        ]);
    }

    public function scan(Request $request, CountingService $counting, BallotScanner $scanner): RedirectResponse
    {
        $validated = $request->validate(['payload' => ['required', 'string']]);
        $rawInput = $validated['payload'];

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

    public function complete(
        CeremonyActions $ceremonies,
        CountingService $counting,
        CountingLegalEvidenceService $legalEvidence,
        LifecycleState $lifecycle,
    ): RedirectResponse {
        if ($lifecycle->current() !== Lifecycle::Counting) {
            throw ValidationException::withMessages([
                'lifecycle' => 'Cannot complete counting unless the current stage is counting.',
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
