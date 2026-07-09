<?php

namespace App\Http\Controllers\Election;

use App\Election\Certification\CertificationService;
use App\Election\Certification\ManualVerificationService;
use App\Election\Core\ElectionSnapshot;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionStorage;
use App\Http\Controllers\Controller;
use Illuminate\Http\BinaryFileResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;

final class CertificationController extends Controller
{
    public function show(ElectionSnapshot $snapshot, ElectionStorage $storage): Response
    {
        return Inertia::render('Election/Certification', [
            'snapshot' => $snapshot->get(),
            'certificationReport' => $storage->readJson('certification/friday-certification-report.json'),
            'manualVerificationReport' => $storage->readJson('certification/manual-verification-report.json'),
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
