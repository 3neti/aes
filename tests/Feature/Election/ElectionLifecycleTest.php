<?php

use App\Election\Attestation\OfficerAttestationService;
use App\Election\Attestation\OfficerRegistry;
use App\Election\Certification\CertificationService;
use App\Election\Certification\DiscrepancyReportService;
use App\Election\Certification\ManualVerificationService;
use App\Election\Core\ActivityJournal;
use App\Election\Counting\CountingService;
use App\Election\Devices\CameraScannerHealthCheck;
use App\Election\Devices\CupsPrinterHealthCheck;
use App\Election\Devices\DeviceCertificationService;
use App\Election\Devices\HandheldScannerHealthCheck;
use App\Election\Diagnostics\ApplianceRecoveryService;
use App\Election\Diagnostics\DiagnosticsService;
use App\Election\Diagnostics\EvidenceBundleArchiveBuilder;
use App\Election\Diagnostics\EvidenceBundleArchiveVerifier;
use App\Election\Diagnostics\RemovableMediaExporter;
use App\Election\Diagnostics\RemovableMediaExportVerifier;
use App\Election\Lifecycle\CeremonyActions;
use App\Election\Lifecycle\ElectionRunType;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Preparation\DeterministicMapper;
use App\Election\Preparation\PrecinctSetupService;
use App\Election\Preparation\SampleElectionData;
use App\Election\Printing\BallotPrinter;
use App\Election\Printing\PrinterCertificationRequired;
use App\Election\Printing\SpoilBallot;
use App\Election\Returns\ElectionReturnService;
use App\Election\Scanning\BallotScanner;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\BallotPayloadService;
use App\Election\Voting\PaperBallotLedger;
use App\Election\Voting\StandardQrCode;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
});

test('automated tests use storage isolated from operational election evidence', function (): void {
    $storage = app(ElectionStorage::class);

    expect($storage->root())->toEndWith('/storage/app/election-testing')
        ->and(storage_path('app/election/runs/20260724-004938-0421-a-operator'))->toBeDirectory();
});

test('rehearsal runs cannot replace the election day pointer', function (): void {
    $storage = app(ElectionStorage::class);
    $storage->selectRunType(ElectionRunType::ElectionDay);
    $electionDay = $storage->startRun(
        'operator',
        '39010001',
        '20260511-050000',
        ElectionRunType::ElectionDay,
    );

    $storage->selectRunType(ElectionRunType::Rehearsal);
    $rehearsal = $storage->startRun(
        'full-demo',
        '39010001',
        '20260508-080000',
        ElectionRunType::Rehearsal,
        'scenario-runner',
    );

    expect(trim(file_get_contents($storage->root().'/LATEST_RUN.txt')))->toBe($electionDay['run_path'])
        ->and($storage->currentRun(ElectionRunType::ElectionDay)['run_id'])->toBe($electionDay['run_id'])
        ->and($storage->currentRun(ElectionRunType::Rehearsal)['run_id'])->toBe($rehearsal['run_id']);
});

test('locked runs cannot be reset or replaced', function (): void {
    $storage = app(ElectionStorage::class);
    $storage->selectRunType(ElectionRunType::ElectionDay);
    $run = $storage->startRun(
        'operator',
        '39010001',
        '20260511-050000',
        ElectionRunType::ElectionDay,
    );
    $locked = $storage->lockActiveRun();

    expect($locked['status'])->toBe('locked')
        ->and($locked['run_type'])->toBe(ElectionRunType::ElectionDay->value)
        ->and($run['run_path'].'/run-context.json')->toBeFile();

    expect(fn () => $storage->startRun(
        'operator',
        '39010001',
        '20260511-050000',
        ElectionRunType::ElectionDay,
    ))->toThrow(RuntimeException::class);
});

test('lifecycle transitions reject invalid jumps', function (): void {
    $lifecycle = app(LifecycleState::class);

    expect($lifecycle->current())->toBe(Lifecycle::Provision);

    $lifecycle->advanceTo(Lifecycle::Certification);

    expect($lifecycle->current())->toBe(Lifecycle::Certification);
    expect(fn () => $lifecycle->advanceTo(Lifecycle::Voting))->toThrow(RuntimeException::class);
});

test('open polls follows legal stepwise transition', function (): void {
    $lifecycle = app(LifecycleState::class);
    $ceremonies = app(CeremonyActions::class);

    $lifecycle->set(Lifecycle::OpenPrecinct);

    $ceremonies->openPolls();
    expect($lifecycle->current())->toBe(Lifecycle::OpenPolls);

    $ceremonies->openPolls();
    expect($lifecycle->current())->toBe(Lifecycle::Voting);

    expect(fn () => $ceremonies->openPolls())->toThrow(RuntimeException::class);
});

test('lifecycle includes transmission, final backup, and custody stages', function (): void {
    $lifecycle = app(LifecycleState::class);
    $ceremonies = app(CeremonyActions::class);

    $lifecycle->set(Lifecycle::ElectionReturn);

    $ceremonies->moveToTransmission();
    expect($lifecycle->current())->toBe(Lifecycle::Transmission);

    $ceremonies->completeTransmission();
    expect($lifecycle->current())->toBe(Lifecycle::FinalBackup);

    $ceremonies->recordCustody();
    expect($lifecycle->current())->toBe(Lifecycle::Custody);

    $ceremonies->closePrecinct();
    expect($lifecycle->current())->toBe(Lifecycle::ClosePrecinct);
});

test('appliance recovery resumes an intact run at the same ceremony', function (): void {
    app(ActivateSamplePackage::class)->handle();
    app(DeviceCertificationService::class)->run();
    app(LifecycleState::class)->set(Lifecycle::Voting);

    $stageBeforeRecovery = app(LifecycleState::class)->current();

    $this->artisan('election:recover')
        ->expectsOutputToContain('Resume status: resume-allowed')
        ->expectsOutputToContain('Device status: ready')
        ->assertSuccessful();

    $report = app(ElectionStorage::class)->readJson('diagnostics/appliance-recovery-report.json');

    expect($report['critical_checks_passed'])->toBeTrue()
        ->and($report['resume_status'])->toBe('resume-allowed')
        ->and($report['lifecycle_stage'])->toBe($stageBeforeRecovery)
        ->and(app(LifecycleState::class)->current())->toBe($stageBeforeRecovery);

    $this->post(route('election.diagnostics.recovery.inspect'))
        ->assertRedirect(route('election.diagnostics'));
});

test('appliance recovery blocks resumption when the activity journal was altered', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $storage = app(ElectionStorage::class);
    $journalPath = $storage->path('journals/activity.jsonl');
    $entries = file($journalPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $firstEntry = json_decode((string) $entries[0], true, flags: JSON_THROW_ON_ERROR);
    $firstEntry['payload']['precinct_id'] = 'altered-precinct';
    $entries[0] = json_encode($firstEntry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($journalPath, implode(PHP_EOL, $entries).PHP_EOL);

    $report = app(ApplianceRecoveryService::class)->inspect();

    expect($report['critical_checks_passed'])->toBeFalse()
        ->and($report['resume_status'])->toBe('locked-for-diagnostics')
        ->and(collect($report['checks'])->firstWhere('name', 'activity_journal_chain')['passed'])->toBeFalse();
});

test('appliance recovery reports degraded devices without blocking intact evidence', function (): void {
    app(ActivateSamplePackage::class)->handle();
    app(ElectionStorage::class)->writeJson('certification/device-certification-report.json', [
        'schema_version' => 'device-certification-report-1',
        'passed' => false,
        'devices' => [
            'printer' => ['status' => 'unavailable'],
            'scanner' => ['status' => 'ready'],
        ],
    ]);

    $report = app(ApplianceRecoveryService::class)->inspect();

    expect($report['resume_status'])->toBe('resume-allowed')
        ->and($report['device_status'])->toBe('degraded')
        ->and($report['degraded_devices'])->toBe(['printer']);
});

test('returns close action advances lifecycle to transmission ceremony', function (): void {
    $this->artisan('election:scenario election-return-copy-distribution')
        ->assertSuccessful();

    $this->post(route('election.returns.close'))
        ->assertRedirect(route('election.transmission'));

    expect(app(LifecycleState::class)->current())->toBe(Lifecycle::Transmission);
});

test('sample package activation derives deterministic mapping', function (): void {
    $configuration = app(ActivateSamplePackage::class)->handle();
    $sample = app(SampleElectionData::class);
    $derivedAgain = app(DeterministicMapper::class)->derive($sample->registries(), $sample->package());

    expect($configuration['precinct_id'])->toBe('0421-A')
        ->and($configuration['mapping_hash'])->toBe($derivedAgain['mapping_hash'])
        ->and(app(ElectionStorage::class)->readJson('runtime/active-precinct.json')['mapping_hash'])->toBe($configuration['mapping_hash']);
});

test('friday certification matches expected result', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $report = app(CertificationService::class)->run();

    expect($report['passed'])->toBeTrue()
        ->and($report['actual_tally'])->toBe($report['expected_tally'])
        ->and(app(ElectionStorage::class)->readJson('certification/friday-certification-report.json')['report_hash'])->toBe($report['report_hash']);
});

test('manual verification passes with matching official return', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $certification = app(CertificationService::class)->run();
    $manualReturn = [
        'schema_version' => 'manual-return-1',
        'precinct_id' => $certification['precinct_id'],
        'accepted_ballots' => $certification['accepted_ballots'],
        'rejected_ballots' => $certification['rejected_ballots'],
        'tally' => $certification['actual_tally'],
    ];
    $verification = app(ManualVerificationService::class)->run($manualReturn);
    $checks = collect($verification['checks'])->keyBy('name');

    expect($verification['passed'])->toBeTrue()
        ->and($verification['machine_accepted_ballots'])->toBe($certification['accepted_ballots'])
        ->and($verification['manual_accepted_ballots'])->toBe($certification['accepted_ballots'])
        ->and($verification['machine_rejected_ballots'])->toBe($certification['rejected_ballots'])
        ->and($verification['manual_rejected_ballots'])->toBe($certification['rejected_ballots'])
        ->and($checks->get('tally_comparison')['passed'] ?? false)->toBeTrue()
        ->and(file_exists($verification['artifact_path']))->toBeTrue();
});

test('manual verification fails when manual totals differ', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $certification = app(CertificationService::class)->run();
    $manualReturn = [
        'schema_version' => 'manual-return-1',
        'precinct_id' => $certification['precinct_id'],
        'accepted_ballots' => $certification['accepted_ballots'] + 1,
        'rejected_ballots' => $certification['rejected_ballots'],
        'tally' => $certification['actual_tally'],
    ];
    $verification = app(ManualVerificationService::class)->run($manualReturn);
    $checks = collect($verification['checks'])->keyBy('name');

    expect($verification['passed'])->toBeFalse()
        ->and($checks->get('tally_comparison')['passed'] ?? false)->toBeFalse();
});

