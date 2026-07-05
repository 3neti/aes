<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Printing\BallotPrinter;
use App\Election\Printing\SpoilBallot;
use App\Election\Support\ElectionStorage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class PrintingController extends Controller
{
    public function show(ElectionSnapshot $snapshot, ElectionStorage $storage, ?string $ballot = null): Response
    {
        $payload = $ballot ? $storage->readJson("ballots/{$ballot}.json") : [];

        return Inertia::render('Election/Printing', [
            'snapshot' => $snapshot->get(),
            'payload' => $payload,
            'qrImageDataUri' => $ballot
                ? 'data:image/png;base64,'.base64_encode($storage->readText("ballots/{$ballot}-qr.png"))
                : '',
        ]);
    }

    public function print(string $ballot, ElectionStorage $storage, BallotPrinter $printer): RedirectResponse
    {
        $printer->print($storage->readJson("ballots/{$ballot}.json"));

        return redirect()->route('election.printing', ['ballot' => $ballot]);
    }

    public function spoil(string $ballot, ElectionStorage $storage, SpoilBallot $spoil): RedirectResponse
    {
        $payload = $storage->readJson("ballots/{$ballot}.json");
        $spoil->handle($payload['payload_hash']);

        return redirect()->route('election.voting');
    }
}
