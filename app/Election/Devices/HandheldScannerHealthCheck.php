<?php

namespace App\Election\Devices;

final class HandheldScannerHealthCheck implements DeviceHealthCheck
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
                'adapter' => 'handheld-keyboard-wedge',
                'status' => 'not-configured',
                'capabilities' => ['qr-payload', 'keyboard-wedge'],
                'detail' => 'No handheld scanner name configured.',
            ];
        }

        return [
            'adapter' => 'handheld-keyboard-wedge',
            'status' => 'ready',
            'capabilities' => ['qr-payload', 'keyboard-wedge'],
            'device' => $this->deviceName,
        ];
    }
}