test('discrepancy report detects manual verification mismatch', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $certification = app(CertificationService::class)->run();
    $manualReturn = [
        'schema_version' => 'manual-return-1',
        'precinct_id' => $certification['precinct_id'],
        'accepted_ballots' => $certification['accepted_ballots'] + 1,
        'rejected_ballots' => $certification['rejected_ballots'],
        'tally' => $certification['actual_tally'],
    ];
    app(ManualVerificationService::class)->run($manualReturn);

    $discrepancy = app(DiscrepancyReportService::class)->run();

    expect($discrepancy['discrepancy_detected'])->toBeTrue()
        ->and($discrepancy['status'])->toBe('discrepancy')
        ->and((bool) ($discrepancy['passed'] ?? false))->toBeFalse()
        ->and($discrepancy['manual_verification_report_hash'])->toBeString()
        ->and($discrepancy['official_minutes_hash'])->toBeString()
        ->and(file_exists($discrepancy['artifact_path']))->toBeTrue();
});

test('fts zero-out and sealing scenario clears counting artifacts', function (): void {
    $this->artisan('election:scenario fts-zero-out')
        ->expectsOutput('Scenario fts-zero-out passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-fts-zero-out')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $scenario = $storage->readJson('scenarios/fts-zero-out-report.json');
    $certification = $storage->readJson('certification/friday-certification-report.json');
    $zeroOut = $storage->readJson('certification/zero-out-report.json');
    $sealing = $storage->readJson('certification/sealing-report.json');
    $discrepancy = $storage->readJson('certification/fts-discrepancy-report.json');

    expect($scenario['scenario'])->toBe('fts-zero-out')
        ->and($scenario['passed'])->toBeTrue()
        ->and($scenario['discrepancy_detected'])->toBeFalse()
        ->and($scenario['discrepancy_report_hash'])->toBe($discrepancy['report_hash'])
        ->and($scenario['zero_out_report_hash'])->toBe($zeroOut['report_hash'])
        ->and($scenario['sealing_report_hash'])->toBe($sealing['report_hash'])
        ->and($scenario['zero_out_passed'])->toBeTrue()
        ->and($scenario['sealing_passed'])->toBeTrue()
        ->and($zeroOut['counts_after']['accepted_ballots'])->toBe(0)
        ->and($zeroOut['counts_after']['rejected_ballots'])->toBe(0)
        ->and($zeroOut['counts_after']['spoiled_ballots'])->toBe(0)
        ->and($zeroOut['passed'])->toBeTrue()
        ->and($sealing['status'])->toBe('sealed')
        ->and($sealing['passed'])->toBeTrue()
        ->and($zeroOut['certification_report_hash'] ?? null)->toBe($certification['report_hash'])
        ->and(file_exists($zeroOut['artifact_path']))->toBeTrue()
        ->and(file_exists($sealing['artifact_path']))->toBeTrue()
        ->and($storage->files('counting/accepted'))->toHaveCount(0)
        ->and($storage->files('counting/rejected'))->toHaveCount(0)
        ->and($storage->files('runtime/spoiled-ballots'))->toHaveCount(0);
});

test('voting legal edge cases scenario blocks invalid lifecycle transitions', function (): void {
    $this->artisan('election:scenario voting-legal-edge-cases')
        ->expectsOutput('Scenario voting-legal-edge-cases passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-voting-legal-edge-cases')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $scenario = $storage->readJson('scenarios/voting-legal-edge-cases-report.json');

    expect($scenario['scenario'])->toBe('voting-legal-edge-cases')
        ->and($scenario['passed'])->toBeTrue()
        ->and($scenario['invalid_open_from_provision'])->toBeTrue()
        ->and($scenario['invalid_close_from_open_polls'])->toBeTrue()
        ->and($scenario['invalid_close_from_close_polls'])->toBeTrue()
        ->and($scenario['stage_after_valid_open'])->toBe(Lifecycle::OpenPolls)
        ->and($scenario['stage_after_close'])->toBe(Lifecycle::ClosePolls);
});

test('special polling intake scenario records deterministic entries and hashes', function (): void {
    $this->artisan('election:scenario special-polling-intake')
        ->expectsOutput('Scenario special-polling-intake passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-special-polling-intake')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $scenario = $storage->readJson('scenarios/special-polling-intake-report.json');
    $summary = $storage->readJson('voting/special-polling-intake.json');
    $entryPaths = $scenario['entry_paths'] ?? [];

    expect($scenario['scenario'])->toBe('special-polling-intake')
        ->and($scenario['passed'])->toBeTrue()
        ->and($scenario['entry_count'])->toBe(3)
        ->and($scenario['total_ballots'])->toBe(14)
        ->and($scenario['totals_by_type']['ppp'])->toBe(7)
        ->and($scenario['totals_by_type']['ip'])->toBe(4)
        ->and($scenario['totals_by_type']['pdl'])->toBe(3)
        ->and($scenario['special_polling_intake_hash'])->toBe($summary['special_polling_intake_hash'])
        ->and($scenario['entry_paths'])->toHaveCount(3)
        ->and($scenario['stage_after_special_intake'])->toBe(Lifecycle::ClosePolls)
        ->and(file_exists($scenario['special_polling_intake_path']))->toBeTrue()
        ->and(collect($entryPaths)->every(
            fn (string $path): bool => file_exists($storage->path($path)),
        ))->toBeTrue()
        ->and($summary['run_id'] ?? null)->toBe($scenario['run_id']);
});

test('close polls and counting legal evidence scenario records both evidences', function (): void {
    $this->artisan('election:scenario close-polls-and-counting-legal-evidence')
        ->expectsOutput('Scenario close-polls-and-counting-legal-evidence passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-close-polls-and-counting-legal-evidence')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $scenario = $storage->readJson('scenarios/close-polls-and-counting-legal-evidence-report.json');
    $closeEvidence = $storage->readJson('closing/close-polls-legal-evidence.json');
    $countingEvidence = $storage->readJson('counting/counting-legal-evidence.json');

    expect($scenario['scenario'])->toBe('close-polls-and-counting-legal-evidence')
        ->and($scenario['passed'])->toBeTrue()
        ->and($scenario['accepted_ballots_counted'])->toBe(1)
        ->and($scenario['rejected_ballots_counted'])->toBe(1)
        ->and($scenario['run_id'])->toBe($storage->currentRun()['run_id'])
        ->and($scenario['close_polls_evidence_path'])->toBe($storage->path('closing/close-polls-legal-evidence.json'))
        ->and($scenario['close_polls_evidence_hash'])->toBe($closeEvidence['evidence_hash'])
        ->and($scenario['counting_evidence_path'])->toBe($storage->path('counting/counting-legal-evidence.json'))
        ->and($scenario['counting_evidence_hash'])->toBe($countingEvidence['evidence_hash'])
        ->and($closeEvidence['schema_version'])->toBe('close-polls-legal-evidence-1')
        ->and($closeEvidence['stage'])->toBe(Lifecycle::ClosePolls)
        ->and($countingEvidence['schema_version'])->toBe('counting-legal-evidence-1')
        ->and($countingEvidence['stage'])->toBe(Lifecycle::Counting)
        ->and($countingEvidence['accepted_ballots'])->toBe(1)
        ->and($countingEvidence['rejected_ballots'])->toBe(1)
        ->and($countingEvidence['passed'])->toBeTrue()
        ->and(app(LifecycleState::class)->current())->toBe(Lifecycle::Counting)
        ->and(file_exists($scenario['close_polls_evidence_path']))->toBeTrue()
        ->and(file_exists($scenario['counting_evidence_path']))->toBeTrue();
});

test('friday certification scenario includes manual verification report', function (): void {
    $this->artisan('election:scenario friday-certification')
        ->expectsOutput('Scenario friday-certification passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-friday-certification')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $report = $storage->readJson('scenarios/friday-certification-report.json');
    $certification = $storage->readJson('certification/friday-certification-report.json');
    $manualVerification = $storage->readJson('certification/manual-verification-report.json');

    expect($report['scenario'])->toBe('friday-certification')
        ->and($report['passed'])->toBeTrue()
        ->and($report['manual_verification_passed'])->toBeTrue()
        ->and($report['manual_verification_report_hash'])->toBe($manualVerification['report_hash'])
        ->and($report['precinct_id'])->toBe($certification['precinct_id'])
        ->and($manualVerification['passed'])->toBeTrue()
        ->and($manualVerification['machine_accepted_ballots'])->toBe($certification['accepted_ballots'])
        ->and(file_exists($manualVerification['artifact_path']))->toBeTrue();
});

test('fts manual verification discrepancy scenario records discrepancy report', function (): void {
    $this->artisan('election:scenario fts-manual-verification-discrepancy')
        ->expectsOutput('Scenario fts-manual-verification-discrepancy passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-fts-manual-verification-discrepancy')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $report = $storage->readJson('scenarios/fts-manual-verification-discrepancy-report.json');
    $discrepancy = $storage->readJson('certification/fts-discrepancy-report.json');

    expect($report['scenario'])->toBe('fts-manual-verification-discrepancy')
        ->and($report['discrepancy_detected'])->toBeTrue()
        ->and($report['discrepancy_report_hash'])->toBe($discrepancy['report_hash'])
        ->and(file_exists($discrepancy['artifact_path']))->toBeTrue()
        ->and(file_exists((string) $report['official_minutes_path']))->toBeTrue();
});

test('device certification checks simulated printer and scanner adapters', function (): void {
    $report = app(DeviceCertificationService::class)->run();
    $diagnostics = app(DiagnosticsService::class)->get();
    $events = app(ActivityJournal::class)->entries();

    expect($report['passed'])->toBeTrue()
        ->and($report['devices']['printer']['status'])->toBe('ready')
        ->and($report['devices']['scanner']['status'])->toBe('ready')
        ->and(app(ElectionStorage::class)->readJson('certification/device-certification-report.json')['report_hash'])->toBe($report['report_hash'])
        ->and($diagnostics['device_certification']['report_hash'])->toBe($report['report_hash'])
        ->and(collect($events)->pluck('event_type'))->toContain('devices.certification_passed');
});

test('evidence export verifier accepts intact removable media bundle', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $export = app(RemovableMediaExporter::class)->export();
    $verification = app(RemovableMediaExportVerifier::class)->verify($export['target_path']);

    expect($verification['passed'])->toBeTrue()
        ->and($verification['export_id'])->toBe($export['export_id'])
        ->and($verification['checked_files'])->toBe($export['artifact_count'])
        ->and($verification['mismatches'])->toBe([]);

    $this->artisan('election:evidence-verify', ['path' => $export['target_path']])
        ->expectsOutput("Evidence export {$export['export_id']} verified.")
        ->expectsOutput("Checked files: {$export['artifact_count']}")
        ->assertSuccessful();
});

