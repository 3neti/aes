<?php

namespace App\Election\Runtime;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

final class CupsQueueReadiness
{
    /**
     * @return list<array{id: string, label: string, status: string, required: bool, detail: string}>
     */
    public function inspect(): array
    {
        $checks = [];

        foreach ($this->configuredPrinters() as $printer) {
            if ($printer['driver'] === 'cups' && $printer['name'] === '') {
                $checks[] = $this->result(
                    'cups_'.Str::slug($printer['purpose'], '_').'_configuration',
                    'CUPS '.$printer['purpose'].' printer',
                    'fail',
                    'CUPS printing is enabled, but no queue name is configured.',
                );
            }
        }

        foreach ($this->configuredQueues() as $printerName => $purposes) {
            $checks[] = $this->inspectQueue($printerName, $purposes);
        }

        return $checks;
    }

    /**
     * @param  list<string>  $purposes
     * @return array{id: string, label: string, status: string, required: bool, detail: string}
     */
    private function inspectQueue(string $printerName, array $purposes): array
    {
        $label = 'CUPS '.implode(' / ', $purposes).' printer';
        $id = 'cups_'.Str::slug($printerName, '_');
        $timeout = max(1, (int) config('election.runtime.cups_timeout_seconds', 3));

        try {
            $status = Process::timeout($timeout)->run(['lpstat', '-p', $printerName, '-l']);

            if (! $status->successful()) {
                return $this->result($id, $label, 'fail', $this->processDetail($status->output(), $status->errorOutput()));
            }

            $statusDetail = trim($status->output());

            if ($this->indicatesBlockedPrinter($statusDetail)) {
                return $this->result($id, $label, 'fail', $statusDetail);
            }

            $jobs = Process::timeout($timeout)->run(['lpstat', '-W', 'not-completed', '-o', $printerName]);

            if (! $jobs->successful()) {
                return $this->result($id, $label, 'fail', $this->processDetail($jobs->output(), $jobs->errorOutput()));
            }

            $pendingJobs = collect(preg_split('/\R/', trim($jobs->output())) ?: [])
                ->filter(fn (string $line): bool => trim($line) !== '')
                ->count();

            if ($pendingJobs > 0) {
                return $this->result(
                    $id,
                    $label,
                    'warn',
                    $printerName.' is reachable but has '.$pendingJobs.' unfinished print '.Str::plural('job', $pendingJobs).'.',
                );
            }

            return $this->result($id, $label, 'pass', $statusDetail ?: $printerName.' is ready.');
        } catch (Throwable $exception) {
            return $this->result($id, $label, 'fail', $exception->getMessage());
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function configuredQueues(): array
    {
        $queues = [];

        foreach ($this->configuredPrinters() as $printer) {
            if ($printer['driver'] !== 'cups' || $printer['name'] === '') {
                continue;
            }

            $queues[$printer['name']] ??= [];
            $queues[$printer['name']][] = $printer['purpose'];
        }

        return $queues;
    }

    /**
     * @return list<array{driver: string, name: string, purpose: string}>
     */
    private function configuredPrinters(): array
    {
        return [
            [
                'driver' => (string) config('election.devices.printer.driver', 'file'),
                'name' => (string) config('election.devices.printer.cups.name', ''),
                'purpose' => 'ballot',
            ],
            [
                'driver' => (string) config('election.control_number_printer.driver', 'file'),
                'name' => (string) config('election.control_number_printer.cups.name', ''),
                'purpose' => 'control number',
            ],
            [
                'driver' => (string) config('election.closeout_printer.driver', 'file'),
                'name' => (string) config('election.closeout_printer.cups.name', ''),
                'purpose' => 'closeout',
            ],
        ];
    }

    private function indicatesBlockedPrinter(string $detail): bool
    {
        return Str::of($detail)->lower()->contains([
            'disabled',
            'not accepting',
            'unable to',
            'waiting for printer to become available',
            'printer not responding',
        ]);
    }

    /**
     * @return array{id: string, label: string, status: string, required: bool, detail: string}
     */
    private function result(string $id, string $label, string $status, string $detail): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'required' => true,
            'detail' => $detail !== '' ? $detail : 'CUPS did not return printer status.',
        ];
    }

    private function processDetail(string $output, string $errorOutput): string
    {
        return trim($output) ?: trim($errorOutput);
    }
}
