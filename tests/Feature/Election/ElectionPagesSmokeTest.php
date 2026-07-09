<?php

use App\Election\Core\ActivityJournal;
use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Printing\BallotPrinter;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\BallotPayloadService;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
    app(ElectionClock::class)->unfreeze();
    $this->withoutVite();
});

test('ceremony page renders :component', function (string $route, string $component): void {
    $this->get(route($route))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->has('snapshot.stage')
            ->has('snapshot.ceremony')
            ->has('snapshot.nextAction')
            ->has('snapshot.journal')
        );
})->with([
    'home' => ['home', 'Election/Home'],
    'provision' => ['election.provision', 'Election/Provision'],
    'certification' => ['election.certification', 'Election/Certification'],
    'voting' => ['election.voting', 'Election/Voting'],
    'printing' => ['election.printing', 'Election/Printing'],
    'counting' => ['election.counting', 'Election/Counting'],
    'returns' => ['election.returns', 'Election/Returns'],
    'diagnostics' => ['election.diagnostics', 'Election/Diagnostics'],
]);

test('certification page can run certification and manual verification', function (): void {
    $this->from(route('election.certification'));

    $this->post(route('election.certification.run'))
        ->assertRedirect(route('election.voting'));

    $certification = app(ElectionStorage::class)->readJson('certification/friday-certification-report.json');
    $manualReturn = [
        'schema_version' => 'manual-return-1',
        'precinct_id' => $certification['precinct_id'] ?? null,
        'accepted_ballots' => $certification['accepted_ballots'] ?? 0,
        'rejected_ballots' => $certification['rejected_ballots'] ?? 0,
        'tally' => $certification['actual_tally'] ?? [],
    ];

    $this->post(route('election.certification.manual-verification'), [
        'manual_return' => json_encode($manualReturn),
    ])->assertRedirect(route('election.certification'));

    $manualVerification = app(ElectionStorage::class)->readJson('certification/manual-verification-report.json');

    $this->get(route('election.certification'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Certification')
            ->where('certificationReport.passed', $certification['passed'])
            ->where('certificationReport.accepted_ballots', $certification['accepted_ballots'])
            ->where('manualVerificationReport.passed', $manualVerification['passed'])
            ->where('manualVerificationReport.report_hash', $manualVerification['report_hash'])
        );

    $this->get(route('election.certification.manual-verification.download'))
        ->assertDownload('manual-verification-report.json');
});

test('printing page exposes finalized ballot qr and artifact state', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana'],
    ], 'smoke-ballot-001');

    app(BallotPrinter::class)->print($payload);

    $this->get(route('election.printing', ['ballot' => 'smoke-ballot-001']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Printing')
            ->where('payload.ballot_id', 'smoke-ballot-001')
            ->where('payload.payload_hash', $payload['payload_hash'])
            ->where('qrImageDataUri', fn (string $value): bool => str_starts_with($value, 'data:image/png;base64,'))
            ->has('snapshot.counts')
        );
});

test('printing ceremony reports certification gate for cups printer driver', function (): void {
    config()->set('election.devices.printer.driver', 'cups');
    config()->set('election.devices.printer.cups.name', 'Precinct_Printer');

    try {
        app(ActivateSamplePackage::class)->handle();

        app(BallotPayloadService::class)->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'smoke-ballot-cups-gated');

        $this->from(route('election.printing', ['ballot' => 'smoke-ballot-cups-gated']))
            ->post(route('election.printing.print', ['ballot' => 'smoke-ballot-cups-gated']))
            ->assertRedirect(route('election.printing', ['ballot' => 'smoke-ballot-cups-gated']))
            ->assertSessionHasErrors('printer');

        expect(app(ElectionStorage::class)->files('print-jobs'))->toHaveCount(0);
    } finally {
        config()->set('election.devices.printer.driver', 'file');
    }
});