test('evidence export verifier reports tampered artifact mismatch', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $export = app(RemovableMediaExporter::class)->export();
    $target = $export['target_path'].'/'.$export['copied_files'][0]['target'];
    file_put_contents($target, 'tampered');

    $verification = app(RemovableMediaExportVerifier::class)->verify($export['target_path']);

    expect($verification['passed'])->toBeFalse()
        ->and(collect($verification['mismatches'])->pluck('type'))->toContain('artifact_hash_mismatch')
        ->and(collect($verification['mismatches'])->pluck('type'))->toContain('artifact_size_mismatch');

    $this->artisan('election:evidence-verify', ['path' => $export['target_path']])
        ->expectsOutputToContain('Evidence export verification failed.')
        ->expectsOutputToContain('artifact_hash_mismatch')
        ->assertFailed();
});

test('evidence bundle archive verifier accepts intact tar bundle', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $archive = app(EvidenceBundleArchiveBuilder::class)->build();
    $verification = app(EvidenceBundleArchiveVerifier::class)->verify($archive['archive_path']);

    expect($verification['passed'])->toBeTrue()
        ->and($verification['archive_id'])->toBe($archive['archive_id'])
        ->and($verification['archive_sha256'])->toBe($archive['archive_sha256'])
        ->and($verification['checked_files'])->toBeGreaterThan(0)
        ->and($verification['mismatches'])->toBe([]);

    $this->artisan('election:archive-verify', ['path' => $archive['archive_path']])
        ->expectsOutput("Evidence bundle archive {$archive['archive_id']} verified.")
        ->expectsOutput("Checked files: {$verification['checked_files']}")
        ->assertSuccessful();
});

test('evidence bundle archive excludes a previously generated manifest from its artifact inventory', function (): void {
    app(ActivateSamplePackage::class)->handle();
    $firstArchive = app(EvidenceBundleArchiveBuilder::class)->build();
    app(EvidenceBundleArchiveVerifier::class)->writeReport($firstArchive['archive_path']);

    $archive = app(EvidenceBundleArchiveBuilder::class)->build();
    $verification = app(EvidenceBundleArchiveVerifier::class)->verify($archive['archive_path']);
    $manifest = app(ElectionStorage::class)->readJson('diagnostics/evidence-manifest.json');
    $manifestPaths = collect($manifest['categories'] ?? [])
        ->flatMap(fn (array $category): array => $category['files'] ?? [])
        ->pluck('relative_path');

    expect($manifestPaths)->not->toContain('12-audit-and-reconciliation/evidence-manifest.json')
        ->and($manifestPaths)->not->toContain('12-audit-and-reconciliation/evidence-bundle-archive.json')
        ->and($manifestPaths)->not->toContain('12-audit-and-reconciliation/evidence-bundle-archive-verification.json')
        ->and($manifestPaths->filter(fn (string $path): bool => str_ends_with($path, '.tar')))->toBeEmpty()
        ->and($verification['passed'])->toBeTrue()
        ->and($verification['mismatches'])->toBe([]);
});

test('evidence bundle archive verifier reports tampered tar artifact mismatch', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $archive = app(EvidenceBundleArchiveBuilder::class)->build();
    $archiveContents = file_get_contents($archive['archive_path']);
    file_put_contents(
        $archive['archive_path'],
        str_replace('AES-2026-SAMPLE', 'AES-2026-TAMPER', $archiveContents),
    );

    $verification = app(EvidenceBundleArchiveVerifier::class)->verify($archive['archive_path']);

    expect($verification['passed'])->toBeFalse()
        ->and(collect($verification['mismatches'])->pluck('type'))->toContain('artifact_hash_mismatch');

    $this->artisan('election:archive-verify', ['path' => $archive['archive_path']])
        ->expectsOutputToContain('Evidence bundle archive verification failed.')
        ->expectsOutputToContain('artifact_hash_mismatch')
        ->assertFailed();
});

test('cups printer health check is selectable through device certification config', function (): void {
    config()->set('election.devices.printer.adapter', 'cups');
    config()->set('election.devices.printer.cups.name', 'Precinct_Printer');
    config()->set('election.devices.printer.cups.timeout', 2);
    app()->forgetInstance(DeviceCertificationService::class);
    Process::fake([
        '*' => Process::result('printer Precinct_Printer is idle. enabled since today'),
    ]);

    try {
        $report = app(DeviceCertificationService::class)->run();

        expect($report['passed'])->toBeTrue()
            ->and($report['devices']['printer']['adapter'])->toBe('cups-printer')
            ->and($report['devices']['printer']['printer'])->toBe('Precinct_Printer')
            ->and($report['devices']['scanner']['adapter'])->toBe('simulated-scanner');

        Process::assertRan(fn ($process): bool => $process->command === ['lpstat', '-p', 'Precinct_Printer']);
    } finally {
        config()->set('election.devices.printer.adapter', 'simulated');
        app()->forgetInstance(DeviceCertificationService::class);
    }
});

test('cups printer health check reports not configured without probing process', function (): void {
    Process::fake();

    $result = (new CupsPrinterHealthCheck(''))->check();

    expect($result['status'])->toBe('not-configured')
        ->and($result['adapter'])->toBe('cups-printer');

    Process::assertNothingRan();
});

test('handheld scanner health check is selectable through device certification config', function (): void {
    config()->set('election.devices.scanner.adapter', 'handheld');
    config()->set('election.devices.scanner.handheld.name', 'USB Scanner 1');
    app()->forgetInstance(DeviceCertificationService::class);

    try {
        $report = app(DeviceCertificationService::class)->run();

        expect($report['passed'])->toBeTrue()
            ->and($report['devices']['scanner']['adapter'])->toBe('handheld-keyboard-wedge')
            ->and($report['devices']['scanner']['device'])->toBe('USB Scanner 1');
    } finally {
        config()->set('election.devices.scanner.adapter', 'simulated');
        app()->forgetInstance(DeviceCertificationService::class);
    }
});

test('handheld scanner health check reports not configured', function (): void {
    $result = (new HandheldScannerHealthCheck(''))->check();

    expect($result['status'])->toBe('not-configured')
        ->and($result['adapter'])->toBe('handheld-keyboard-wedge');
});

test('camera scanner health check is selectable through device certification config', function (): void {
    config()->set('election.devices.scanner.adapter', 'camera');
    config()->set('election.devices.scanner.camera.name', 'Precinct Camera 1');
    app()->forgetInstance(DeviceCertificationService::class);

    try {
        $report = app(DeviceCertificationService::class)->run();

        expect($report['passed'])->toBeTrue()
            ->and($report['devices']['scanner']['adapter'])->toBe('camera-image')
            ->and($report['devices']['scanner']['device'])->toBe('Precinct Camera 1');
    } finally {
        config()->set('election.devices.scanner.adapter', 'simulated');
        app()->forgetInstance(DeviceCertificationService::class);
    }
});

test('camera scanner health check reports not configured', function (): void {
    $result = (new CameraScannerHealthCheck(''))->check();

    expect($result['status'])->toBe('not-configured')
        ->and($result['adapter'])->toBe('camera-image');
});

test('officer attestation writes artifact and journal event', function (): void {
    $record = app(OfficerAttestationService::class)->attest([
        'ceremony' => 'Friday Certification',
        'officer_code' => 'SIM-OFFICER-001',
        'officer_pin' => '123456',
        'signature_data' => lifecycleTestSignatureDataUri(),
        'stage' => Lifecycle::Certification,
        'statement' => 'Certification checkpoint reviewed.',
    ]);
    $events = app(ActivityJournal::class)->entries();

    expect($record['attestation_id'])->toBe('attestation-000001')
        ->and($record['attestation_hash'])->toBeString()
        ->and($record['officer_code_hash'])->toBe(hash('sha256', 'SIM-OFFICER-001'))
        ->and($record['officer_name'])->toBe('Simulation Officer')
        ->and($record['officer_role'])->toBe('Election Board Chairperson')
        ->and($record['signature_artifact_hash'])->toBe(hash('sha256', lifecycleTestSignaturePng()))
        ->and(file_exists($record['artifact_path']))->toBeTrue()
        ->and(file_exists($record['signature_artifact_path']))->toBeTrue()
        ->and(app(ElectionStorage::class)->files('attestations'))->toHaveCount(1)
        ->and(app(ElectionStorage::class)->files('attestation-signatures'))->toHaveCount(1)
        ->and(collect($events)->pluck('event_type'))->toContain('officer.attested');
});

test('officer attestation rejects invalid local registry pin', function (): void {
    expect(fn () => app(OfficerAttestationService::class)->attest([
        'ceremony' => 'Friday Certification',
        'officer_code' => 'SIM-OFFICER-001',
        'officer_pin' => '000000',
        'signature_data' => lifecycleTestSignatureDataUri(),
        'stage' => Lifecycle::Certification,
        'statement' => 'Certification checkpoint reviewed.',
    ]))->toThrow(ValidationException::class);

    expect(app(ElectionStorage::class)->files('attestations'))->toHaveCount(0);
});

test('officer attestation rejects invalid signature artifact', function (): void {
    expect(fn () => app(OfficerAttestationService::class)->attest([
        'ceremony' => 'Friday Certification',
        'officer_code' => 'SIM-OFFICER-001',
        'officer_pin' => '123456',
        'signature_data' => 'data:image/png;base64,'.base64_encode('not a png'),
        'stage' => Lifecycle::Certification,
        'statement' => 'Certification checkpoint reviewed.',
    ]))->toThrow(ValidationException::class);

    expect(app(ElectionStorage::class)->files('attestations'))->toHaveCount(0)
        ->and(app(ElectionStorage::class)->files('attestation-signatures'))->toHaveCount(0);
});

test('officer registry can rotate a local pin with evidence artifact', function (): void {
    $rotation = app(OfficerRegistry::class)->rotatePin('SIM-OFFICER-001', '123456', '654321');

    expect($rotation['code_hash'])->toBe(hash('sha256', 'SIM-OFFICER-001'))
        ->and($rotation['artifact_path'])->toBeReadableFile()
        ->and(app(OfficerRegistry::class)->verify('SIM-OFFICER-001', '123456'))->toBeNull()
        ->and(app(OfficerRegistry::class)->verify('SIM-OFFICER-001', '654321'))->not->toBeNull()
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type'))->toContain('officer.pin_rotated');
});

