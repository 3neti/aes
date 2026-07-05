<?php

namespace App\Election\Devices;

final class CameraScannerHealthCheck implements DeviceHealthCheck
{
    public function __construct(
        private readonly string $deviceName,
    ) {}

    public function deviceType(): string
    {
        return 'scanner';
    }

    /**
     * @return array<string, mixed>
     */
    public function check(): array
    {
        if ($this->deviceName === '') {
            return [
                'adapter' => 'camera-image',
                'status' => 'not-configured',
                'capabilities' => ['qr-png-decode', 'image-data-uri'],
                'detail' => 'No camera scanner name configured.',
            ];
        }

        return [
            'adapter' => 'camera-image',
            'status' => 'ready',
            'capabilities' => ['qr-png-decode', 'image-data-uri'],
            'device' => $this->deviceName,
        ];
    }
}
