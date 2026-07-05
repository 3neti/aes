<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Preparation\ActivateSamplePackage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class ProvisionController extends Controller
{
    public function show(ElectionSnapshot $snapshot): Response
    {
        return Inertia::render('Election/Provision', ['snapshot' => $snapshot->get()]);
    }

    public function activate(ActivateSamplePackage $activate): RedirectResponse
    {
        $activate->handle();

        return redirect()->route('election.certification');
    }
}