test('ballot finalization creates deterministic qr payload and print artifact', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana', 'council-cora'],
    ], 'test-ballot-001');
    $job = app(BallotPrinter::class)->print($payload);

    expect($payload['payload_hash'])->toBeString()
        ->and($payload['qr_payload'])->toBeString()
        ->and($payload['qr_artifact_path'])->toBeString()
        ->and(file_exists($payload['qr_artifact_path']))->toBeTrue()
        ->and($job['status'])->toBe('printed')
        ->and(file_exists($job['artifact_path']))->toBeTrue()
        ->and(file_exists($job['pdf_artifact_path']))->toBeTrue()
        ->and(file_get_contents($job['pdf_artifact_path']))->toContain('%PDF-1.4')
        ->and(file_get_contents($job['pdf_artifact_path']))->toContain('Official Simulation Ballot')
        ->and(file_get_contents($job['pdf_artifact_path']))->toContain('Alternative Election System - Simulation Evidence Artifact')
        ->and(file_get_contents($job['pdf_artifact_path']))->toContain('Paper ballots remain the legal source of truth.')
        ->and(file_get_contents($job['pdf_artifact_path']))->toContain('/BaseFont /Courier')
        ->and(file_get_contents($job['pdf_artifact_path']))->toContain('/Subtype /Image')
        ->and(file_get_contents($job['pdf_artifact_path']))->toContain('/BallotQr')
        ->and(file_get_contents($job['pdf_artifact_path']))->toContain('1. Ada Santos');
});

test('paper ballot ledger reconciles issuance printing spoilage and deposit', function (): void {
    app(ActivateSamplePackage::class)->handle();
    app(PrecinctSetupService::class)->record(config('election.simulation.precinct_setup'));

    $first = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana'],
    ], 'paper-ballot-001');
    app(BallotPrinter::class)->print($first);
    app(SpoilBallot::class)->handle($first['payload_hash'], 'printer streak');

    $second = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-cora'],
    ], 'paper-ballot-002');
    app(BallotPrinter::class)->print($second);
    app(CountingService::class)->accept($second['qr_payload']);

    $summary = app(PaperBallotLedger::class)->summary();

    expect($first['paper_ballot_serial'])->toBe('0421-A-000001')
        ->and($second['paper_ballot_serial'])->toBe('0421-A-000002')
        ->and($summary['total_stock'])->toBe(1000)
        ->and($summary['issued'])->toBe(2)
        ->and($summary['spoiled'])->toBe(1)
        ->and($summary['deposited'])->toBe(1)
        ->and($summary['unused'])->toBe(998)
        ->and($summary['balanced'])->toBeTrue()
        ->and(app(ElectionStorage::class)->files('paper-ballot-ledger'))->toHaveCount(6);
});

test('cups ballot printer submits generated artifact when configured', function (): void {
    config()->set('election.devices.printer.driver', 'cups');
    config()->set('election.devices.printer.cups.name', 'Precinct_Printer');
    config()->set('election.devices.printer.cups.timeout', 4);
    writePassingCupsCertification('Precinct_Printer');
    Process::fake([
        '*' => Process::result('request id is Precinct_Printer-17 (1 file)'),
    ]);

    try {
        app(ActivateSamplePackage::class)->handle();

        $payload = app(BallotPayloadService::class)->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'test-ballot-cups');
        $job = app(BallotPrinter::class)->print($payload);
        $record = app(ElectionStorage::class)->readJson('print-jobs/test-ballot-cups.json');
        $events = app(ActivityJournal::class)->entries();

        expect($job['printer'])->toBe('cups')
            ->and($job['status'])->toBe('submitted')
            ->and($job['printer_name'])->toBe('Precinct_Printer')
            ->and($job['cups_output'])->toContain('Precinct_Printer-17')
            ->and(file_exists($job['cups_artifact_path']))->toBeTrue()
            ->and($record['status'])->toBe('submitted')
            ->and(collect($events)->pluck('event_type'))->toContain('ballot.print_submitted');

        Process::assertRan(fn ($process): bool => $process->command === [
            'lp',
            '-d',
            'Precinct_Printer',
            '-t',
            'AES Ballot test-ballot-cups',
            $job['cups_artifact_path'],
        ]);
    } finally {
        config()->set('election.devices.printer.driver', 'file');
    }
});

test('cups ballot printer records failed submissions without losing artifacts', function (): void {
    config()->set('election.devices.printer.driver', 'cups');
    config()->set('election.devices.printer.cups.name', 'Precinct_Printer');
    writePassingCupsCertification('Precinct_Printer');
    Process::fake([
        '*' => Process::result(errorOutput: 'printer is stopped', exitCode: 1),
    ]);

    try {
        app(ActivateSamplePackage::class)->handle();

        $payload = app(BallotPayloadService::class)->finalize([
            'president' => ['pres-grace'],
            'mayor' => ['mayor-jose'],
            'council' => ['council-ben'],
        ], 'test-ballot-cups-failed');
        $job = app(BallotPrinter::class)->print($payload);
        $events = app(ActivityJournal::class)->entries();

        expect($job['printer'])->toBe('cups')
            ->and($job['status'])->toBe('failed')
            ->and($job['cups_exit_code'])->toBe(1)
            ->and($job['cups_output'])->toContain('printer is stopped')
            ->and(file_exists($job['artifact_path']))->toBeTrue()
            ->and(file_exists($job['pdf_artifact_path']))->toBeTrue()
            ->and(collect($events)->pluck('event_type'))->toContain('ballot.print_failed');
    } finally {
        config()->set('election.devices.printer.driver', 'file');
    }
});

test('cups ballot printer requires passing device certification before submission', function (): void {
    config()->set('election.devices.printer.driver', 'cups');
    config()->set('election.devices.printer.cups.name', 'Precinct_Printer');
    Process::fake();

    try {
        app(ActivateSamplePackage::class)->handle();

        $payload = app(BallotPayloadService::class)->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'test-ballot-cups-uncertified');

        expect(fn () => app(BallotPrinter::class)->print($payload))
            ->toThrow(PrinterCertificationRequired::class);

        Process::assertNothingRan();
        expect(app(ElectionStorage::class)->files('print-jobs'))->toHaveCount(0);
    } finally {
        config()->set('election.devices.printer.driver', 'file');
    }
});

test('rendered standards compliant qr artifact decodes to the finalized ballot payload', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana'],
    ], 'test-ballot-qr');
    $png = file_get_contents($payload['qr_artifact_path']);

    expect($png)->toStartWith("\x89PNG")
        ->and(app(StandardQrCode::class)->decodePngFile($payload['qr_artifact_path']))->toBe($payload['qr_payload']);

    $accepted = app(CountingService::class)->accept($png);

    expect($accepted['status'])->toBe('accepted')
        ->and($accepted['payload_hash'])->toBe($payload['payload_hash']);
});

test('counting appends accepted files and tally is generated from accepted records', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana'],
    ], 'test-ballot-002');

    $accepted = app(CountingService::class)->accept($payload['qr_payload']);
    $duplicate = app(CountingService::class)->accept($payload['qr_payload']);
    $tally = app(CountingService::class)->tally();

    expect($accepted['status'])->toBe('accepted')
        ->and($duplicate['status'])->toBe('rejected')
        ->and($tally['accepted_ballots'])->toBe(1)
        ->and($tally['tally']['president']['pres-ada'])->toBe(1)
        ->and(app(ElectionStorage::class)->files('counting/accepted'))->toHaveCount(1);
});

test('manual scanner captures payload before counting', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana'],
    ], 'test-ballot-manual-scan');

    $scan = app(BallotScanner::class)->scan("  {$payload['qr_payload']}  ");
    $accepted = app(CountingService::class)->accept($scan['payload']);
    $events = app(ActivityJournal::class)->entries();

    expect($scan['adapter'])->toBe('manual-payload')
        ->and($accepted['status'])->toBe('accepted')
        ->and(collect($events)->pluck('event_type'))->toContain('ballot.scan_captured');
});

test('handheld scanner normalizes keyboard wedge payload before counting', function (): void {
    config()->set('election.devices.scanner.driver', 'handheld');
    config()->set('election.devices.scanner.handheld.name', 'USB Scanner 1');

    try {
        app(ActivateSamplePackage::class)->handle();

        $payload = app(BallotPayloadService::class)->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'test-ballot-handheld-scan');

        $scan = app(BallotScanner::class)->scan("AES-SCAN:\r\n{$payload['qr_payload']}\t");
        $accepted = app(CountingService::class)->accept($scan['payload']);

        expect($scan['adapter'])->toBe('handheld-keyboard-wedge')
            ->and($scan['payload'])->toBe($payload['qr_payload'])
            ->and($accepted['status'])->toBe('accepted');
    } finally {
        config()->set('election.devices.scanner.driver', 'manual');
    }
});

test('camera scanner decodes qr png data uri before counting', function (): void {
    config()->set('election.devices.scanner.driver', 'camera');
    config()->set('election.devices.scanner.camera.name', 'Precinct Camera 1');

    try {
        app(ActivateSamplePackage::class)->handle();

        $payload = app(BallotPayloadService::class)->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'test-ballot-camera-scan');
        $imagePayload = 'data:image/png;base64,'.base64_encode(file_get_contents($payload['qr_artifact_path']));

        $scan = app(BallotScanner::class)->scan($imagePayload);
        $accepted = app(CountingService::class)->accept($scan['payload']);

        expect($scan['adapter'])->toBe('camera-image')
            ->and($scan['payload'])->toBe($payload['qr_payload'])
            ->and($accepted['status'])->toBe('accepted');
    } finally {
        config()->set('election.devices.scanner.driver', 'manual');
    }
});

test('spoiled ballot is rejected during counting', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-grace'],
        'mayor' => ['mayor-jose'],
        'council' => ['council-ben'],
    ], 'test-ballot-spoiled');
    app(SpoilBallot::class)->handle($payload['payload_hash']);

    $record = app(CountingService::class)->accept($payload['qr_payload']);

    expect($record['status'])->toBe('rejected')
        ->and($record['reason'])->toContain('spoiled');
});

test('election return artifact is generated from tally', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-cora'],
    ], 'test-ballot-003');
    app(CountingService::class)->accept($payload['qr_payload']);
    $return = app(ElectionReturnService::class)->generate(app(CountingService::class)->tally());

    expect($return['return_hash'])->toBeString()
        ->and(app(ElectionStorage::class)->readJson('returns/0421-A-return.json')['return_hash'])->toBe($return['return_hash'])
        ->and(file_get_contents(app(ElectionStorage::class)->path('returns/0421-A-return.pdf')))->toContain('%PDF-1.4')
        ->and(file_get_contents(app(ElectionStorage::class)->path('returns/0421-A-return.pdf')))->toContain('Election Return')
        ->and(file_get_contents(app(ElectionStorage::class)->path('returns/0421-A-return.pdf')))->toContain('Alternative Election System - Simulation Evidence Artifact')
        ->and(file_get_contents(app(ElectionStorage::class)->path('returns/0421-A-return.pdf')))->toContain('Paper ballots remain the legal source of truth.')
        ->and(file_get_contents(app(ElectionStorage::class)->path('returns/0421-A-return.pdf')))->toContain('Ada Santos')
        ->and(file_get_contents(app(ElectionStorage::class)->path('returns/0421-A-return.pdf')))->toContain('ELECTORAL BOARD CERTIFICATION');
});

