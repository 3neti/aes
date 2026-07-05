<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Diagnostics\DiagnosticsService;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class DiagnosticsController extends Controller
{
    public function __invoke(ElectionSnapshot $snapshot, DiagnosticsService $diagnostics): Response
    {
        return Inertia::render('Election/Diagnostics', [
            'snapshot' => $snapshot->get(),
            'diagnostics' => $diagnostics->get(),
        ]);
    }
}
