<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Devices\DeviceCertificationService;
use App\Election\Diagnostics\DiagnosticsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

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
}