test('election return legal evidence artifact is generated from return', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $acceptedPayload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana'],
    ], 'test-ballot-legal-evidence-accepted');
    $rejectedPayload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-grace'],
        'mayor' => ['mayor-jose'],
        'council' => ['council-ben'],
    ], 'test-ballot-legal-evidence-rejected');
    app(SpoilBallot::class)->handle($rejectedPayload['payload_hash']);

    $ceremonies = app(CeremonyActions::class);
    $ceremonies->openPrecinct('Scenario Officer');
    $ceremonies->openPolls('Scenario Officer');
    $ceremonies->openPolls('Scenario Officer');
    $ceremonies->closePolls('Scenario Officer');
    $ceremonies->startCounting();

    app(CountingService::class)->accept($acceptedPayload['qr_payload']);
    app(CountingService::class)->accept($rejectedPayload['qr_payload']);
    $tally = app(CountingService::class)->tally();

    $ceremonies->moveToReturns();
    $return = app(ElectionReturnService::class)->generate($tally);
    $evidence = app(ElectionStorage::class)->readJson('returns/election-return-legal-evidence.json');

    expect($return['return_hash'])->toBe($evidence['return_hash'])
        ->and($evidence['schema_version'])->toBe('election-return-legal-evidence-1')
        ->and($evidence['evidence_profile'])->toBe('legal-election-return-v1')
        ->and($evidence['passed'])->toBeTrue()
        ->and($evidence['counts_match'])->toBeTrue()
        ->and($evidence['accepted_ballots'])->toBe(1)
        ->and($evidence['rejected_ballots'])->toBe(1)
        ->and($evidence['tally_hash'])->toBe($return['tally_hash'])
        ->and($evidence['return_hash'])->toBe($return['return_hash'])
        ->and(file_get_contents($evidence['artifact_path']))->toContain('"return_hash"');
});

test('election return legal artifact scenario runs deterministically', function (): void {
    $this->artisan('election:scenario election-return-legal-artifact')
        ->expectsOutput('Scenario election-return-legal-artifact passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-election-return-legal-artifact')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $report = app(ElectionStorage::class)->readJson('scenarios/election-return-legal-artifact-report.json');
    $artifactPath = app(ElectionStorage::class)->path('returns/election-return-legal-evidence.json');

    expect($report['scenario'])->toBe('election-return-legal-artifact')
        ->and($report['passed'])->toBeTrue()
        ->and($report['precinct_id'])->toBe('39010001')
        ->and($report['accepted_ballots'])->toBe(1)
        ->and($report['rejected_ballots'])->toBe(1)
        ->and($report['election_return_legal_evidence_path'])->toBe($artifactPath)
        ->and($report['election_return_legal_evidence_hash'])->toBeString()
        ->and(file_exists($artifactPath))->toBeTrue();
});

test('election return copy distribution scenario runs deterministically', function (): void {
    $this->artisan('election:scenario election-return-copy-distribution')
        ->expectsOutput('Scenario election-return-copy-distribution passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-election-return-copy-distribution')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $report = app(ElectionStorage::class)->readJson('scenarios/election-return-copy-distribution-report.json');
    $artifactPath = app(ElectionStorage::class)->path('returns/39010001-copy-distribution.json');

    expect($report['scenario'])->toBe('election-return-copy-distribution')
        ->and($report['passed'])->toBeTrue()
        ->and($report['precinct_id'])->toBe('39010001')
        ->and($report['copy_count'])->toBe(3)
        ->and($report['required_copy_count'])->toBe(2)
        ->and($report['distribution_posting_status'])->toBe('completed')
        ->and($report['copy_distribution_artifact_path'])->toBe($artifactPath)
        ->and($report['copy_distribution_hash'])->toBeString()
        ->and($report['run_id'])->toBeString()
        ->and(file_exists($artifactPath))->toBeTrue();
});

test('delivery package scenario command succeeds', function (): void {
    $this->artisan('election:scenario delivery-package')
        ->expectsOutput('Scenario delivery-package passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-delivery-package')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $report = app(ElectionStorage::class)->readJson('scenarios/delivery-package-report.json');
    $package = app(ElectionStorage::class)->readJson('transmission/delivery-package.json');
    $distribution = app(ElectionStorage::class)->readJson('returns/39010001-copy-distribution.json');

    expect($report['scenario'])->toBe('delivery-package')
        ->and($report['passed'])->toBeTrue()
        ->and($report['run_id'])->toBeString()
        ->and($report['precinct_id'])->toBe('39010001')
        ->and($report['accepted_ballots'])->toBe(1)
        ->and($report['rejected_ballots'])->toBe(1)
        ->and($report['distribution_hash'])->toBe($distribution['distribution_hash'])
        ->and($report['delivery_package_hash'])->toBe($package['delivery_package_hash'])
        ->and($report['required_artifacts_present'])->toBeTrue()
        ->and($report['artifact_count'])->toBeGreaterThan(0)
        ->and($report['transmission_id'])->toBe($package['transmission']['transmission_id'] ?? null)
        ->and($report['transmission_hash'])->toBe($package['transmission']['transmission_hash'] ?? null)
        ->and(file_exists((string) ($report['delivery_package_path'] ?? '')))->toBeTrue();
});

test('manual-handoff scenario command succeeds', function (): void {
    $this->artisan('election:scenario manual-handoff')
        ->expectsOutput('Scenario manual-handoff passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-manual-handoff')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $report = $storage->readJson('scenarios/manual-handoff-report.json');
    $officer = file_exists((string) ($report['officer_verification_path'] ?? '')) ? json_decode((string) file_get_contents((string) $report['officer_verification_path']), true) : [];
    $recipient = file_exists((string) ($report['recipient_verification_path'] ?? '')) ? json_decode((string) file_get_contents((string) $report['recipient_verification_path']), true) : [];

    expect($report['scenario'])->toBe('manual-handoff')
        ->and($report['passed'])->toBeTrue()
        ->and($report['run_id'])->toBe('20260508-080000-39010001-manual-handoff')
        ->and($report['officer_verification_id'])->toBe($officer['verification_id'] ?? null)
        ->and($report['recipient_verification_id'])->toBe($recipient['verification_id'] ?? null)
        ->and($report['officer_verification_hash'])->toBe($officer['verification_hash'] ?? null)
        ->and($report['recipient_verification_hash'])->toBe($recipient['verification_hash'] ?? null)
        ->and($report['officer_verification_path'])->toBeString()
        ->and($report['recipient_verification_path'])->toBeString()
        ->and(file_exists($report['officer_verification_path']))->toBeTrue()
        ->and(file_exists($report['recipient_verification_path']))->toBeTrue();
});

test('delivery-receipt scenario command succeeds', function (): void {
    $this->artisan('election:scenario delivery-receipt')
        ->expectsOutput('Scenario delivery-receipt passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-delivery-receipt')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $report = $storage->readJson('scenarios/delivery-receipt-report.json');
    $receipt = file_exists((string) ($report['delivery_receipt_path'] ?? '')) ? json_decode((string) file_get_contents((string) $report['delivery_receipt_path']), true) : [];
    $package = file_exists((string) ($report['delivery_package_path'] ?? '')) ? json_decode((string) file_get_contents((string) $report['delivery_package_path']), true) : [];
    $officer = file_exists((string) ($report['officer_verification_path'] ?? '')) ? json_decode((string) file_get_contents((string) $report['officer_verification_path']), true) : [];
    $recipient = file_exists((string) ($report['recipient_verification_path'] ?? '')) ? json_decode((string) file_get_contents((string) $report['recipient_verification_path']), true) : [];

    expect($report['scenario'])->toBe('delivery-receipt')
        ->and($report['passed'])->toBeTrue()
        ->and($report['run_id'])->toBe('20260508-080000-39010001-delivery-receipt')
        ->and($report['lifecycle_stage_after_receipt'])->toBe(Lifecycle::FinalBackup)
        ->and($report['delivery_receipt_id'])->toBe($receipt['delivery_receipt_id'] ?? null)
        ->and($report['delivery_receipt_hash'])->toBe($receipt['delivery_receipt_hash'] ?? null)
        ->and($report['delivery_package_hash'])->toBe($package['delivery_package_hash'] ?? null)
        ->and($report['officer_verification_id'])->toBe($officer['verification_id'] ?? null)
        ->and($report['recipient_verification_id'])->toBe($recipient['verification_id'] ?? null)
        ->and($report['delivery_driver'])->toBe('manual')
        ->and(file_exists((string) ($report['delivery_receipt_path'] ?? '')))->toBeTrue()
        ->and(file_exists((string) ($report['delivery_package_path'] ?? '')))->toBeTrue()
        ->and(file_exists($report['officer_verification_path'] ?? ''))->toBeTrue()
        ->and(file_exists($report['recipient_verification_path'] ?? ''))->toBeTrue();
});

test('final-backup scenario command succeeds', function (): void {
    $this->artisan('election:scenario final-backup')
        ->expectsOutput('Scenario final-backup passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-final-backup')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $report = $storage->readJson('scenarios/final-backup-report.json');
    $finalBackup = file_exists((string) ($report['final_backup_path'] ?? '')) ? json_decode((string) file_get_contents((string) $report['final_backup_path']), true) : [];
    $manifest = file_exists((string) ($report['final_backup_manifest_path'] ?? '')) ? json_decode((string) file_get_contents((string) $report['final_backup_manifest_path']), true) : [];
    $package = file_exists((string) ($report['delivery_package_path'] ?? '')) ? json_decode((string) file_get_contents((string) $report['delivery_package_path']), true) : [];
    $receipt = file_exists((string) ($report['delivery_receipt_path'] ?? '')) ? json_decode((string) file_get_contents((string) $report['delivery_receipt_path']), true) : [];
    $transmission = file_exists((string) ($report['transmission_path'] ?? '')) ? json_decode((string) file_get_contents((string) $report['transmission_path']), true) : [];

    expect($report['scenario'])->toBe('final-backup')
        ->and($report['passed'])->toBeTrue()
        ->and($report['run_id'])->toBe('20260508-080000-39010001-final-backup')
        ->and($report['final_backup_stage_after'])->toBe(Lifecycle::FinalBackup)
        ->and($report['delivery_package_hash'])->toBe($package['delivery_package_hash'] ?? null)
        ->and($report['delivery_receipt_id'])->toBe($receipt['delivery_receipt_id'] ?? null)
        ->and($report['delivery_receipt_hash'])->toBe($receipt['delivery_receipt_hash'] ?? null)
        ->and($report['transmission_id'])->toBe($transmission['transmission_id'] ?? null)
        ->and($report['final_backup_id'])->toBe($finalBackup['backup_id'] ?? null)
        ->and($report['final_backup_hash'])->toBe($finalBackup['final_backup_hash'] ?? null)
        ->and($manifest['schema_version'])->toBe('precinct-evidence-manifest-1')
        ->and(file_exists((string) ($report['final_backup_path'] ?? '')))->toBeTrue()
        ->and(file_exists((string) ($report['final_backup_manifest_path'] ?? '')))->toBeTrue();
});

