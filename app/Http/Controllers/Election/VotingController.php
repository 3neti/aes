<?php

namespace App\Http\Controllers\Election;

use App\Election\Attestation\OfficerRegistry;
use App\Election\Certification\InitializationReportService;
use App\Election\Core\ElectionSnapshot;
use App\Election\Counting\CountingLegalEvidenceService;
use App\Election\Lifecycle\CeremonyActions;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\BallotPayloadService;
use App\Election\Voting\SpecialPollingIntakeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\OpenPollsRequest;
use App\Http\Requests\StoreSpecialPollingIntakeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class VotingController extends Controller
{
    public function show(
        ElectionSnapshot $snapshot,
        ElectionStorage $storage,
        SpecialPollingIntakeService $specialPollingIntake,
    ): Response {
        $readyBallot = collect($storage->files('voter-ballots'))
            ->filter(fn (string $path): bool => str_ends_with($path, '.json'))
            ->map(fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
            ->filter(fn (array $payload): bool => ! file_exists($storage->path("print-jobs/{$payload['ballot_id']}.json")))
            ->last();

        return Inertia::render('Election/Voting', [
            'snapshot' => $snapshot->get(),
            'specialPollingIntake' => $specialPollingIntake->summary(),
            'readyBallot' => $readyBallot ?: [],
        ]);
    }

    public function openPolls(
        OpenPollsRequest $request,
        OfficerRegistry $officers,
        LifecycleState $lifecycle,
        InitializationReportService $initializationReport,
        CeremonyActions $ceremonies,
    ): RedirectResponse {
        $officerCode = $request->validated('officer_code');
        $officerPin = $request->validated('officer_pin');
        $officer = $officers->verify($officerCode, $officerPin);

        if ($officer === null) {
            throw ValidationException::withMessages([
                'officer_pin' => 'The officer code or PIN is invalid.',
            ]);
        }

        try {
            if ($lifecycle->current() === Lifecycle::OpenPrecinct) {
                $initializationReport->write('opening/initialization-report.json');
            }

            $ceremonies->openPolls($officer['name']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'lifecycle' => 'Cannot open polls from the current lifecycle stage: '.$lifecycle->current().'.',
            ]);
        }

        return redirect()->route('election.voting');
    }

    public function finalize(
        Request $request,
        BallotPayloadService $payloads,
        LifecycleState $lifecycle,
    ): RedirectResponse {
        if ($lifecycle->current() !== Lifecycle::Voting) {
            throw ValidationException::withMessages([
                'lifecycle' => 'Votes can only be finalized while voting is active.',
            ]);
        }

        $validated = $request->validate([
            'selections' => ['nullable', 'array'],
            'selections.*' => ['array'],
            'selections.*.*' => ['string'],
        ]);

        $selections = collect($validated['selections'] ?? [])
            ->map(fn (array $candidateIds): array => array_values($candidateIds))
            ->all();

        $payload = $payloads->finalize($selections);

        return redirect()->route('election.printing', ['ballot' => $payload['ballot_id']]);
    }

    public function closePolls(
        CeremonyActions $ceremonies,
        CountingLegalEvidenceService $legalEvidence,
    ): RedirectResponse {
        try {
            $ceremonies->closePolls();
            $legalEvidence->writeForClosePolls();
            $ceremonies->startCounting();
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'lifecycle' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('election.counting');
    }

    public function recordSpecialPollingIntake(
        StoreSpecialPollingIntakeRequest $request,
        SpecialPollingIntakeService $intake,
    ): RedirectResponse {
        $intake->record($request->validated());

        return redirect()->route('election.voting');
    }
}
