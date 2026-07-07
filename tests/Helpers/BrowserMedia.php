<?php

function browserMediaCaptureShim(string $qrDataUri): string
{
    $encodedQrDataUri = json_encode($qrDataUri, JSON_THROW_ON_ERROR);

    return <<<JS
        () => {
            const qrDataUri = {$encodedQrDataUri};

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
    JS;
}

function browserMediaDeniedShim(): string
{
    return <<<'JS'
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
    JS;
}
