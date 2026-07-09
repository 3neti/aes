<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Devices\DeviceCertificationService;
use App\Election\Diagnostics\DiagnosticsService;
use App\Election\Diagnostics\EvidenceBundleArchiveBuilder;
use App\Election\Diagnostics\EvidenceBundleArchiveVerifier;
use App\Election\Diagnostics\EvidenceReferenceBaselineService;
use App\Election\Diagnostics\RemovableMediaExporter;
use App\Election\Diagnostics\RemovableMediaExportVerifier;
use App\Election\Diagnostics\RemovableMediaReadinessChecker;
use App\Election\Minutes\OfficialMinutesBaselineService;
use App\Election\Support\ElectionStorage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class DiagnosticsController extends Controller
{
    public function show(ElectionSnapshot $snapshot, DiagnosticsService $diagnostics): Response
    {
        return Inertia::render('Election/Diagnostics', [
            'snapshot' => $snapshot->get(),
            'diagnostics' => $diagnostics->get(),
        ]);
    }

    public function certifyDevices(DeviceCertificationService $certification): RedirectResponse
    {
        $certification->run();

        return redirect()->route('election.diagnostics');
    }

    public function generateEvidenceManifest(DiagnosticsService $diagnostics): RedirectResponse
    {
        $manifest = $diagnostics->writeEvidenceManifest();

        return redirect()
            ->route('election.diagnostics')
            ->with('evidence_manifest_hash', $manifest['manifest_hash'] ?? null);
    }

    public function downloadEvidenceManifest(DiagnosticsService $diagnostics): BinaryFileResponse
    {
        $manifest = $diagnostics->writeEvidenceManifest();

        return response()->download(
            $manifest['artifact_path'],
            'evidence-manifest.json',
            ['Content-Type' => 'application/json'],
        );
    }

    public function generateEvidenceReferenceBaseline(EvidenceReferenceBaselineService $baseline): RedirectResponse
    {
        $report = $baseline->write();

        return redirect()
            ->route('election.diagnostics')
            ->with('evidence_reference_baseline_hash', $report['baseline_hash'] ?? null);
    }

    public function downloadEvidenceReferenceBaseline(ElectionStorage $storage): BinaryFileResponse
    {
        $path = $storage->path('diagnostics/evidence-reference-baseline.json');

        abort_unless(file_exists($path), 404);

        return response()->download(
            $path,
            'evidence-reference-baseline.json',
            ['Content-Type' => 'application/json'],
        );
    }

    public function generateOfficialMinutesBaseline(OfficialMinutesBaselineService $minutes): RedirectResponse
    {
        $report = $minutes->write();

        return redirect()
            ->route('election.diagnostics')
            ->with('official_minutes_baseline_hash', $report['official_minute_hash'] ?? null);
    }

    public function downloadOfficialMinutesBaseline(ElectionStorage $storage): BinaryFileResponse
    {
        $path = $storage->path('diagnostics/official-minutes-baseline.json');

        abort_unless(file_exists($path), 404);

        return response()->download(
            $path,
            'official-minutes-baseline.json',
            ['Content-Type' => 'application/json'],
        );
    }

    public function exportRemovableMedia(RemovableMediaExporter $exporter): RedirectResponse
    {
        $report = $exporter->export();

        return redirect()
            ->route('election.diagnostics')
            ->with('removable_media_export_hash', $report['export_hash'] ?? null);
    }

    public function verifyRemovableMedia(RemovableMediaExportVerifier $verifier): RedirectResponse
    {
        $report = $verifier->writeReport();

        return redirect()
            ->route('election.diagnostics')
            ->with('evidence_export_verification_hash', $report['verification_hash'] ?? null);
    }

    public function checkRemovableMediaReadiness(RemovableMediaReadinessChecker $checker): RedirectResponse
    {
        $report = $checker->check();

        return redirect()
            ->route('election.diagnostics')
            ->with('removable_media_readiness_hash', $report['readiness_hash'] ?? null);
    }

    public function buildEvidenceBundleArchive(EvidenceBundleArchiveBuilder $builder): RedirectResponse
    {
        $report = $builder->build();

        return redirect()
            ->route('election.diagnostics')
            ->with('evidence_bundle_archive_hash', $report['archive_report_hash'] ?? null);
    }

    public function downloadEvidenceBundleArchive(ElectionStorage $storage): BinaryFileResponse
    {
        $report = $storage->readJson('diagnostics/evidence-bundle-archive.json');
        $artifact = (string) ($report['archive_artifact'] ?? 'evidence-bundle.tar');
        abort_if($artifact !== basename($artifact), 404);

        $path = $storage->path('diagnostics/'.$artifact);

        abort_unless(file_exists($path), 404);

        return response()->download(
            $path,
            $artifact,
            ['Content-Type' => 'application/x-tar'],
        );
    }

    public function verifyEvidenceBundleArchive(EvidenceBundleArchiveVerifier $verifier): RedirectResponse
    {
        $report = $verifier->writeReport();

        return redirect()
            ->route('election.diagnostics')
            ->with('evidence_bundle_archive_verification_hash', $report['verification_hash'] ?? null);
    }

    public function verifyUploadedEvidenceBundleArchive(Request $request, EvidenceBundleArchiveVerifier $verifier): RedirectResponse
    {
        $validated = $request->validate([
            'archive' => ['nullable', 'required_without:archive_payload', File::types(['tar'])->max('50mb')],
            'archive_name' => ['nullable', 'string', 'max:255'],
            'archive_payload' => ['nullable', 'required_without:archive', 'string'],
        ]);

        if (isset($validated['archive'])) {
            $report = $verifier->verifyUploadedArchive($validated['archive']);
        } else {
            $contents = base64_decode((string) $validated['archive_payload'], true);

            if ($contents === false) {
                throw ValidationException::withMessages([
                    'archive_payload' => 'The archive payload must be valid base64.',
                ]);
            }

            if (strlen($contents) > 50 * 1024 * 1024) {
                throw ValidationException::withMessages([
                    'archive_payload' => 'The archive payload must not be greater than 50 megabytes.',
                ]);
            }

            $report = $verifier->verifyUploadedArchiveContents(
                $contents,
                (string) ($validated['archive_name'] ?? 'returned-evidence-bundle.tar'),
            );
        }

        return redirect()
            ->route('election.diagnostics')
            ->with('evidence_bundle_archive_verification_hash', $report['verification_hash'] ?? null);
    }

    public function attestation(ElectionStorage $storage, string $artifact): BinaryFileResponse
    {
        return response()->file($this->artifactPath($storage, 'attestations', $artifact), [
            'Content-Type' => 'application/json',
        ]);
    }

    public function signature(ElectionStorage $storage, string $artifact): BinaryFileResponse
    {
        return response()->file($this->artifactPath($storage, 'attestation-signatures', $artifact), [
            'Content-Type' => 'image/png',
        ]);
    }

    public function downloadAttestation(ElectionStorage $storage, string $artifact): BinaryFileResponse
    {
        return response()->download(
            $this->artifactPath($storage, 'attestations', $artifact),
            $artifact,
            ['Content-Type' => 'application/json'],
        );
    }

    public function downloadSignature(ElectionStorage $storage, string $artifact): BinaryFileResponse
    {
        return response()->download(
            $this->artifactPath($storage, 'attestation-signatures', $artifact),
            $artifact,
            ['Content-Type' => 'image/png'],
        );
    }

    private function artifactPath(ElectionStorage $storage, string $directory, string $artifact): string
    {
        abort_if($artifact !== basename($artifact), 404);

        $path = $storage->path($directory.'/'.$artifact);

        abort_unless(file_exists($path), 404);

        return $path;
    }
}
