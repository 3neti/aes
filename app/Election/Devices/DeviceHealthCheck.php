<?php

namespace App\Election\Devices;

interface DeviceHealthCheck
{
    public function deviceType(): string;

    /**
     * @return array<string, mixed>
     */
    public function check(): array;
}
