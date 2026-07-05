<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Devices\DeviceCertificationService;
use App\Election\Diagnostics\DiagnosticsService;
use App\Election\Support\ElectionStorage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
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
