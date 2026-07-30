<?php

use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Support\ElectionStorage;
use App\Election\Tabulation\TabulationProfile;
use App\Election\Voting\BallotPayloadService;

beforeEach(function (): void {
    config()->set('election.tabulation.profile', TabulationProfile::PaperFirst->value);
    app(ElectionStorage::class)->reset();
});

test('operator can submit a camera capture frame through the counting ceremony', function (): void {
    config()->set('election.devices.scanner.driver', 'camera');
    config()->set('election.devices.scanner.camera.name', 'Browser Camera Test');

    app(ActivateSamplePackage::class)->handle();
    app(LifecycleState::class)->set(Lifecycle::Counting);

    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana'],
    ], 'browser-camera-ballot-001');

    $qrDataUri = 'data:image/png;base64,'.base64_encode(file_get_contents($payload['qr_artifact_path']));

    $page = visit('/election/counting')
        ->assertSee('Camera Capture')
        ->assertSee('Accepted 0')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $page->script(browserMediaCaptureShim($qrDataUri));

    $page->click('Start Camera')
        ->assertSee('Camera ready.')
        ->click('Capture Scan')
        ->wait(1)
        ->assertSee('Scan Accepted')
        ->assertSee('browser-camera-ballot-001')
        ->assertSee('camera-image')
        ->assertSee('Accepted 1')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(app(ElectionStorage::class)->files('counting/accepted'))->toHaveCount(1);
});

test('operator sees feedback when camera permission is denied or unavailable', function (): void {
    config()->set('election.devices.scanner.driver', 'camera');
    config()->set('election.devices.scanner.camera.name', 'Browser Camera Test');

    app(ActivateSamplePackage::class)->handle();
    app(LifecycleState::class)->set(Lifecycle::Counting);

    $page = visit('/election/counting')
        ->assertSee('Camera Capture')
        ->assertSee('Accepted 0')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $page->script(browserMediaDeniedShim());

    $page->click('Start Camera')
        ->assertSee('Camera permission was denied or unavailable.')
        ->assertSee('Accepted 0')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(app(ElectionStorage::class)->files('counting/accepted'))->toHaveCount(0);
});

test('operator sees rejected feedback when camera frame has no decodable qr code', function (): void {
    config()->set('election.devices.scanner.driver', 'camera');
    config()->set('election.devices.scanner.camera.name', 'Browser Camera Test');

    app(ActivateSamplePackage::class)->handle();
    app(LifecycleState::class)->set(Lifecycle::Counting);

    $page = visit('/election/counting')
        ->assertSee('Camera Capture')
        ->assertSee('Accepted 0')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $page->script(browserMediaCaptureShim(browserInvalidQrPngDataUri()));

    $page->click('Start Camera')
        ->assertSee('Camera ready.')
        ->click('Capture Scan')
        ->wait(1)
        ->assertSee('Scan Rejected')
        ->assertSee('Unable to decode QR artifact')
        ->assertSee('scanner-decode')
        ->assertSee('Rejected 1')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(app(ElectionStorage::class)->files('counting/accepted'))->toHaveCount(0)
        ->and(app(ElectionStorage::class)->files('counting/rejected'))->toHaveCount(1);
});

function browserInvalidQrPngDataUri(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGOSHzRgAAAAABJRU5ErkJggg==';
}