test('diagnostics page can run device adapter certification', function (): void {
    $this->post(route('election.diagnostics.certify-devices'))
        ->assertRedirect(route('election.diagnostics'));

    $this->get(route('election.diagnostics'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Diagnostics')
            ->where('diagnostics.device_certification.passed', true)
            ->where('diagnostics.device_certification.devices.printer.status', 'ready')
            ->where('diagnostics.device_certification.devices.scanner.status', 'ready')
            ->has('snapshot.journal')
        );
});

test('provision page can generate and display electoral board role baseline', function (): void {
    $this->post(route('election.provision.eb-role-baseline'))
        ->assertRedirect(route('election.provision'))
        ->assertSessionHas('electoral_board_baseline_hash');

    $this->get(route('election.provision'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Provision')
            ->has('electoralBoardBaseline')
            ->where('electoralBoardBaseline.exists', true)
            ->where('electoralBoardBaseline.required_role_count', 3)
            ->where('electoralBoardBaseline.required_roles_present', 3)
            ->where('electoralBoardBaseline.missing_required_role_count', 0)
            ->where('electoralBoardBaseline.passed', true)
            ->where('electoralBoardBaseline.baseline_hash', fn (string $hash): bool => $hash !== '')
            ->has('snapshot.stage')
        );
});

test('provision page can run and display legal scenario suite harness', function (): void {
    $this->post(route('election.provision.legal-scenario-suite'))
        ->assertRedirect(route('election.provision'))
        ->assertSessionHas('legal_scenario_suite_hash');

    $this->get(route('election.provision'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Provision')
            ->has('legalScenarioSuite')
            ->where('legalScenarioSuite.exists', true)
            ->where('legalScenarioSuite.suite', 'legal')
            ->where('legalScenarioSuite.passed', true)
            ->where('legalScenarioSuite.sub_scenarios.0', 'friday-certification')
            ->has('snapshot.stage')
        );
});

test('provision page can generate and display supply verification baseline', function (): void {
    app(ActivateSamplePackage::class)->handle();
    $this->post(route('election.diagnostics.certify-devices'))->assertRedirect(route('election.diagnostics'));

    $this->post(route('election.provision.supply-verification-baseline'))
        ->assertRedirect(route('election.provision'))
        ->assertSessionHas('supply_verification_baseline_hash');

    $this->get(route('election.provision'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Provision')
            ->has('supplyVerificationBaseline')
            ->where('supplyVerificationBaseline.exists', true)
            ->where('supplyVerificationBaseline.required_supply_count', fn (int $count): bool => $count >= 3)
            ->where('supplyVerificationBaseline.required_supplies_present', fn (int $count): bool => $count === 3)
            ->where('supplyVerificationBaseline.required_supply_missing_count', 0)
            ->where('supplyVerificationBaseline.passed', true)
            ->where('supplyVerificationBaseline.baseline_hash', fn (string $hash): bool => $hash !== '')
            ->has('snapshot.stage')
        );
});

test('diagnostics page exposes attestation signature evidence bundle', function (): void {
    $this->post(route('election.attestations.store'), [
        'ceremony' => 'Friday Certification',
        'officer_code' => 'SIM-OFFICER-001',
        'officer_pin' => '123456',
        'signature_data' => pagesTestSignatureDataUri(),
        'stage' => 'certification',
        'statement' => 'Certification checkpoint reviewed.',
    ])->assertRedirect();

    $attestationPath = app(ElectionStorage::class)->files('attestations')[0];
    $attestation = json_decode(
        file_get_contents($attestationPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $attestationArtifact = basename($attestationPath);
    $signatureArtifact = basename($attestation['signature_artifact_path']);

    $this->get(route('election.diagnostics'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Diagnostics')
            ->where('diagnostics.attestation_artifacts.0.attestation_id', 'attestation-000001')
            ->where('diagnostics.attestation_artifacts.0.attestation_artifact', $attestationArtifact)
            ->where('diagnostics.attestation_artifacts.0.signature_artifact', $signatureArtifact)
            ->where('diagnostics.attestation_artifacts.0.signature_artifact_hash', $attestation['signature_artifact_hash'])
        );

    $this->get(route('election.diagnostics.attestations.show', $attestationArtifact))
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/json');

    $this->get(route('election.diagnostics.signatures.show', $signatureArtifact))
        ->assertSuccessful()
        ->assertHeader('content-type', 'image/png');

    $this->get(route('election.diagnostics.attestations.download', $attestationArtifact))
        ->assertDownload($attestationArtifact);

    $this->get(route('election.diagnostics.signatures.download', $signatureArtifact))
        ->assertDownload($signatureArtifact);

    $this->get(route('election.diagnostics.signatures.show', '../'.$signatureArtifact))
        ->assertNotFound();
});

test('diagnostics can generate and download precinct evidence manifest', function (): void {
    app(ElectionClock::class)->freeze('2026-05-08 18:00:00');
    app(ActivateSamplePackage::class)->handle();

    $this->post(route('election.attestations.store'), [
        'ceremony' => 'Friday Certification',
        'officer_code' => 'SIM-OFFICER-001',
        'officer_pin' => '123456',
        'signature_data' => pagesTestSignatureDataUri(),
        'stage' => 'certification',
        'statement' => 'Certification checkpoint reviewed.',
    ])->assertRedirect();

    $this->post(route('election.diagnostics.evidence-manifest.generate'))
        ->assertRedirect(route('election.diagnostics'))
        ->assertSessionHas('evidence_manifest_hash');

    $manifest = app(ElectionStorage::class)->readJson('diagnostics/evidence-manifest.json');

    expect($manifest['schema_version'])->toBe('precinct-evidence-manifest-1')
        ->and($manifest['configuration']['precinct_id'])->toBe('0421-A')
        ->and($manifest['categories']['polls_opening']['files'])->toHaveCount(2)
        ->and($manifest['manifest_hash'])->toBeString();

    $this->get(route('election.diagnostics'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Diagnostics')
            ->where('diagnostics.evidence_manifest.exists', true)
            ->where('diagnostics.evidence_manifest.manifest_hash', $manifest['manifest_hash'])
            ->where('diagnostics.evidence_manifest.categories.polls_opening', 2)
        );

    $this->get(route('election.diagnostics.evidence-manifest.download'))
        ->assertDownload('evidence-manifest.json');

    app(ElectionClock::class)->unfreeze();
});

test('diagnostics can generate and download initialization report', function (): void {
    app(ElectionClock::class)->freeze('2026-05-09 09:45:00');
    app(ActivateSamplePackage::class)->handle();
    $this->post(route('election.diagnostics.certify-devices'))->assertRedirect(route('election.diagnostics'));

    $this->post(route('election.diagnostics.initialization-report.generate'))
        ->assertRedirect(route('election.diagnostics'))
        ->assertSessionHas('initialization_report_hash');

    $report = app(ElectionStorage::class)->readJson('diagnostics/initialization-report.json');

    expect($report['schema_version'])->toBe('initialization-report-1')
        ->and($report['precinct_id'])->toBe('0421-A')
        ->and($report['passed'])->toBeBool()
        ->and($report['counts']['accepted_ballots'])->toBeInt()
        ->and($report['counts']['rejected_ballots'])->toBeInt()
        ->and($report['checks'])->toBeArray()
        ->and($report['report_hash'])->toBe($report['report_hash']);

    $this->get(route('election.diagnostics'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Diagnostics')
            ->where('diagnostics.initialization_report.exists', true)
            ->where('diagnostics.initialization_report.schema_version', 'initialization-report-1')
            ->where('diagnostics.initialization_report.run_id', $report['run_id'])
            ->where('diagnostics.initialization_report.report_hash', $report['report_hash'])
            ->where('diagnostics.initialization_report.passed', $report['passed'])
        );

    $this->get(route('election.diagnostics.initialization-report.download'))
        ->assertDownload('initialization-report.json');

    app(ElectionClock::class)->unfreeze();
});

test('diagnostics can generate and download evidence reference baseline', function (): void {
    app(ElectionClock::class)->freeze('2026-05-09 10:00:00');
    app(ActivateSamplePackage::class)->handle();

    $this->post(route('election.attestations.store'), [
        'ceremony' => 'Friday Certification',
        'officer_code' => 'SIM-OFFICER-001',
        'officer_pin' => '123456',
        'signature_data' => pagesTestSignatureDataUri(),
        'stage' => 'certification',
        'statement' => 'Certification checkpoint reviewed.',
    ])->assertRedirect();

    $this->post(route('election.diagnostics.evidence-reference-baseline.generate'))
        ->assertRedirect(route('election.diagnostics'))
        ->assertSessionHas('evidence_reference_baseline_hash');

    $baseline = app(ElectionStorage::class)->readJson('diagnostics/evidence-reference-baseline.json');

    expect($baseline['schema_version'])->toBe('evidence-reference-baseline-1')
        ->and($baseline['artifact_reference_count'])->toBeGreaterThan(0)
        ->and($baseline['missing_required_reference_count'])->toBeGreaterThanOrEqual(0);

    $this->get(route('election.diagnostics'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Diagnostics')
            ->where('diagnostics.evidence_reference_baseline.exists', true)
            ->where('diagnostics.evidence_reference_baseline.baseline_hash', $baseline['baseline_hash'])
            ->where('diagnostics.evidence_reference_baseline.artifact_reference_count', $baseline['artifact_reference_count'])
        );

    $this->get(route('election.diagnostics.evidence-reference-baseline.download'))
        ->assertDownload('evidence-reference-baseline.json');

    app(ElectionClock::class)->unfreeze();
});

test('diagnostics can generate and download official minutes baseline', function (): void {
    app(ElectionClock::class)->freeze('2026-05-09 11:00:00');
    app(ActivateSamplePackage::class)->handle();

    $this->post(route('election.attestations.store'), [
        'ceremony' => 'Friday Certification',
        'officer_code' => 'SIM-OFFICER-001',
        'officer_pin' => '123456',
        'signature_data' => pagesTestSignatureDataUri(),
        'stage' => 'certification',
        'statement' => 'Certification checkpoint reviewed.',
    ])->assertRedirect();

    $this->post(route('election.diagnostics.official-minutes-baseline.generate'))
        ->assertRedirect(route('election.diagnostics'))
        ->assertSessionHas('official_minutes_baseline_hash');

    $minutes = app(ElectionStorage::class)->readJson('diagnostics/official-minutes-baseline.json');

    expect($minutes['schema_version'])->toBe('official-minutes-baseline-1')
        ->and($minutes['minute_count'])->toBeGreaterThan(0)
        ->and($minutes['source_journal_event_count'])->toBeGreaterThan(0)
        ->and($minutes['source_attestation_count'])->toBeGreaterThan(0)
        ->and($minutes['official_minute_hash'])->toBeString();

    $this->get(route('election.diagnostics'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Diagnostics')
            ->where('diagnostics.official_minutes_baseline.exists', true)
            ->where('diagnostics.official_minutes_baseline.official_minute_hash', $minutes['official_minute_hash'])
            ->where('diagnostics.official_minutes_baseline.minute_count', $minutes['minute_count'])
        );

    $this->get(route('election.diagnostics.official-minutes-baseline.download'))
        ->assertDownload('official-minutes-baseline.json');

    app(ElectionClock::class)->unfreeze();
});

test('diagnostics can build and download evidence bundle archive', function (): void {
    app(ElectionClock::class)->freeze('2026-05-08 18:30:00');
    app(ActivateSamplePackage::class)->handle();

    $this->post(route('election.attestations.store'), [
        'ceremony' => 'Friday Certification',
        'officer_code' => 'SIM-OFFICER-001',
        'officer_pin' => '123456',
        'signature_data' => pagesTestSignatureDataUri(),
        'stage' => 'certification',
        'statement' => 'Certification checkpoint reviewed.',
    ])->assertRedirect();

    $this->post(route('election.diagnostics.evidence-bundle-archive.build'))
        ->assertRedirect(route('election.diagnostics'))
        ->assertSessionHas('evidence_bundle_archive_hash');

    $report = app(ElectionStorage::class)->readJson('diagnostics/evidence-bundle-archive.json');
    $archive = file_get_contents($report['archive_path']);

    expect($report['schema_version'])->toBe('evidence-bundle-archive-report-1')
        ->and($report['archive_id'])->toBe('evidence-bundle-20260508-183000')
        ->and($report['archive_artifact'])->toBe('evidence-bundle-20260508-183000.tar')
        ->and($report['entry_count'])->toBeGreaterThan(3)
        ->and($report['archive_sha256'])->toBe(hash_file('sha256', $report['archive_path']))
        ->and($archive)->toContain('evidence-manifest.json')
        ->and($archive)->toContain('archive-index.json')
        ->and($archive)->toContain('03-polls-opening')
        ->and($archive)->toContain('attestation-000001');

    $this->get(route('election.diagnostics'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Diagnostics')
            ->where('diagnostics.evidence_bundle_archive.exists', true)
            ->where('diagnostics.evidence_bundle_archive.archive_id', 'evidence-bundle-20260508-183000')
            ->where('diagnostics.evidence_bundle_archive.archive_sha256', $report['archive_sha256'])
        );

    $this->get(route('election.diagnostics.evidence-bundle-archive.download'))
        ->assertDownload('evidence-bundle-20260508-183000.tar');

    expect(collect(app(ActivityJournal::class)->entries())->last()['event_type'])
        ->toBe('evidence_bundle.archive_built');

    app(ElectionClock::class)->unfreeze();
});

test('diagnostics can verify downloadable evidence bundle archive', function (): void {
    app(ElectionClock::class)->freeze('2026-05-08 18:45:00');
    app(ActivateSamplePackage::class)->handle();

    $this->post(route('election.diagnostics.evidence-bundle-archive.build'))
        ->assertRedirect(route('election.diagnostics'));

    $this->post(route('election.diagnostics.evidence-bundle-archive.verify'))
        ->assertRedirect(route('election.diagnostics'))
        ->assertSessionHas('evidence_bundle_archive_verification_hash');

    $report = app(ElectionStorage::class)->readJson('diagnostics/evidence-bundle-archive-verification.json');

    expect($report['schema_version'])->toBe('evidence-bundle-archive-verification-1')
        ->and($report['archive_id'])->toBe('evidence-bundle-20260508-184500')
        ->and($report['passed'])->toBeTrue()
        ->and($report['checked_files'])->toBeGreaterThan(0)
        ->and($report['mismatches'])->toBe([])
        ->and($report['verification_hash'])->toBeString();

    $this->get(route('election.diagnostics'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Diagnostics')
            ->where('diagnostics.evidence_bundle_archive_verification.exists', true)
            ->where('diagnostics.evidence_bundle_archive_verification.passed', true)
            ->where('diagnostics.evidence_bundle_archive_verification.archive_id', 'evidence-bundle-20260508-184500')
            ->where('diagnostics.evidence_bundle_archive_verification.verification_hash', $report['verification_hash'])
            ->where('diagnostics.evidence_bundle_archive_verification.mismatch_count', 0)
        );

    expect(collect(app(ActivityJournal::class)->entries())->last()['event_type'])
        ->toBe('evidence_bundle.archive_verification_passed');

    app(ElectionClock::class)->unfreeze();
});

test('diagnostics can upload and verify returned evidence bundle archive', function (): void {
    app(ElectionClock::class)->freeze('2026-05-08 18:50:00');
    app(ActivateSamplePackage::class)->handle();

    $this->post(route('election.diagnostics.evidence-bundle-archive.build'))
        ->assertRedirect(route('election.diagnostics'));

    $archive = app(ElectionStorage::class)->readJson('diagnostics/evidence-bundle-archive.json');
    $uploadedArchive = new UploadedFile(
        $archive['archive_path'],
        'returned-evidence-bundle.tar',
        'application/x-tar',
        null,
        true,
    );

    $this->post(route('election.diagnostics.evidence-bundle-archive.upload-verify'), [
        'archive' => $uploadedArchive,
    ])
        ->assertRedirect(route('election.diagnostics'))
        ->assertSessionHas('evidence_bundle_archive_verification_hash');

    $report = app(ElectionStorage::class)->readJson('diagnostics/evidence-bundle-archive-verification.json');

    expect($report['schema_version'])->toBe('evidence-bundle-archive-verification-1')
        ->and($report['archive_source'])->toBe('operator-upload')
        ->and($report['archive_id'])->toBe('evidence-bundle-20260508-185000')
        ->and($report['passed'])->toBeTrue()
        ->and($report['mismatches'])->toBe([])
        ->and($report['uploaded_archive_original_name'])->toBe('returned-evidence-bundle.tar')
        ->and($report['uploaded_archive_artifact'])->toStartWith('uploaded-evidence-bundle-20260508-185000-')
        ->and(app(ElectionStorage::class)->path('diagnostics/uploaded-archives/'.$report['uploaded_archive_artifact']))->toBeReadableFile();

    $this->get(route('election.diagnostics'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Diagnostics')
            ->where('diagnostics.evidence_bundle_archive_verification.exists', true)
            ->where('diagnostics.evidence_bundle_archive_verification.archive_source', 'operator-upload')
            ->where('diagnostics.evidence_bundle_archive_verification.uploaded_archive_original_name', 'returned-evidence-bundle.tar')
            ->where('diagnostics.evidence_bundle_archive_verification.verification_hash', $report['verification_hash'])
            ->where('diagnostics.evidence_bundle_archive_verification.mismatch_count', 0)
        );

    $event = collect(app(ActivityJournal::class)->entries())->last();

    expect($event['event_type'])->toBe('evidence_bundle.archive_verification_passed')
        ->and($event['payload']['archive_source'])->toBe('operator-upload')
        ->and($event['payload']['archive_id'])->toBe('evidence-bundle-20260508-185000');

    app(ElectionClock::class)->unfreeze();
});

test('diagnostics can stage removable media evidence export', function (): void {
    app(ElectionClock::class)->freeze('2026-05-08 19:00:00');
    app(ActivateSamplePackage::class)->handle();

    $this->post(route('election.attestations.store'), [
        'ceremony' => 'Friday Certification',
        'officer_code' => 'SIM-OFFICER-001',
        'officer_pin' => '123456',
        'signature_data' => pagesTestSignatureDataUri(),
        'stage' => 'certification',
        'statement' => 'Certification checkpoint reviewed.',
    ])->assertRedirect();

    $this->post(route('election.diagnostics.removable-media.export'))
        ->assertRedirect(route('election.diagnostics'))
        ->assertSessionHas('removable_media_export_hash');

    $storage = app(ElectionStorage::class);
    $attestationArtifact = basename($storage->files('attestations')[0]);
    $signatureArtifact = basename($storage->files('attestation-signatures')[0]);
    $exportRoot = $storage->path('removable-media/evidence-export-20260508-190000');
    $report = json_decode(
        file_get_contents($exportRoot.'/export-report.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($report['schema_version'])->toBe('removable-media-export-report-1')
        ->and($report['export_id'])->toBe('evidence-export-20260508-190000')
        ->and($report['artifact_count'])->toBeGreaterThan(2)
        ->and(collect($report['copied_files'])->contains(fn (array $file): bool => $file['target'] === 'artifacts/03-polls-opening/attestations/'.$attestationArtifact))->toBeTrue()
        ->and($exportRoot.'/evidence-manifest.json')->toBeReadableFile()
        ->and($exportRoot.'/artifacts/03-polls-opening/attestations/'.$attestationArtifact)->toBeReadableFile()
        ->and($exportRoot.'/artifacts/03-polls-opening/signatures/'.$signatureArtifact)->toBeReadableFile();

    $this->get(route('election.diagnostics'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Diagnostics')
            ->where('diagnostics.removable_media_export.exists', true)
            ->where('diagnostics.removable_media_export.export_id', 'evidence-export-20260508-190000')
            ->where('diagnostics.removable_media_export.export_hash', $report['export_hash'])
        );

    expect(collect(app(ActivityJournal::class)->entries())->last()['event_type'])
        ->toBe('evidence_bundle.exported');

    app(ElectionClock::class)->unfreeze();
});

test('diagnostics can check simulated removable media readiness', function (): void {
    app(ElectionClock::class)->freeze('2026-05-08 19:15:00');

    $this->post(route('election.diagnostics.removable-media.readiness'))
        ->assertRedirect(route('election.diagnostics'))
        ->assertSessionHas('removable_media_readiness_hash');

    $report = app(ElectionStorage::class)->readJson('diagnostics/removable-media-readiness.json');

    expect($report['schema_version'])->toBe('removable-media-readiness-report-1')
        ->and($report['ready'])->toBeTrue()
        ->and($report['configured'])->toBeFalse()
        ->and($report['status'])->toBe('simulated_ready')
        ->and($report['status_label'])->toBe('Simulated Local Target Ready')
        ->and($report['readiness_hash'])->toBeString()
        ->and(collect($report['checks'])->pluck('passed')->every(fn (bool $passed): bool => $passed))->toBeTrue();

    $this->get(route('election.diagnostics'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Diagnostics')
            ->where('diagnostics.removable_media_readiness.exists', true)
            ->where('diagnostics.removable_media_readiness.ready', true)
            ->where('diagnostics.removable_media_readiness.status', 'simulated_ready')
            ->where('diagnostics.removable_media_readiness.status_label', 'Simulated Local Target Ready')
            ->where('diagnostics.removable_media_readiness.readiness_hash', $report['readiness_hash'])
        );

    expect(collect(app(ActivityJournal::class)->entries())->last()['event_type'])
        ->toBe('removable_media.readiness_passed');

    app(ElectionClock::class)->unfreeze();
});

test('diagnostics reports missing configured removable media target as not ready', function (): void {
    $target = sys_get_temp_dir().'/aes-missing-media-'.bin2hex(random_bytes(4));
    config()->set('election.removable_media.path', $target);

    try {
        $this->post(route('election.diagnostics.removable-media.readiness'))
            ->assertRedirect(route('election.diagnostics'));

        $report = app(ElectionStorage::class)->readJson('diagnostics/removable-media-readiness.json');

        expect($report['ready'])->toBeFalse()
            ->and($report['configured'])->toBeTrue()
            ->and($report['status'])->toBe('missing')
            ->and($report['status_label'])->toBe('Target Missing')
            ->and($report['target_path'])->toBe($target)
            ->and(collect($report['checks'])->firstWhere('name', 'directory_available')['passed'])->toBeFalse();

        $this->get(route('election.diagnostics'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Election/Diagnostics')
                ->where('diagnostics.removable_media_readiness.exists', true)
                ->where('diagnostics.removable_media_readiness.ready', false)
                ->where('diagnostics.removable_media_readiness.status', 'missing')
                ->where('diagnostics.removable_media_readiness.status_label', 'Target Missing')
                ->where('diagnostics.removable_media_readiness.target_path', $target)
            );
    } finally {
        config()->set('election.removable_media.path', '');
    }
});

test('diagnostics can run and inspect evidence export verification report', function (): void {
    app(ElectionClock::class)->freeze('2026-05-08 19:30:00');
    app(ActivateSamplePackage::class)->handle();

    $this->post(route('election.diagnostics.removable-media.export'))
        ->assertRedirect(route('election.diagnostics'));

    $this->post(route('election.diagnostics.removable-media.verify'))
        ->assertRedirect(route('election.diagnostics'))
        ->assertSessionHas('evidence_export_verification_hash');

    $report = app(ElectionStorage::class)->readJson('diagnostics/evidence-export-verification.json');

    expect($report['schema_version'])->toBe('removable-media-export-verification-1')
        ->and($report['passed'])->toBeTrue()
        ->and($report['checked_files'])->toBeGreaterThan(0)
        ->and($report['mismatches'])->toBe([])
        ->and($report['verification_hash'])->toBeString();

    $this->get(route('election.diagnostics'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Diagnostics')
            ->where('diagnostics.evidence_export_verification.exists', true)
            ->where('diagnostics.evidence_export_verification.passed', true)
            ->where('diagnostics.evidence_export_verification.verification_hash', $report['verification_hash'])
            ->where('diagnostics.evidence_export_verification.mismatch_count', 0)
        );

    expect(collect(app(ActivityJournal::class)->entries())->last()['event_type'])
        ->toBe('evidence_bundle.verification_passed');

    app(ElectionClock::class)->unfreeze();
});

test('counting route uses configured handheld scanner adapter', function (): void {
    config()->set('election.devices.scanner.driver', 'handheld');
    config()->set('election.devices.scanner.handheld.name', 'USB Scanner 1');

    try {
        app(ActivateSamplePackage::class)->handle();

        $payload = app(BallotPayloadService::class)->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'smoke-ballot-handheld');

        $this->post(route('election.counting.scan'), [
            'payload' => "AES-SCAN:\n{$payload['qr_payload']}\t",
        ])->assertRedirect(route('election.counting'));

        expect(app(ElectionStorage::class)->files('counting/accepted'))->toHaveCount(1);
    } finally {
        config()->set('election.devices.scanner.driver', 'manual');
    }
});

test('counting route can scan camera qr image data uri', function (): void {
    config()->set('election.devices.scanner.driver', 'camera');
    config()->set('election.devices.scanner.camera.name', 'Precinct Camera 1');

    try {
        app(ActivateSamplePackage::class)->handle();

        $payload = app(BallotPayloadService::class)->finalize([
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ], 'smoke-ballot-camera');

        $this->post(route('election.counting.scan'), [
            'payload' => 'data:image/png;base64,'.base64_encode(file_get_contents($payload['qr_artifact_path'])),
        ])->assertRedirect(route('election.counting'));

        expect(app(ElectionStorage::class)->files('counting/accepted'))->toHaveCount(1);
    } finally {
        config()->set('election.devices.scanner.driver', 'manual');
    }
});

test('counting route records scanner decode failures as rejected scan feedback', function (): void {
    config()->set('election.devices.scanner.driver', 'camera');
    config()->set('election.devices.scanner.camera.name', 'Precinct Camera 1');

    try {
        app(ActivateSamplePackage::class)->handle();

        $this->post(route('election.counting.scan'), [
            'payload' => 'data:image/png;base64,'.base64_encode(cameraDecodeFailurePng()),
        ])
            ->assertRedirect(route('election.counting'))
            ->assertSessionHas('scan_feedback', fn (array $feedback): bool => $feedback['status'] === 'rejected'
                && $feedback['adapter'] === 'scanner-decode'
                && str_contains($feedback['reason'], 'Unable to decode QR artifact'));

        expect(app(ElectionStorage::class)->files('counting/accepted'))->toHaveCount(0)
            ->and(app(ElectionStorage::class)->files('counting/rejected'))->toHaveCount(1);
    } finally {
        config()->set('election.devices.scanner.driver', 'manual');
    }
});

test('counting page shows operator feedback after accepted and rejected scans', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana'],
    ], 'smoke-ballot-feedback');

    $this->post(route('election.counting.scan'), [
        'payload' => $payload['qr_payload'],
    ])
        ->assertRedirect(route('election.counting'))
        ->assertSessionHas('scan_feedback', fn (array $feedback): bool => $feedback['status'] === 'accepted'
            && $feedback['ballot_id'] === 'smoke-ballot-feedback'
            && $feedback['adapter'] === 'manual-payload');

    $this->get(route('election.counting'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Counting')
            ->where('scanFeedback.status', 'accepted')
            ->where('scanFeedback.ballot_id', 'smoke-ballot-feedback')
            ->where('scanFeedback.adapter', 'manual-payload')
        );

    $this->post(route('election.counting.scan'), [
        'payload' => $payload['qr_payload'],
    ])
        ->assertRedirect(route('election.counting'))
        ->assertSessionHas('scan_feedback', fn (array $feedback): bool => $feedback['status'] === 'rejected'
            && str_contains($feedback['reason'], 'Duplicate ballot payload.'));

    $this->get(route('election.counting'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Counting')
            ->where('scanFeedback.status', 'rejected')
            ->where('scanFeedback.reason', 'Duplicate ballot payload.')
        );
});

test('ceremony shell can record officer attestation', function (): void {
    $this->from(route('election.certification'))
        ->post(route('election.attestations.store'), [
            'ceremony' => 'Friday Certification',
            'officer_code' => 'SIM-OFFICER-001',
            'officer_pin' => '123456',
            'signature_data' => pagesTestSignatureDataUri(),
            'stage' => 'certification',
            'statement' => 'Certification checkpoint reviewed.',
        ])
        ->assertRedirect(route('election.certification'));

    $this->get(route('election.certification'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/Certification')
            ->where('snapshot.counts.attestations', 1)
            ->has('snapshot.journal')
        );
});

test('ceremony shell rejects invalid officer pin', function (): void {
    $this->from(route('election.certification'))
        ->post(route('election.attestations.store'), [
            'ceremony' => 'Friday Certification',
            'officer_code' => 'SIM-OFFICER-001',
            'officer_pin' => '000000',
            'signature_data' => pagesTestSignatureDataUri(),
            'stage' => 'certification',
            'statement' => 'Certification checkpoint reviewed.',
        ])
        ->assertRedirect(route('election.certification'))
        ->assertSessionHasErrors('officer_pin');

    expect(app(ElectionStorage::class)->files('attestations'))->toHaveCount(0);
});

test('ceremony shell requires officer signature artifact', function (): void {
    $this->from(route('election.certification'))
        ->post(route('election.attestations.store'), [
            'ceremony' => 'Friday Certification',
            'officer_code' => 'SIM-OFFICER-001',
            'officer_pin' => '123456',
            'stage' => 'certification',
            'statement' => 'Certification checkpoint reviewed.',
        ])
        ->assertRedirect(route('election.certification'))
        ->assertSessionHasErrors('signature_data');

    expect(app(ElectionStorage::class)->files('attestations'))->toHaveCount(0)
        ->and(app(ElectionStorage::class)->files('attestation-signatures'))->toHaveCount(0);
});

function pagesTestSignatureDataUri(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGOSHzRgAAAAABJRU5ErkJggg==';
}

function cameraDecodeFailurePng(): string
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGOSHzRgAAAAABJRU5ErkJggg==', true);

    if ($png === false) {
        throw new RuntimeException('Invalid camera decode failure PNG fixture.');
    }

    return $png;
}