test('custody turnover scenario command succeeds', function (): void {
    $this->artisan('election:scenario custody-turnover')
        ->expectsOutput('Scenario custody-turnover passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-custody-turnover')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $report = $storage->readJson('scenarios/custody-turnover-report.json');
    $custodyTurnover = file_exists((string) ($report['custody_turnover_path'] ?? '')) ? json_decode((string) file_get_contents((string) $report['custody_turnover_path']), true) : [];
    $custodyRecord = file_exists((string) $storage->path('custody/custody-record.json')) ? json_decode((string) file_get_contents($storage->path('custody/custody-record.json')), true) : [];
    $receipt = file_exists((string) ($report['delivery_receipt_path'] ?? '')) ? json_decode((string) file_get_contents((string) $report['delivery_receipt_path']), true) : [];
    $finalBackup = file_exists((string) ($report['final_backup_path'] ?? '')) ? json_decode((string) file_get_contents((string) $report['final_backup_path']), true) : [];

    expect($report['scenario'])->toBe('custody-turnover')
        ->and($report['passed'])->toBeTrue()
        ->and($report['run_id'])->toBe('20260508-080000-39010001-custody-turnover')
        ->and($report['lifecycle_stage_after_turnover'])->toBe(Lifecycle::Custody)
        ->and($report['turnover_stage'])->toBe(Lifecycle::FinalBackup)
        ->and($report['accepted_ballots'])->toBe(1)
        ->and($report['rejected_ballots'])->toBe(1)
        ->and($report['custody_id'])->toBe($custodyRecord['custody_id'] ?? null)
        ->and($report['custody_turnover_id'])->toBe($custodyTurnover['custody_turnover_id'] ?? null)
        ->and($report['custody_turnover_hash'])->toBe($custodyTurnover['custody_turnover_hash'] ?? null)
        ->and($report['turnover_artifact_count'])->toBe(5)
        ->and($report['delivery_receipt_id'])->toBe($receipt['delivery_receipt_id'] ?? null)
        ->and($report['final_backup_id'])->toBe($finalBackup['backup_id'] ?? null)
        ->and(file_exists((string) ($report['custody_turnover_path'] ?? '')))->toBeTrue()
        ->and(file_exists((string) ($report['delivery_package_path'] ?? '')))->toBeTrue()
        ->and(file_exists((string) ($report['delivery_receipt_path'] ?? '')))->toBeTrue()
        ->and(file_exists((string) ($report['final_backup_path'] ?? '')))->toBeTrue();
});

test('audit reconciliation baseline scenario command succeeds', function (): void {
    $this->artisan('election:scenario audit-reconciliation-baseline')
        ->expectsOutput('Scenario audit-reconciliation-baseline passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-audit-reconciliation-baseline')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $report = $storage->readJson('scenarios/audit-reconciliation-baseline-report.json');
    $reconciliation = $storage->readJson('diagnostics/audit-reconciliation-baseline.json');
    $runSummary = $storage->readJson('run-summary.json');
    $runArtifactIndex = $storage->readJson('artifact-index.json');

    expect($report['scenario'])->toBe('audit-reconciliation-baseline')
        ->and($report['passed'])->toBeTrue()
        ->and($report['run_id'])->toBe('20260508-080000-39010001-audit-reconciliation-baseline')
        ->and($report['precinct_id'])->toBe('39010001')
        ->and($report['reconciliation_complete'])->toBeTrue()
        ->and($report['reconciliation_ready'])->toBeTrue()
        ->and($report['checks_total'])->toBeGreaterThan(0)
        ->and($report['checks_passed'])->toBe($report['checks_total'])
        ->and($report['artifacts_expected'])->toBe(8)
        ->and($report['artifacts_found'])->toBe(8)
        ->and($report['artifact_catalog_count'])->toBe(8)
        ->and($report['run_summary_hash'])->toBe($runSummary['run_hash'] ?? null)
        ->and($report['run_artifact_index_hash'])->toBe($runArtifactIndex['artifact_index_hash'] ?? null)
        ->and($report['journal_sequence'])->toBeGreaterThan(0)
        ->and($report['audit_reconciliation_hash'])->toBe($reconciliation['audit_reconciliation_hash'] ?? null)
        ->and($report['artifact_path'])->toBe($storage->path('diagnostics/audit-reconciliation-baseline.json'))
        ->and(file_exists($report['artifact_path']))->toBeTrue()
        ->and(collect($report['checks'])->every('passed'))->toBeTrue();
});

test('full demo scenario command succeeds', function (): void {
    $this->artisan('election:scenario full-demo')
        ->expectsOutput('Scenario full-demo passed.')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $report = app(ElectionStorage::class)->readJson('scenarios/full-demo-report.json');
    $configuration = app(ElectionStorage::class)->readJson('runtime/active-precinct.json');
    $returnText = file_get_contents(app(ElectionStorage::class)->path('returns/39010001-return.txt'));
    $tallySheet = app(ElectionStorage::class)->readText('runtime/tally-sheet.txt');
    $printedBallot = file_get_contents($report['print_job']['artifact_path']);
    $journal = collect(app(ActivityJournal::class)->entries());
    $stages = $journal
        ->where('event_type', 'lifecycle.stage_set')
        ->pluck('payload.stage');
    $eventTypes = $journal->pluck('event_type');

    expect($report['passed'])->toBeTrue()
        ->and($report['precinct_id'])->toBe('39010001')
        ->and($report['lifecycle_stage'])->toBe(Lifecycle::Audit)
        ->and(collect($report['checks'])->every(fn (bool $passed): bool => $passed))->toBeTrue()
        ->and($report['checks']['sealing_passed'])->toBeTrue()
        ->and($report['checks']['counting_reconciliation_passed'])->toBeTrue()
        ->and($report['checks']['return_dual_approval_passed'])->toBeTrue()
        ->and($report['checks']['final_backup_complete'])->toBeTrue()
        ->and($report['checks']['custody_sealed'])->toBeTrue()
        ->and($report['checks']['audit_reconciliation_complete'])->toBeTrue()
        ->and($report['checks']['archive_verification_passed'])->toBeTrue()
        ->and($report['checks']['primary_artifacts_exist'])->toBeTrue()
        ->and($report['pop_import']['mapping_profile'])->toBe('comelec-pop-2025-nle')
        ->and($report['pop_import']['row_count'])->toBe(93629)
        ->and($report['pop_import']['clustered_precinct'])->toBe('39010001')
        ->and($report['pop_import']['precinct_location']['polling_place'])->toBe('ISABELO DELOS REYES ELEMENTARY SCHOOL')
        ->and($configuration['precinct_id'])->toBe('39010001')
        ->and($configuration['ballot_style_id'])->toBe('BS-2025NLE-39010001')
        ->and($report['ballot_definition']['contest_count'])->toBe(6)
        ->and($report['ballot_definition']['candidate_count'])->toBe(387)
        ->and(collect($configuration['contests'])->pluck('office')->contains('PRESIDENT'))->toBeFalse()
        ->and($printedBallot)->toContain('SENATOR - PHILIPPINES')
        ->and($printedBallot)->toContain('1. ABALOS, BENHUR (PFP)')
        ->and($tallySheet)->toContain('PARTY LIST - PHILIPPINES')
        ->and($returnText)->toContain('MAYOR - NCR - CITY OF MANILA')
        ->and($returnText)->toContain('DOMAGOSO, ISKO MORENO')
        ->and($report['accepted_ballots'])->toBe(1)
        ->and($report['rejected_ballots'])->toBe(1)
        ->and($report['attestation_hashes'])->toHaveCount(2)
        ->and($report['statistics']['paper_ballots_issued'])->toBe(2)
        ->and($report['statistics']['paper_ballots_spoiled'])->toBe(1)
        ->and($report['statistics']['paper_ballots_deposited'])->toBe(1)
        ->and($report['statistics']['primary_artifacts'])->toBeGreaterThanOrEqual(25)
        ->and($stages)->toContain(
            Lifecycle::Certification,
            Lifecycle::OpenPrecinct,
            Lifecycle::OpenPolls,
            Lifecycle::Voting,
            Lifecycle::ClosePolls,
            Lifecycle::Counting,
            Lifecycle::ElectionReturn,
            Lifecycle::Transmission,
            Lifecycle::FinalBackup,
            Lifecycle::Custody,
            Lifecycle::ClosePrecinct,
            Lifecycle::Audit,
        )
        ->and($eventTypes)->toContain(
            'certification.sealed',
            'precinct.opened',
            'polls.opened',
            'polls.closed',
            'counting.started',
            'return.generated',
            'return.approved',
            'transmission.completed',
            'transmission.final_backup',
            'custody.recorded',
            'precinct.closed',
            'audit.started',
            'evidence_bundle.archive_verification_passed',
        );

    foreach ($report['artifacts'] as $artifact) {
        expect($artifact)->toBeReadableFile();
    }
});

test('legal scenario suite command succeeds', function (): void {
    $this->artisan('election:scenario legal-suite')
        ->expectsOutput('Scenario legal-suite passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-legal-suite')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $report = app(ElectionStorage::class)->readJson('scenarios/legal-suite-report.json');

    expect($report['scenario'])->toBe('legal-suite')
        ->and($report['suite'])->toBe('legal')
        ->and($report['harness_stages']['scope'])->toBe('legal baseline')
        ->and($report['sub_scenarios'])->toBe(['friday-certification', 'full-demo', 'eb-role-baseline']);
});

test('legal scenario suite creates an evidence reference baseline artifact', function (): void {
    $this->artisan('election:scenario legal-suite')
        ->expectsOutput('Scenario legal-suite passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-legal-suite')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $report = $storage->readJson('scenarios/legal-suite-report.json');
    $baselinePath = $report['evidence_reference_baseline']['artifact_path'];

    expect($report['evidence_reference_baseline']['artifact_path'])->toBe($baselinePath)
        ->and($report['evidence_reference_baseline']['baseline_hash'])->toBeString()
        ->and($report['evidence_reference_baseline']['artifact_reference_count'])->toBeGreaterThan(10)
        ->and($report['evidence_reference_baseline']['missing_required_reference_count'])->toBeGreaterThanOrEqual(0)
        ->and(file_exists($baselinePath))->toBeTrue();

    $baseline = $storage->readJson('diagnostics/evidence-reference-baseline.json');

    expect($baseline['schema_version'])->toBe('evidence-reference-baseline-1')
        ->and($baseline['run_id'])->toBe('20260508-080000-39010001-legal-suite')
        ->and($baseline['precinct_id'])->toBe('39010001')
        ->and($baseline['artifact_reference_count'])->toBe(count($baseline['artifact_references']))
        ->and($baseline['missing_required_references'])->toBeArray();

    $minutesPath = $report['official_minutes_baseline']['artifact_path'];
    $officialMinutes = $storage->readJson('diagnostics/official-minutes-baseline.json');

    expect($officialMinutes['schema_version'])->toBe('official-minutes-baseline-1')
        ->and($officialMinutes['run_id'])->toBe('20260508-080000-39010001-legal-suite')
        ->and($officialMinutes['precinct_id'])->toBe('39010001')
        ->and($officialMinutes['minute_count'])->toBeGreaterThan(0)
        ->and($officialMinutes['source_journal_event_count'])->toBeGreaterThan(0)
        ->and($officialMinutes['source_attestation_count'])->toBeGreaterThan(0)
        ->and(in_array($officialMinutes['minutes'][0]['source_type'], ['attestation', 'activity_journal']))->toBeTrue()
        ->and($report['official_minutes_baseline']['artifact_path'])->toBe($minutesPath)
        ->and($report['official_minutes_baseline']['minute_count'])->toBe($officialMinutes['minute_count'])
        ->and(file_exists($minutesPath))->toBeTrue();
});

