<?php

use App\Http\Controllers\Election\AttestationController;
use App\Http\Controllers\Election\CertificationController;
use App\Http\Controllers\Election\CountingController;
use App\Http\Controllers\Election\DiagnosticsController;
use App\Http\Controllers\Election\HomeController;
use App\Http\Controllers\Election\PrintingController;
use App\Http\Controllers\Election\PrintStationController;
use App\Http\Controllers\Election\ProvisionController;
use App\Http\Controllers\Election\PublicSimulationController;
use App\Http\Controllers\Election\PublicSimulationGodModeController;
use App\Http\Controllers\Election\PublicSimulationRandomManualAuditController;
use App\Http\Controllers\Election\PublicSimulationVoterController;
use App\Http\Controllers\Election\PublicSimulationWatcherController;
use App\Http\Controllers\Election\ReturnsController;
use App\Http\Controllers\Election\ReviewRoomController;
use App\Http\Controllers\Election\TransmissionController;
use App\Http\Controllers\Election\VoterAuthorizationController;
use App\Http\Controllers\Election\VoterBallotController;
use App\Http\Controllers\Election\VotingController;
use App\Http\Controllers\Election\WatcherController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)
    ->middleware('review-room-role:officer')
    ->name('home');

Route::prefix('election')->name('election.')->group(function (): void {
    Route::prefix('play')->name('public-simulation.')->group(function (): void {
        Route::get('/', [PublicSimulationController::class, 'index'])->name('index');
        Route::get('/{round:code}/god-mode', PublicSimulationGodModeController::class)->name('god-mode');
        Route::get('/{round:code}/{precinct:code}', [PublicSimulationController::class, 'show'])->name('show');
        Route::post('/{round:code}/{precinct:code}/open', [PublicSimulationController::class, 'open'])->name('open');
        Route::post('/{round:code}/{precinct:code}/admit', [PublicSimulationController::class, 'admit'])->middleware('throttle:20,1')->name('admit');
        Route::post('/{round:code}/{precinct:code}/admit-queued', [PublicSimulationController::class, 'admitQueued'])->middleware('throttle:20,1')->name('admit-queued');
        Route::post('/{round:code}/{precinct:code}/admission-intake', [PublicSimulationController::class, 'updateAdmissionIntake'])->middleware('throttle:10,1')->name('admission-intake');
        Route::post('/{round:code}/{precinct:code}/contention-report', [PublicSimulationController::class, 'generateContentionReport'])->middleware('throttle:10,1')->name('contention-report');
        Route::post('/{round:code}/{precinct:code}/close', [PublicSimulationController::class, 'close'])->name('close');
        Route::post('/{round:code}/{precinct:code}/publish', [PublicSimulationController::class, 'publish'])->name('publish');

        Route::get('/{round:code}/{precinct:code}/audit', [PublicSimulationRandomManualAuditController::class, 'show'])->name('audit.show');
        Route::post('/{round:code}/{precinct:code}/audit/select', [PublicSimulationRandomManualAuditController::class, 'select'])->name('audit.select');
        Route::post('/{round:code}/{precinct:code}/audit/propose', [PublicSimulationRandomManualAuditController::class, 'propose'])->name('audit.propose');
        Route::post('/{round:code}/{precinct:code}/audit/approve', [PublicSimulationRandomManualAuditController::class, 'approve'])->name('audit.approve');
        Route::post('/{round:code}/{precinct:code}/audit/discrepancy', [PublicSimulationRandomManualAuditController::class, 'discrepancy'])->name('audit.discrepancy');
        Route::post('/{round:code}/{precinct:code}/audit/reconcile', [PublicSimulationRandomManualAuditController::class, 'reconcile'])->name('audit.reconcile');
        Route::post('/{round:code}/{precinct:code}/audit/evidence-pack', [PublicSimulationRandomManualAuditController::class, 'evidencePack'])->name('audit.evidence-pack');
        Route::post('/{round:code}/{precinct:code}/audit/publish', [PublicSimulationRandomManualAuditController::class, 'publish'])->name('audit.publish');
        Route::get('/{round:code}/{precinct:code}/audit/evidence-pack', [PublicSimulationRandomManualAuditController::class, 'download'])->name('audit.download');

        Route::get('/{round:code}/{precinct:code}/vote', [PublicSimulationVoterController::class, 'show'])->name('voter.show');
        Route::post('/{round:code}/{precinct:code}/vote/participation', [PublicSimulationVoterController::class, 'acceptParticipation'])->middleware('throttle:10,1')->name('voter.participation.accept');
        Route::post('/{round:code}/{precinct:code}/vote/queue', [PublicSimulationVoterController::class, 'joinQueue'])->middleware('throttle:5,1')->name('voter.join-queue');
        Route::post('/{round:code}/{precinct:code}/vote/claim', [PublicSimulationVoterController::class, 'claim'])->middleware('throttle:10,1')->name('voter.claim');
        Route::get('/{round:code}/{precinct:code}/vote/ballot', [PublicSimulationVoterController::class, 'ballot'])->name('voter.ballot');
        Route::post('/{round:code}/{precinct:code}/vote/ballot', [PublicSimulationVoterController::class, 'finalize'])->name('voter.finalize');
        Route::get('/{round:code}/{precinct:code}/vote/complete', [PublicSimulationVoterController::class, 'complete'])->name('voter.complete');
        Route::get('/{round:code}/{precinct:code}/print', [PublicSimulationVoterController::class, 'printStation'])->name('print.station');
        Route::post('/{round:code}/{precinct:code}/print/redeem', [PublicSimulationVoterController::class, 'redeem'])->name('print.redeem');
        Route::post('/{round:code}/{precinct:code}/print/print', [PublicSimulationVoterController::class, 'print'])->name('print.print');
        Route::post('/{round:code}/{precinct:code}/print/deposit', [PublicSimulationVoterController::class, 'deposit'])->name('print.deposit');

        Route::get('/{round:code}/{precinct:code}/watch', [PublicSimulationWatcherController::class, 'show'])->name('watcher.show');
        Route::get('/{round:code}/{precinct:code}/watch/tally-sheet', [PublicSimulationWatcherController::class, 'tally'])->name('watcher.tally');
        Route::get('/{round:code}/{precinct:code}/watch/election-return', [PublicSimulationWatcherController::class, 'electionReturn'])->name('watcher.return');
        Route::get('/{round:code}/{precinct:code}/watch/vvdat-audit-export', [PublicSimulationWatcherController::class, 'vvdatAuditExport'])->name('watcher.vvdat-audit-export');
        Route::get('/{round:code}/{precinct:code}/watch/random-manual-audit', [PublicSimulationWatcherController::class, 'randomManualAudit'])->name('watcher.rma-audit');
    });

    Route::get('/review-room', [ReviewRoomController::class, 'index'])->name('review-room.index');
    Route::post('/review-room', [ReviewRoomController::class, 'store'])->name('review-room.store');
    Route::post('/review-room/start-fresh', [ReviewRoomController::class, 'startFresh'])
        ->name('review-room.start-fresh');
    Route::post('/review-room/{room:code}/close', [ReviewRoomController::class, 'close'])->name('review-room.close');
    Route::get('/review-room/{room:code}/stations/{station}/qr', [ReviewRoomController::class, 'stationQr'])
        ->middleware('throttle:60,1')
        ->name('review-room.station-qr');
    Route::post('/review-room/{room:code}/stations/{station}/release', [ReviewRoomController::class, 'releaseStation'])
        ->middleware('throttle:30,1')
        ->name('review-room.station-release');
    Route::get('/review-room/{room:code}/join/{station}', [ReviewRoomController::class, 'join'])
        ->middleware(['signed', 'throttle:30,1'])
        ->name('review-room.join');
    Route::get('/review-room-presentation', [ReviewRoomController::class, 'presentation'])
        ->middleware('review-room-role:presentation')
        ->name('review-room.presentation');

    Route::middleware('review-room-role:officer')->group(function (): void {
        Route::get('/', HomeController::class)->name('home');
        Route::get('/provision', [ProvisionController::class, 'show'])->name('provision');
        Route::post('/provision/activate', [ProvisionController::class, 'activate'])->name('provision.activate');
        Route::post('/provision/setup', [ProvisionController::class, 'storeSetup'])->name('provision.setup');
        Route::post('/provision/eb-role-baseline', [ProvisionController::class, 'writeElectoralBoardBaseline'])->name('provision.eb-role-baseline');
        Route::post('/provision/supply-verification-baseline', [ProvisionController::class, 'writeSupplyVerificationBaseline'])->name('provision.supply-verification-baseline');
        Route::post('/provision/legal-scenario-suite', [ProvisionController::class, 'runLegalScenarioSuite'])->name('provision.legal-scenario-suite');

        Route::get('/certification', [CertificationController::class, 'show'])->name('certification');
        Route::post('/certification/run', [CertificationController::class, 'run'])->name('certification.run');
        Route::post('/certification/manual-verification', [CertificationController::class, 'runManualVerification'])->name('certification.manual-verification');
        Route::get('/certification/manual-verification/download', [CertificationController::class, 'downloadManualVerification'])->name('certification.manual-verification.download');
        Route::post('/certification/discrepancy', [CertificationController::class, 'runDiscrepancy'])->name('certification.discrepancy');
        Route::get('/certification/discrepancy-report/download', [CertificationController::class, 'downloadDiscrepancy'])->name('certification.discrepancy.download');
        Route::post('/certification/zero-out', [CertificationController::class, 'runZeroOut'])->name('certification.zero-out');
        Route::get('/certification/zero-out-report/download', [CertificationController::class, 'downloadZeroOut'])->name('certification.zero-out.download');
        Route::post('/certification/seal', [CertificationController::class, 'runSealing'])->name('certification.seal');
        Route::get('/certification/sealing-report/download', [CertificationController::class, 'downloadSealing'])->name('certification.sealing-report.download');

        Route::get('/voting', [VotingController::class, 'show'])->name('voting');
        Route::post('/voting/voter-authorizations', [VoterAuthorizationController::class, 'issue'])
            ->middleware('throttle:30,1')
            ->name('voting.voter-authorizations.issue');
        Route::post('/voting/open-polls', [VotingController::class, 'openPolls'])->name('voting.open-polls');
        Route::post('/voting/finalize', [VotingController::class, 'finalize'])->name('voting.finalize');
        Route::post('/voting/close-polls', [VotingController::class, 'closePolls'])->name('voting.close-polls');
        Route::post('/voting/special-polling-intake', [VotingController::class, 'recordSpecialPollingIntake'])->name('voting.special-polling-intake');

        Route::get('/printing/{ballot?}', [PrintingController::class, 'show'])->name('printing');
        Route::post('/printing/{ballot}/print', [PrintingController::class, 'print'])->name('printing.print');
        Route::get('/printing/{ballot}/forms/{profile}', [PrintingController::class, 'downloadForm'])->name('printing.forms.download');
        Route::post('/printing/{ballot}/spoil', [PrintingController::class, 'spoil'])->name('printing.spoil');

        Route::get('/counting', [CountingController::class, 'show'])->name('counting');
        Route::post('/counting/scan', [CountingController::class, 'scan'])->name('counting.scan');
        Route::post('/counting/rma/select-sample', [CountingController::class, 'selectRandomManualAuditSample'])->name('counting.rma.select-sample');
        Route::post('/counting/rma/propose', [CountingController::class, 'proposeRandomManualAudit'])->name('counting.rma.propose');
        Route::post('/counting/rma/approve', [CountingController::class, 'approveRandomManualAudit'])->name('counting.rma.approve');
        Route::post('/counting/rma/discrepancy', [CountingController::class, 'recordRandomManualAuditDiscrepancy'])->name('counting.rma.discrepancy');
        Route::post('/counting/rma/reconciliation-report', [CountingController::class, 'generateRandomManualAuditReconciliationReport'])->name('counting.rma.reconciliation-report');
        Route::post('/counting/rma/evidence-pack', [CountingController::class, 'buildRandomManualAuditEvidencePack'])->name('counting.rma.evidence-pack.build');
        Route::get('/counting/rma/evidence-pack/download', [CountingController::class, 'downloadRandomManualAuditEvidencePack'])->name('counting.rma.evidence-pack.download');
        Route::get('/counting/rma/evidence-pack/print', [CountingController::class, 'downloadRandomManualAuditEvidencePackPdf'])->name('counting.rma.evidence-pack.print');
        Route::post('/counting/physical-count', [CountingController::class, 'recordPhysicalCount'])->name('counting.physical-count');
        Route::post('/counting/adjudicate', [CountingController::class, 'adjudicate'])->name('counting.adjudicate');
        Route::post('/counting/complete', [CountingController::class, 'complete'])->name('counting.complete');
        Route::get('/counting/tally-sheet/{profile}', [CountingController::class, 'downloadTallySheet'])->name('counting.tally-sheet.download');

        Route::get('/returns', [ReturnsController::class, 'show'])->name('returns');
        Route::post('/returns/generate', [ReturnsController::class, 'generate'])->name('returns.generate');
        Route::post('/returns/copy-distribution', [ReturnsController::class, 'copyDistribution'])->name('returns.copy-distribution');
        Route::post('/returns/approve', [ReturnsController::class, 'approve'])->name('returns.approve');
        Route::post('/returns/close', [ReturnsController::class, 'close'])->name('returns.close');
        Route::get('/returns/forms/{profile}', [ReturnsController::class, 'downloadForm'])->name('returns.forms.download');

        Route::get('/transmission', [TransmissionController::class, 'show'])->name('transmission');
        Route::post('/transmission/package', [TransmissionController::class, 'preparePackage'])->name('transmission.prepare');
        Route::post('/transmission/officer-verification', [TransmissionController::class, 'verifyOfficer'])->name('transmission.officer-verification');
        Route::post('/transmission/recipient-verification', [TransmissionController::class, 'verifyRecipient'])->name('transmission.recipient-verification');
        Route::post('/transmission/receipt', [TransmissionController::class, 'recordReceipt'])->name('transmission.receipt');
        Route::post('/transmission/final-backup', [TransmissionController::class, 'recordFinalBackup'])->name('transmission.final-backup');
        Route::post('/transmission/send', [TransmissionController::class, 'send'])->name('transmission.send');
        Route::post('/transmission/custody', [TransmissionController::class, 'recordCustody'])->name('transmission.custody');
        Route::post('/transmission/close-precinct', [TransmissionController::class, 'closePrecinct'])->name('transmission.close-precinct');

        Route::get('/diagnostics', [DiagnosticsController::class, 'show'])->name('diagnostics');
        Route::post('/diagnostics/certify-devices', [DiagnosticsController::class, 'certifyDevices'])->name('diagnostics.certify-devices');
        Route::post('/diagnostics/recovery', [DiagnosticsController::class, 'inspectRecovery'])->name('diagnostics.recovery.inspect');
        Route::post('/diagnostics/begin-audit', [DiagnosticsController::class, 'beginAudit'])->name('diagnostics.begin-audit');
        Route::post('/diagnostics/evidence-manifest', [DiagnosticsController::class, 'generateEvidenceManifest'])->name('diagnostics.evidence-manifest.generate');
        Route::get('/diagnostics/evidence-manifest/download', [DiagnosticsController::class, 'downloadEvidenceManifest'])->name('diagnostics.evidence-manifest.download');
        Route::post('/diagnostics/evidence-reference-baseline', [DiagnosticsController::class, 'generateEvidenceReferenceBaseline'])->name('diagnostics.evidence-reference-baseline.generate');
        Route::get('/diagnostics/evidence-reference-baseline/download', [DiagnosticsController::class, 'downloadEvidenceReferenceBaseline'])->name('diagnostics.evidence-reference-baseline.download');
        Route::post('/diagnostics/official-minutes-baseline', [DiagnosticsController::class, 'generateOfficialMinutesBaseline'])->name('diagnostics.official-minutes-baseline.generate');
        Route::get('/diagnostics/official-minutes-baseline/download', [DiagnosticsController::class, 'downloadOfficialMinutesBaseline'])->name('diagnostics.official-minutes-baseline.download');
        Route::post('/diagnostics/audit-reconciliation-baseline', [DiagnosticsController::class, 'generateAuditReconciliationBaseline'])->name('diagnostics.audit-reconciliation-baseline.generate');
        Route::get('/diagnostics/audit-reconciliation-baseline/download', [DiagnosticsController::class, 'downloadAuditReconciliationBaseline'])->name('diagnostics.audit-reconciliation-baseline.download');
        Route::post('/diagnostics/initialization-report', [DiagnosticsController::class, 'generateInitializationReport'])->name('diagnostics.initialization-report.generate');
        Route::get('/diagnostics/initialization-report/download', [DiagnosticsController::class, 'downloadInitializationReport'])->name('diagnostics.initialization-report.download');
        Route::post('/diagnostics/evidence-bundle-archive', [DiagnosticsController::class, 'buildEvidenceBundleArchive'])->name('diagnostics.evidence-bundle-archive.build');
        Route::get('/diagnostics/evidence-bundle-archive/download', [DiagnosticsController::class, 'downloadEvidenceBundleArchive'])->name('diagnostics.evidence-bundle-archive.download');
        Route::post('/diagnostics/evidence-bundle-archive/verify', [DiagnosticsController::class, 'verifyEvidenceBundleArchive'])->name('diagnostics.evidence-bundle-archive.verify');
        Route::post('/diagnostics/evidence-bundle-archive/upload-verify', [DiagnosticsController::class, 'verifyUploadedEvidenceBundleArchive'])->name('diagnostics.evidence-bundle-archive.upload-verify');
        Route::post('/diagnostics/removable-media/export', [DiagnosticsController::class, 'exportRemovableMedia'])->name('diagnostics.removable-media.export');
        Route::post('/diagnostics/removable-media/readiness', [DiagnosticsController::class, 'checkRemovableMediaReadiness'])->name('diagnostics.removable-media.readiness');
        Route::post('/diagnostics/removable-media/verify', [DiagnosticsController::class, 'verifyRemovableMedia'])->name('diagnostics.removable-media.verify');
        Route::get('/diagnostics/attestations/{artifact}', [DiagnosticsController::class, 'attestation'])->name('diagnostics.attestations.show');
        Route::get('/diagnostics/attestations/{artifact}/download', [DiagnosticsController::class, 'downloadAttestation'])->name('diagnostics.attestations.download');
        Route::get('/diagnostics/signatures/{artifact}', [DiagnosticsController::class, 'signature'])->name('diagnostics.signatures.show');
        Route::get('/diagnostics/signatures/{artifact}/download', [DiagnosticsController::class, 'downloadSignature'])->name('diagnostics.signatures.download');
        Route::post('/attestations', [AttestationController::class, 'store'])->name('attestations.store');
    });

    Route::middleware('review-room-role:voter')->group(function (): void {
        Route::get('/voter', [VoterAuthorizationController::class, 'show'])->name('voter');
        Route::post('/voter/claim', [VoterAuthorizationController::class, 'claim'])
            ->middleware('throttle:10,1')
            ->name('voter.claim');
        Route::get('/voter/ballot', [VoterBallotController::class, 'show'])->name('voter.ballot');
        Route::post('/voter/ballot', [VoterBallotController::class, 'finalize'])->name('voter.finalize');
        Route::get('/voter/complete', [VoterBallotController::class, 'complete'])->name('voter.complete');
    });

    Route::middleware('review-room-role:print_station')->group(function (): void {
        Route::get('/print-station', [PrintStationController::class, 'show'])->name('print-station');
        Route::post('/print-station/redeem', [PrintStationController::class, 'redeem'])
            ->middleware('throttle:10,1')
            ->name('print-station.redeem');
        Route::post('/print-station/print', [PrintStationController::class, 'print'])->name('print-station.print');
        Route::post('/print-station/deposit', [PrintStationController::class, 'deposit'])->name('print-station.deposit');
    });

    Route::middleware('review-room-role:watcher')->group(function (): void {
        Route::get('/watchers', WatcherController::class)->name('watchers');
        Route::get('/watchers/tally-sheet/download', [WatcherController::class, 'downloadTallySheetPdf'])->name('watchers.tally-sheet.download');
        Route::get('/watchers/tally/download', [WatcherController::class, 'downloadTallyJson'])->name('watchers.tally.download');
        Route::get('/watchers/rma/evidence-pack/download', [WatcherController::class, 'downloadRandomManualAuditEvidencePack'])
            ->name('watchers.rma.evidence-pack.download');
        Route::get('/watchers/rma/evidence-pack/print', [WatcherController::class, 'downloadRandomManualAuditEvidencePackPdf'])
            ->name('watchers.rma.evidence-pack.print');
        Route::post('/watchers/rma/evidence-pack/verify', [WatcherController::class, 'verifyRandomManualAuditEvidencePack'])
            ->name('watchers.rma.evidence-pack.verify');
    });
});
