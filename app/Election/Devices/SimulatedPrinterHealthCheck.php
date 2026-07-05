<?php

namespace App\Election\Devices;

final class SimulatedPrinterHealthCheck implements DeviceHealthCheck
{
    public function deviceType(): string
    {
        return 'printer';
    }

    /**
     * @return array<string, mixed>
     */
    public function check(): array
    {
        return [
            'adapter' => 'simulated-printer',
            'status' => 'ready',
            'capabilities' => ['file-ballot', 'pdf-artifact', 'text-artifact'],
        ];
    }
}
