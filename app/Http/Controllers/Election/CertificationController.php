<?php

namespace App\Http\Controllers\Election;

use App\Election\Certification\CertificationService;
use App\Election\Certification\DiscrepancyReportService;
use App\Election\Certification\ManualVerificationService;
use App\Election\Certification\SealingService;
use App\Election\Certification\ZeroOutService;
use App\Election\Core\ElectionSnapshot;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionStorage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class CertificationController extends Controller
{
    public function show(ElectionSnapshot $snapshot, ElectionStorage $storage): Response
    {
        return Inertia::render('Election/Certification', [
            'snapshot' => $snapshot->get(),
            'certificationReport' => $storage->readJson('certification/friday-certification-report.json'),
            'manualVerificationReport' => $storage->readJson('certification/manual-verification-report.json'),
            'discrepancyReport' => $storage->readJson('certification/fts-discrepancy-report.json'),
            'zeroOutReport' => $storage->readJson('certification/zero-out-report.json'),
            'sealingReport' => $storage->readJson('certification/sealing-report.json'),
            'manualReturnTemplate' => $this->manualReturnTemplate($storage),
        ]);
    }

    public function run(CertificationService $certification, LifecycleState $lifecycle): RedirectResponse
    {
        $certification->run();
        $lifecycle->set(Lifecycle::OpenPrecinct);

        return redirect()->route('election.voting');
    }

    public function runManualVerification(Request $request, ManualVerificationService $verification): RedirectResponse
    {
        $request->validate([
            'manual_return' => ['required', 'string'],
        ]);

        try {
            $manualReturn = json_decode($request->string('manual_return')->toString(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return back()->withErrors(['manual_return' => 'Manual return payload is not valid JSON.'])->withInput();
        }

        $report = $verification->run((array) $manualReturn);

        return redirect()
            ->route('election.certification')
            ->with('manual_verification_hash', $report['report_hash'] ?? null);
    }

    public function downloadManualVerification(ElectionStorage $storage): BinaryFileResponse
    {
        $path = $storage->path('certification/manual-verification-report.json');

        abort_unless(file_exists($path), 404);

        return response()->download($path, 'manual-verification-report.json');
    }

    public function runDiscrepancy(DiscrepancyReportService $discrepancy): RedirectResponse
    {
        $report = $discrepancy->run();

        return redirect()->route('election.certification')->with('discrepancy_report_hash', $report['report_hash'] ?? null);
    }

    public function downloadDiscrepancy(ElectionStorage $storage): BinaryFileResponse
    {
        $path = $storage->path('certification/fts-discrepancy-report.json');

        abort_unless(file_exists($path), 404);

        return response()->download($path, 'fts-discrepancy-report.json');
    }

    public function runZeroOut(ZeroOutService $zeroOut): RedirectResponse
    {
        $report = $zeroOut->run();

        return redirect()
            ->route('election.certification')
            ->with('zero_out_report_hash', $report['report_hash'] ?? null);
    }

    public function downloadZeroOut(ElectionStorage $storage): BinaryFileResponse
    {
        $path = $storage->path('certification/zero-out-report.json');

        abort_unless(file_exists($path), 404);

        return response()->download($path, 'zero-out-report.json');
    }

    public function runSealing(SealingService $sealing): RedirectResponse
    {
        $report = $sealing->run();

        return redirect()
            ->route('election.certification')
            ->with('sealing_report_hash', $report['report_hash'] ?? null);
    }

    public function downloadSealing(ElectionStorage $storage): BinaryFileResponse
    {
        $path = $storage->path('certification/sealing-report.json');

        abort_unless(file_exists($path), 404);

        return response()->download($path, 'sealing-report.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function manualReturnTemplate(ElectionStorage $storage): array
    {
        $report = $storage->readJson('certification/friday-certification-report.json');

        if ($report === []) {
            return [
                'schema_version' => 'manual-return-1',
                'precinct_id' => null,
                'accepted_ballots' => 0,
                'rejected_ballots' => 0,
                'tally' => [],
            ];
        }

        return [
            'schema_version' => 'manual-return-1',
            'precinct_id' => $report['precinct_id'] ?? null,
            'accepted_ballots' => $report['actual_ballots'] ?? ($report['accepted_ballots'] ?? 0),
            'rejected_ballots' => $report['rejected_ballots'] ?? 0,
            'tally' => $report['actual_tally'] ?? [],
        ];
    }
}
