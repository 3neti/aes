<?php

use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\BallotPayloadService;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
});

test('operator can submit a camera capture frame through the counting ceremony', function (): void {
    config()->set('election.devices.scanner.driver', 'camera');
    config()->set('election.devices.scanner.camera.name', 'Browser Camera Test');

    app(ActivateSamplePackage::class)->handle();

    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana'],
    ], 'browser-camera-ballot-001');

    $qrDataUri = json_encode(
        'data:image/png;base64,'.base64_encode(file_get_contents($payload['qr_artifact_path'])),
        JSON_THROW_ON_ERROR,
    );

    $page = visit('/election/counting')
        ->assertSee('Camera Capture')
        ->assertSee('Accepted 0')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $page->script(<<<JS
        () => {
            const qrDataUri = {$qrDataUri};

            Object.defineProperty(navigator, 'mediaDevices', {
                configurable: true,
                value: {
                    getUserMedia: async () => new MediaStream(),
                },
            });

            Object.defineProperty(HTMLVideoElement.prototype, 'videoWidth', {
                configurable: true,
                get: () => 640,
            });

            Object.defineProperty(HTMLVideoElement.prototype, 'videoHeight', {
                configurable: true,
                get: () => 480,
            });

            HTMLVideoElement.prototype.play = async () => undefined;

            HTMLCanvasElement.prototype.getContext = () => ({
                drawImage: () => undefined,
            });

            HTMLCanvasElement.prototype.toDataURL = () => qrDataUri;

            return true;
        }
    JS);

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

    $page = visit('/election/counting')
        ->assertSee('Camera Capture')
        ->assertSee('Accepted 0')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $page->script(<<<'JS'
        () => {
            Object.defineProperty(navigator, 'mediaDevices', {
                configurable: true,
                value: {
                    getUserMedia: async () => {
                        throw new DOMException('Permission denied', 'NotAllowedError');
                    },
                },
            });

            return true;
        }
    JS);

    $page->click('Start Camera')
        ->assertSee('Camera permission was denied or unavailable.')
        ->assertSee('Accepted 0')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(app(ElectionStorage::class)->files('counting/accepted'))->toHaveCount(0);
});
