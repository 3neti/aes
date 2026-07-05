<?php

namespace App\Http\Controllers\Election;

use App\Election\Certification\CertificationService;
use App\Election\Core\ElectionSnapshot;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class CertificationController extends Controller
{
    public function show(ElectionSnapshot $snapshot): Response
    {
        return Inertia::render('Election/Certification', ['snapshot' => $snapshot->get()]);
    }

    public function run(CertificationService $certification, LifecycleState $lifecycle): RedirectResponse
    {
        $certification->run();
        $lifecycle->set(Lifecycle::OpenPrecinct);

        return redirect()->route('election.voting');
    }
}