test('legal scenario suite includes electoral board role baseline artifact', function (): void {
    $this->artisan('election:scenario legal-suite')
        ->expectsOutput('Scenario legal-suite passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-legal-suite')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $report = $storage->readJson('scenarios/legal-suite-report.json');

    expect($report['sub_scenarios'])->toContain('eb-role-baseline')
        ->and($report['electoral_board_baseline']['required_role_count'])->toBe(3)
        ->and($report['electoral_board_baseline']['required_roles_present'])->toBe(3)
        ->and($report['electoral_board_baseline']['missing_required_role_count'])->toBe(0)
        ->and($report['electoral_board_baseline']['passed'])->toBeTrue()
        ->and($report['electoral_board_baseline']['artifact_path'])->toBeString()
        ->and(file_exists($report['electoral_board_baseline']['artifact_path']))->toBeTrue();
});

test('supply verification baseline scenario command succeeds', function (): void {
    app(ActivateSamplePackage::class)->handle();
    app(DeviceCertificationService::class)->run();

    $this->artisan('election:scenario supply-verification-baseline')
        ->expectsOutput('Scenario supply-verification-baseline passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-supply-verification-baseline')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $report = $storage->readJson('scenarios/supply-verification-baseline-report.json');

    expect($report['scenario'])->toBe('supply-verification-baseline')
        ->and($report['passed'])->toBeTrue()
        ->and($report['required_supply_count'])->toBe(3)
        ->and($report['required_supplies_present'])->toBe(3)
        ->and($report['required_supply_missing_count'])->toBe(0)
        ->and($report['artifact_path'])->toBeString()
        ->and(file_exists($report['artifact_path']))->toBeTrue();
});

test('supply verification scenario creates supply verification baseline artifact', function (): void {
    app(ActivateSamplePackage::class)->handle();
    app(DeviceCertificationService::class)->run();

    $this->artisan('election:scenario supply-verification-baseline')
        ->expectsOutput('Scenario supply-verification-baseline passed.')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $scenarioReport = $storage->readJson('scenarios/supply-verification-baseline-report.json');
    $baseline = $storage->readJson('runtime/supply-verification-baseline.json');

    expect($scenarioReport['baseline_hash'])->toBe($baseline['baseline_hash'])
        ->and($scenarioReport['required_supply_count'])->toBe($baseline['required_supply_count'])
        ->and($scenarioReport['required_supply_missing_count'])->toBe($baseline['required_supply_missing_count']);
});

test('initialization report scenario command succeeds', function (): void {
    $this->artisan('election:scenario initialization-report')
        ->expectsOutput('Scenario initialization-report passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-initialization-report')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $report = $storage->readJson('scenarios/initialization-report-report.json');

    expect($report['scenario'])->toBe('initialization-report')
        ->and($report['passed'])->toBeTrue()
        ->and($report['run_id'])->toBe('20260508-080000-39010001-initialization-report')
        ->and($report['checks_total'])->toBe(5)
        ->and($report['checks_passed'])->toBe(5)
        ->and($report['artifact_path'])->toBeString()
        ->and(file_exists($report['artifact_path']))->toBeTrue();
});

test('initialization report scenario writes initialization report artifact', function (): void {
    $this->artisan('election:scenario initialization-report')
        ->expectsOutput('Scenario initialization-report passed.')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $scenarioReport = $storage->readJson('scenarios/initialization-report-report.json');
    $initializationReport = $storage->readJson('diagnostics/initialization-report.json');

    expect($scenarioReport['report_hash'])->toBe($initializationReport['report_hash'])
        ->and($scenarioReport['precinct_id'])->toBe($initializationReport['precinct_id'])
        ->and($scenarioReport['checks_total'])->toBe(count($initializationReport['checks']))
        ->and($scenarioReport['checks_passed'])->toBe(count(collect($initializationReport['checks'])->filter(fn (array $check): bool => (bool) $check['passed'])->all()));
});

test('open polls initialization report scenario command succeeds', function (): void {
    $this->artisan('election:scenario open-polls-initialization-report')
        ->expectsOutput('Scenario open-polls-initialization-report passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-open-polls-initialization-report')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $report = $storage->readJson('scenarios/open-polls-initialization-report-report.json');
    $openingInitialization = $storage->readJson('opening/initialization-report.json');
    $openingInitializationPath = $storage->path('opening/initialization-report.json');

    expect($report['scenario'])->toBe('open-polls-initialization-report')
        ->and($report['passed'])->toBeTrue()
        ->and($report['run_id'])->toBe('20260508-080000-39010001-open-polls-initialization-report')
        ->and($report['checks_total'])->toBe(5)
        ->and($report['checks_passed'])->toBe(5)
        ->and($report['artifact_path'])->toBe($openingInitializationPath)
        ->and($report['stage_after_open'])->toBe('open_polls')
        ->and(file_exists($report['artifact_path']))->toBeTrue();
});

test('open polls initialization scenario writes opening initialization report artifact', function (): void {
    $this->artisan('election:scenario open-polls-initialization-report')
        ->expectsOutput('Scenario open-polls-initialization-report passed.')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $scenarioReport = $storage->readJson('scenarios/open-polls-initialization-report-report.json');
    $openingInitialization = $storage->readJson('opening/initialization-report.json');
    $openingInitializationPath = $storage->path('opening/initialization-report.json');

    expect($scenarioReport['artifact_path'])->toBe($openingInitializationPath)
        ->and($scenarioReport['report_hash'])->toBe($openingInitialization['report_hash'])
        ->and($scenarioReport['checks_passed'])->toBe(count(collect($openingInitialization['checks'])->filter(fn (array $check): bool => (bool) $check['passed'])->all()))
        ->and(file_exists($openingInitializationPath))->toBeTrue();
});

test('eb-role-baseline scenario writes an electoral board role baseline artifact', function (): void {
    $this->artisan('election:scenario eb-role-baseline')
        ->expectsOutput('Scenario eb-role-baseline passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-eb-role-baseline')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $report = $storage->readJson('scenarios/eb-role-baseline-report.json');
    $baseline = $storage->readJson('runtime/electoral-board-baseline.json');
    $run = $storage->currentRun();

    expect($report['scenario'])->toBe('eb-role-baseline')
        ->and($report['passed'])->toBeTrue()
        ->and($report['required_role_count'])->toBe(3)
        ->and($report['required_roles_present'])->toBe(3)
        ->and($report['missing_required_role_count'])->toBe(0)
        ->and($report['run_id'])->toStartWith('20260508-080000')
        ->and($report['run_id'])->toBe('20260508-080000-39010001-eb-role-baseline')
        ->and($baseline['schema_version'])->toBe('electoral-board-baseline-1')
        ->and($run['run_id'])->toBe('20260508-080000-39010001-eb-role-baseline')
        ->and($baseline['required_roles'])->toHaveCount(3)
        ->and(file_exists($baseline['artifact_path'] ?? ''))->toBeTrue();
});

test('full demo scenario uses configurable pop import defaults', function (): void {
    config()->set('election.pop.source_path', resource_path('election/pop/2025NLE_POP.xlsx'));
    config()->set('election.pop.profile', 'comelec-pop-2025-nle');
    config()->set('election.pop.clustered_precinct', '39010001');

    $this->artisan('election:scenario full-demo')
        ->expectsOutput('Scenario full-demo passed.')
        ->assertSuccessful();

    $report = app(ElectionStorage::class)->readJson('scenarios/full-demo-report.json');

    expect($report['pop_import']['source_path'])->toBe(resource_path('election/pop/2025NLE_POP.xlsx'))
        ->and($report['pop_import']['mapping_profile'])->toBe('comelec-pop-2025-nle')
        ->and($report['pop_import']['clustered_precinct'])->toBe('39010001');
});

test('scenario command writes run first report folders', function (): void {
    $storage = app(ElectionStorage::class);

    $this->artisan('election:scenario friday-certification')
        ->expectsOutput('Scenario friday-certification passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-friday-certification')
        ->expectsOutputToContain('Run Folder: ')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $fridayRun = $storage->currentRun();

    expect($fridayRun['run_id'])->toBe('20260508-080000-39010001-friday-certification')
        ->and($fridayRun['run_path'].'/00-start-here')->toBeDirectory()
        ->and(glob($fridayRun['run_path'].'/00-start-here/*friday-certification*-report.json') ?: [])->not->toBeEmpty();

    $this->artisan('election:scenario full-demo')
        ->expectsOutput('Scenario full-demo passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-full-demo')
        ->expectsOutputToContain('Run Folder: ')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $fullDemoRun = $storage->currentRun();

    expect($fullDemoRun['run_id'])->toBe('20260508-080000-39010001-full-demo')
        ->and($fullDemoRun['run_path'].'/README.txt')->toBeReadableFile()
        ->and($fullDemoRun['run_path'].'/run-summary.txt')->toBeReadableFile()
        ->and($fullDemoRun['run_path'].'/artifact-index.json')->toBeReadableFile()
        ->and($storage->root().'/pointers/rehearsal.json')->toBeReadableFile()
        ->and($storage->root().'/LATEST_RUN.txt')->not->toBeFile()
        ->and(storage_path('app/election-scenario-reports'))->not->toBeDirectory()
        ->and(storage_path('app/election-scenario-artifacts'))->not->toBeDirectory()
        ->and($storage->readJson('scenarios/full-demo-report.json')['passed'])->toBeTrue();
});

