<?php

use App\Election\Attestation\OfficerAttestationService;
use App\Election\Certification\CertificationService;
use App\Election\Core\ActivityJournal;
use App\Election\Counting\CountingService;
use App\Election\Devices\CameraScannerHealthCheck;
use App\Election\Devices\CupsPrinterHealthCheck;
use App\Election\Devices\DeviceCertificationService;
use App\Election\Devices\HandheldScannerHealthCheck;
use App\Election\Diagnostics\DiagnosticsService;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Preparation\DeterministicMapper;
use App\Election\Preparation\SampleElectionData;
use App\Election\Printing\BallotPrinter;
use App\Election\Printing\PrinterCertificationRequired;
use App\Election\Printing\SpoilBallot;
use App\Election\Returns\ElectionReturnService;
use App\Election\Scanning\BallotScanner;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\BallotPayloadService;
use App\Election\Voting\StandardQrCode;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
});

test('lifecycle transitions reject invalid jumps', function (): void {
    $lifecycle = app(LifecycleState::class);

    expect($lifecycle->current())->toBe(Lifecycle::Provision);

    $lifecycle->advanceTo(Lifecycle::Certification);

    expect($lifecycle->current())->toBe(Lifecycle::Certification);
    expect(fn () => $lifecycle->advanceTo(Lifecycle::Voting))->toThrow(RuntimeException::class);
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
        ->and($record['officer_role'])->toBe('Precinct Chair')
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
        ->and(file_get_contents($job['pdf_artifact_path']))->toContain('pres-ada');
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
        ->and(file_get_contents(app(ElectionStorage::class)->path('returns/0421-A-return.pdf')))->toContain('pres-ada: 1');
});

test('full demo scenario command succeeds', function (): void {
    $this->artisan('election:scenario full-demo')
        ->expectsOutput('Scenario full-demo passed.')
        ->assertSuccessful();

    $report = app(ElectionStorage::class)->readJson('scenarios/full-demo-report.json');

    expect($report['passed'])->toBeTrue()
        ->and($report['accepted_ballots'])->toBe(1)
        ->and($report['rejected_ballots'])->toBe(1)
        ->and($report['attestation_hashes'])->toHaveCount(2);
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
