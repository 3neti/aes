<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Custody\CustodyService;
use App\Election\Lifecycle\CeremonyActions;
use App\Election\Support\ElectionStorage;
use App\Election\Transmission\DeliveryPackageService;
use App\Election\Transmission\DeliveryReceiptService;
use App\Election\Transmission\FinalBackupService;
use App\Election\Transmission\ManualHandoffService;
use App\Election\Transmission\TransmissionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeliveryReceiptRequest;
use App\Http\Requests\StoreFinalBackupRequest;
use App\Http\Requests\StoreManualHandoffOfficerVerificationRequest;
use App\Http\Requests\StoreManualHandoffRecipientVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class TransmissionController extends Controller
{
    public function show(
        ElectionSnapshot $snapshot,
        ElectionStorage $storage,
        DeliveryPackageService $package,
        ManualHandoffService $handoff,
        DeliveryReceiptService $receipt,
        FinalBackupService $finalBackup,
    ): Response {
        return Inertia::render('Election/Transmission', [
            'snapshot' => $snapshot->get(),
            'transmission' => $storage->readJson('transmission/transmission-report.json'),
            'deliveryPackage' => $package->summary(),
            'custody' => $storage->readJson('custody/custody-record.json'),
            'manualOfficerVerification' => $handoff->officerVerificationSummary(),
            'manualRecipientVerification' => $handoff->recipientVerificationSummary(),
            'deliveryReceipt' => $receipt->summary(),
            'finalBackup' => $finalBackup->summary(),
        ]);
    }

    public function preparePackage(DeliveryPackageService $package): RedirectResponse
    {
        $package->prepare();

        return redirect()->route('election.transmission');
    }

    public function verifyOfficer(StoreManualHandoffOfficerVerificationRequest $request, ManualHandoffService $handoff): RedirectResponse
    {
        $handoff->verifyOfficer($request->validated());

        return redirect()->route('election.transmission');
    }

    public function verifyRecipient(StoreManualHandoffRecipientVerificationRequest $request, ManualHandoffService $handoff): RedirectResponse
    {
        $handoff->verifyRecipient($request->validated());

        return redirect()->route('election.transmission');
    }

    public function send(TransmissionService $transmission): RedirectResponse
    {
        $transmission->run();

        return redirect()->route('election.transmission');
    }

    public function recordReceipt(StoreDeliveryReceiptRequest $request, DeliveryReceiptService $receipt): RedirectResponse
    {
        $receipt->prepare($request->validated());

        return redirect()->route('election.transmission');
    }

    public function recordFinalBackup(StoreFinalBackupRequest $request, FinalBackupService $finalBackup): RedirectResponse
    {
        $finalBackup->perform($request->validated());

        return redirect()->route('election.transmission');
    }

    public function recordCustody(CustodyService $custody, CeremonyActions $ceremonies): RedirectResponse
    {
        $custody->record();
        $ceremonies->recordCustody();

        return redirect()->route('election.transmission');
    }
}