test('fifty ballot field scenario reconciles paper, counting, return, and archive evidence', function (): void {
    $this->artisan('election:scenario field-50-ballots')
        ->expectsOutput('Scenario field-50-ballots passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-field-50-ballots')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $report = $storage->readJson('scenarios/field-50-ballots-report.json');
    $run = $storage->currentRun(ElectionRunType::Rehearsal);
    $summary = json_decode(file_get_contents($run['summary_report_path']), true, flags: JSON_THROW_ON_ERROR);
    $summaryText = file_get_contents($run['summary_report_text_path']);

    expect($report['passed'])->toBeTrue()
        ->and($report['precinct_id'])->toBe('39010001')
        ->and($report['lifecycle_stage'])->toBe(Lifecycle::Audit)
        ->and($report['statistics']['voters_served'])->toBe(50)
        ->and($report['statistics']['paper_ballots_issued'])->toBe(52)
        ->and($report['statistics']['paper_ballots_spoiled'])->toBe(2)
        ->and($report['statistics']['paper_ballots_deposited'])->toBe(50)
        ->and($report['statistics']['accepted_ballots'])->toBe(50)
        ->and($report['statistics']['rejected_scans'])->toBe(1)
        ->and($report['statistics']['adjudicated_rejections'])->toBe(1)
        ->and($report['statistics']['physical_ballots'])->toBe(50)
        ->and($report['checks']['restart_resume_status'])->toBe('resume-allowed')
        ->and($report['checks']['paper_ballot_accounting_balanced'])->toBeTrue()
        ->and($report['checks']['counting_reconciliation_passed'])->toBeTrue()
        ->and($report['checks']['return_dual_approval_passed'])->toBeTrue()
        ->and($report['checks']['audit_reconciliation_complete'])->toBeTrue()
        ->and($report['checks']['archive_verification_passed'])->toBeTrue()
        ->and($run['run_path'].'/04-voting/ballots/field-ballot-050.pdf')->toBeReadableFile()
        ->and($run['run_path'].'/05-closing-of-polls/close-polls-legal-evidence.json')->toBeReadableFile()
        ->and($run['run_path'].'/06-counting-and-tally/counting-legal-evidence.json')->toBeReadableFile()
        ->and($run['run_path'].'/06-counting-and-tally/accepted')->toBeDirectory()
        ->and(count($storage->files('counting/accepted')))->toBe(50)
        ->and($report['artifacts']['election_return'])->toBeReadableFile()
        ->and($report['artifacts']['evidence_archive'])->toBeReadableFile()
        ->and($report['artifacts']['archive_verification'])->toBeReadableFile()
        ->and($summary['scenario_statistics']['voters_served'])->toBe(50)
        ->and($summary['scenario_checks']['archive_verification_passed'])->toBeTrue()
        ->and($summary['scenario_artifacts']['election_return'])->toBe($report['artifacts']['election_return'])
        ->and($summaryText)->toContain('Voters served: 50')
        ->and($summaryText)->toContain('Archive verification passed: yes')
        ->and($summaryText)->toContain('Election return: '.$report['artifacts']['election_return']);
});

test('scenario runner preserves a locked browser walkthrough before starting a new rehearsal', function (): void {
    $storage = app(ElectionStorage::class);
    $storage->selectRunType(ElectionRunType::Rehearsal);
    $lockedRun = $storage->startRun(
        'preserved-browser-walkthrough',
        '39010001',
        '20260729-120000',
        ElectionRunType::Rehearsal,
        'browser-walkthrough',
    );
    $preservedArtifact = $storage->writeText(
        'scenarios/preserved-browser-evidence.txt',
        'Preserve this locked evidence.',
    );
    $storage->lockActiveRun();

    $this->artisan('election:scenario full-demo')
        ->expectsOutput('Scenario full-demo passed.')
        ->assertSuccessful();

    $currentRun = $storage->currentRun(ElectionRunType::Rehearsal);

    expect($currentRun['run_id'])->not->toBe($lockedRun['run_id'])
        ->and($currentRun['scenario'])->toBe('full-demo')
        ->and($preservedArtifact)->toBeReadableFile()
        ->and(file_get_contents($preservedArtifact))->toBe('Preserve this locked evidence.');
});

test('evidence folder demo scenario command is registered', function (): void {
    $this->artisan('election:scenario evidence-folder-demo')
        ->expectsOutput('Scenario evidence-folder-demo passed.')
        ->expectsOutputToContain('Run ID: 20260508-080000-39010001-evidence-folder-demo')
        ->expectsOutputToContain('Run Folder: ')
        ->expectsOutputToContain('Start Here: ')
        ->expectsOutputToContain('Report: ')
        ->assertSuccessful();

    $report = app(ElectionStorage::class)->readJson('scenarios/evidence-folder-demo-report.json');
    $run = app(ElectionStorage::class)->currentRun();
    $summary = json_decode(file_get_contents($run['summary_report_path']), true, flags: JSON_THROW_ON_ERROR);
    $index = json_decode(file_get_contents($run['artifact_index_path']), true, flags: JSON_THROW_ON_ERROR);
    $folder = $run['run_path'];

    expect($report['passed'])->toBeTrue()
        ->and($report['scenario'])->toBe('evidence-folder-demo')
        ->and($report['precinct_id'])->toBe('39010001')
        ->and($report['pop_import']['row_count'])->toBe(93629)
        ->and($run['run_id'])->toBe('20260508-080000-39010001-evidence-folder-demo')
        ->and($folder)->toBeDirectory()
        ->and($run['artifact_index_path'])->toBeReadableFile()
        ->and($run['summary_report_path'])->toBeReadableFile()
        ->and($run['summary_report_text_path'])->toBeReadableFile()
        ->and($folder.'/README.md')->toBeReadableFile()
        ->and($folder.'/00-start-here')->toBeDirectory()
        ->and($folder.'/00-start-here/README.md')->toBeReadableFile()
        ->and($folder.'/01-precinct-package-and-configuration')->toBeDirectory()
        ->and($folder.'/01-precinct-package-and-configuration/README.md')->toBeReadableFile()
        ->and($folder.'/02-final-testing-and-sealing')->toBeDirectory()
        ->and($folder.'/02-final-testing-and-sealing/README.md')->toBeReadableFile()
        ->and($folder.'/03-opening-of-polls')->toBeDirectory()
        ->and($folder.'/03-opening-of-polls/README.md')->toBeReadableFile()
        ->and($folder.'/04-voting')->toBeDirectory()
        ->and($folder.'/04-voting/README.md')->toBeReadableFile()
        ->and($folder.'/05-closing-of-polls')->toBeDirectory()
        ->and($folder.'/05-closing-of-polls/README.md')->toBeReadableFile()
        ->and($folder.'/06-counting-and-tally')->toBeDirectory()
        ->and($folder.'/06-counting-and-tally/README.md')->toBeReadableFile()
        ->and($folder.'/07-election-return')->toBeDirectory()
        ->and($folder.'/07-election-return/README.md')->toBeReadableFile()
        ->and($folder.'/08-transmission-or-official-handoff')->toBeDirectory()
        ->and($folder.'/08-transmission-or-official-handoff/README.md')->toBeReadableFile()
        ->and($folder.'/09-final-backup')->toBeDirectory()
        ->and($folder.'/09-final-backup/README.md')->toBeReadableFile()
        ->and($folder.'/10-custody-turnover')->toBeDirectory()
        ->and($folder.'/10-custody-turnover/README.md')->toBeReadableFile()
        ->and($folder.'/11-close-precinct')->toBeDirectory()
        ->and($folder.'/11-close-precinct/README.md')->toBeReadableFile()
        ->and($folder.'/12-audit-and-reconciliation')->toBeDirectory()
        ->and($folder.'/12-audit-and-reconciliation/README.md')->toBeReadableFile()
        ->and($folder.'/13-journal')->toBeDirectory()
        ->and($folder.'/13-journal/README.md')->toBeReadableFile()
        ->and($folder.'/01-precinct-package-and-configuration/active-precinct.json')->toBeReadableFile()
        ->and($folder.'/02-final-testing-and-sealing/scan-documents/cert-001-qr.png')->toBeReadableFile()
        ->and($folder.'/02-final-testing-and-sealing/device-certification-report.json')->toBeReadableFile()
        ->and($folder.'/03-opening-of-polls/attestations')->toBeDirectory()
        ->and($folder.'/03-opening-of-polls/signatures')->toBeDirectory()
        ->and($folder.'/04-voting/ballots/demo-ballot-001.pdf')->toBeReadableFile()
        ->and($folder.'/06-counting-and-tally/tally-sheet.txt')->toBeReadableFile()
        ->and($folder.'/06-counting-and-tally/tally-sheet.pdf')->toBeReadableFile()
        ->and($folder.'/07-election-return/39010001-return.pdf')->toBeReadableFile()
        ->and($summary['important_paths']['ballots'])->toBe($folder.'/04-voting')
        ->and($summary['important_paths']['election_return'])->toBe($folder.'/07-election-return')
        ->and($summary['important_paths']['transmission_or_official_handoff'])->toBe($folder.'/08-transmission-or-official-handoff')
        ->and($summary['important_paths']['final_backup'])->toBe($folder.'/09-final-backup')
        ->and($summary['important_paths']['custody_turnover'])->toBe($folder.'/10-custody-turnover')
        ->and($summary['important_paths']['audit_and_reconciliation'])->toBe($folder.'/12-audit-and-reconciliation')
        ->and($index['artifact_count'])->toBeGreaterThan(0)
        ->and(collect($index['artifacts'])->pluck('relative_path'))->toContain('04-voting/ballots/demo-ballot-001.pdf');

    expect(glob($folder.'/03-opening-of-polls/attestations/attestation-*.json') ?: [])->not->toBeEmpty()
        ->and(glob($folder.'/03-opening-of-polls/signatures/attestation-*.png') ?: [])->not->toBeEmpty();

    foreach ($index['artifacts'] as $artifact) {
        $path = $folder.'/'.$artifact['relative_path'];

        expect($path)->toBeReadableFile()
            ->and(filesize($path))->toBe($artifact['bytes'])
            ->and(hash_file('sha256', $path))->toBe($artifact['sha256']);
    }

    $this->artisan('election:scenario full-demo')
        ->expectsOutput('Scenario full-demo passed.')
        ->assertSuccessful();

    expect($folder)->not->toBeDirectory()
        ->and($run['summary_report_path'])->not->toBeReadableFile();
});

test('home page renders the ceremony shell', function (): void {
    $this->withoutVite();

    $this->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Home')
            ->has('snapshot.stage')
        );
});

function writePassingCupsCertification(string $printerName): void
{
    app(ElectionStorage::class)->writeJson('certification/device-certification-report.json', [
        'schema_version' => 'device-certification-report-1',
        'passed' => true,
        'devices' => [
            'printer' => [
                'adapter' => 'cups-printer',
                'status' => 'ready',
                'capabilities' => ['cups-status'],
                'detail' => "printer {$printerName} is idle",
                'exit_code' => 0,
                'printer' => $printerName,
            ],
            'scanner' => [
                'adapter' => 'simulated-scanner',
                'status' => 'ready',
                'capabilities' => ['qr-payload'],
            ],
        ],
        'report_hash' => hash('sha256', $printerName),
    ]);
}

function lifecycleTestSignatureDataUri(): string
{
    return 'data:image/png;base64,'.base64_encode(lifecycleTestSignaturePng());
}

function lifecycleTestSignaturePng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGOSHzRgAAAAABJRU5ErkJggg==', true);
}
