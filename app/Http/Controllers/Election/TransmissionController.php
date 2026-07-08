<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Custody\CustodyService;
use App\Election\Transmission\TransmissionService;
use App\Election\Support\ElectionStorage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class TransmissionController extends Controller
{
    public function show(ElectionSnapshot $snapshot, ElectionStorage $storage): Response
    {
        return Inertia::render('Election/Transmission', [
            'snapshot' => $snapshot->get(),
            'transmission' => $storage->readJson('transmission/transmission-report.json'),
            'custody' => $storage->readJson('custody/custody-record.json'),
        ]);
    }

    public function send(TransmissionService $transmission): RedirectResponse
    {
        $transmission->run();

        return redirect()->route('election.transmission');
    }

    public function recordCustody(CustodyService $custody): RedirectResponse
    {
        $custody->record();

        return redirect()->route('election.transmission');
    }
}
