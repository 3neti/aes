<?php

namespace App\Election\Devices;

final class SimulatedScannerHealthCheck implements DeviceHealthCheck
{
    public function deviceType(): string
    {
        return 'scanner';
    }

    /**
     * @return array<string, mixed>
     */
    public function check(): array
    {
        return [
            'adapter' => 'simulated-scanner',
            'status' => 'ready',
            'capabilities' => ['qr-payload', 'qr-png-decode', 'manual-paste'],
        ];
    }
}
