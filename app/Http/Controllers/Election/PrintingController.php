<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Printing\BallotPrinter;
use App\Election\Printing\PrinterCertificationRequired;
use App\Election\Printing\PrintFormProfile;
use App\Election\Printing\PrintFormProfileResolver;
use App\Election\Printing\SpoilBallot;
use App\Election\Support\ElectionStorage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PrintingController extends Controller
{
    public function show(ElectionSnapshot $snapshot, ElectionStorage $storage, PrintFormProfileResolver $profiles, ?string $ballot = null): Response
    {
        $payload = $ballot ? $storage->readJson("ballots/{$ballot}.json") : [];

        return Inertia::render('Election/Printing', [
            'snapshot' => $snapshot->get(),
            'payload' => $payload,
            'qrImageDataUri' => $ballot
                ? 'data:image/png;base64,'.base64_encode($storage->readText("ballots/{$ballot}-qr.png"))
                : '',
            'printProfiles' => $profiles->options(),
            'defaultPrintProfile' => $profiles->default()->value,
            'printJob' => $ballot ? $storage->readJson("print-jobs/{$ballot}.json") : [],
        ]);
    }

    public function print(Request $request, string $ballot, ElectionStorage $storage, BallotPrinter $printer, PrintFormProfileResolver $profiles): RedirectResponse
    {
        $validated = $request->validate([
            'profile' => ['nullable', 'string', Rule::enum(PrintFormProfile::class)],
        ]);

        try {
            $printer->print(
                $storage->readJson("ballots/{$ballot}.json"),
                $profiles->from($validated['profile'] ?? $profiles->default()->value),
            );
        } catch (PrinterCertificationRequired $exception) {
            return redirect()
                ->route('election.printing', ['ballot' => $ballot])
                ->withErrors(['printer' => $exception->getMessage()]);
        }

        return redirect()->route('election.printing', ['ballot' => $ballot]);
    }

    public function downloadForm(string $ballot, string $profile, ElectionStorage $storage, PrintFormProfileResolver $profiles): BinaryFileResponse
    {
        $resolved = $profiles->from($profile);
        $path = $storage->path("print-forms/ballots/{$ballot}/{$resolved->value}.pdf");

        abort_unless(file_exists($path), 404);

        return response()->file($path, ['Content-Type' => 'application/pdf']);
    }

    public function spoil(string $ballot, ElectionStorage $storage, SpoilBallot $spoil): RedirectResponse
    {
        $payload = $storage->readJson("ballots/{$ballot}.json");
        $spoil->handle($payload['payload_hash']);

        return redirect()->route('election.voting');
    }
}
