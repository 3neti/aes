<?php

namespace App\Election\Devices;

use Illuminate\Support\Facades\Process;
use Throwable;

final class CupsPrinterHealthCheck implements DeviceHealthCheck
{
    public function __construct(
        private readonly string $printerName,
        private readonly int $timeoutSeconds = 3,
    ) {}

    public function deviceType(): string
    {
        return 'printer';
    }

    /**
     * @return array<string, mixed>
     */
    public function check(): array
    {
        if ($this->printerName === '') {
            return [
                'adapter' => 'cups-printer',
                'status' => 'not-configured',
                'capabilities' => ['cups-status'],
                'detail' => 'No CUPS printer name configured.',
            ];
        }

        try {
            $result = Process::timeout($this->timeoutSeconds)->run(['lpstat', '-p', $this->printerName]);
        } catch (Throwable $exception) {
            return [
                'adapter' => 'cups-printer',
                'status' => 'unavailable',
                'capabilities' => ['cups-status'],
                'detail' => $exception->getMessage(),
                'printer' => $this->printerName,
            ];
        }

        return [
            'adapter' => 'cups-printer',
            'status' => $result->successful() ? 'ready' : 'unavailable',
            'capabilities' => ['cups-status'],
            'detail' => trim($result->output() ?: $result->errorOutput()),
            'exit_code' => $result->exitCode(),
            'printer' => $this->printerName,
        ];
    }
}
