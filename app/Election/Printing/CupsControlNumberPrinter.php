<?php

namespace App\Election\Printing;

use App\Election\Core\ActivityJournal;
use App\Election\Support\ElectionStorage;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

final class CupsControlNumberPrinter implements ControlNumberPrinter
{
    public function __construct(
        private readonly FileControlNumberReceiptPrinter $files,
        private readonly ElectionStorage $storage,
        private readonly ActivityJournal $journal,
        private readonly string $printerName,
        private readonly int $timeoutSeconds = 10,
    ) {}

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    public function print(array $release): array
    {
        if ($this->printerName === '') {
            return $this->printWithFileFallback($release, 'cups-printer-not-configured');
        }

        $job = $this->files->print($release);
        $artifactPath = $job['pdf_artifact_path'] ?? null;

        if (! is_string($artifactPath) || $artifactPath === '') {
            throw new RuntimeException('No printable control number receipt was generated for CUPS submission.');
        }

        $releaseId = (string) ($release['release_id'] ?? '');
        $result = $this->submit($artifactPath, $releaseId);

        $job['printer'] = 'cups';
        $job['printer_name'] = $this->printerName;
        $job['cups_artifact_path'] = $artifactPath;
        $job['cups_command'] = ['lp', '-d', $this->printerName, '-t', 'AES Control Number '.$releaseId, $artifactPath];
        $job['cups_exit_code'] = $result['exit_code'];
        $job['cups_output'] = $result['output'];
        $job['status'] = $result['successful'] ? 'submitted' : 'failed';

        $this->storage->writeJson("print-jobs/control-number/{$releaseId}.json", $job);
        $this->journal->record($result['successful'] ? 'control_number.print_submitted' : 'control_number.print_failed', [
            'release_id' => $releaseId,
            'printer' => 'cups',
            'printer_name' => $this->printerName,
            'status' => $job['status'],
        ]);

        return $job;
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    private function printWithFileFallback(array $release, string $reason): array
    {
        $job = $this->files->print($release);
        $job['printer_fallback_reason'] = $reason;
        $job['requested_cups_printer'] = $this->printerName;

        $releaseId = (string) ($release['release_id'] ?? '');
        $this->storage->writeJson("print-jobs/control-number/{$releaseId}.json", $job);
        $this->journal->record('control_number.print_fallback', [
            'release_id' => $releaseId,
            'printer' => 'file',
            'requested_cups_printer' => $this->printerName,
            'reason' => $reason,
        ]);

        return $job;
    }

    /**
     * @return array{successful: bool, exit_code: int|null, output: string}
     */
    private function submit(string $artifactPath, string $releaseId): array
    {
        try {
            $result = Process::timeout($this->timeoutSeconds)->run([
                'lp',
                '-d',
                $this->printerName,
                '-t',
                'AES Control Number '.$releaseId,
                $artifactPath,
            ]);
        } catch (Throwable $exception) {
            return [
                'successful' => false,
                'exit_code' => null,
                'output' => $exception->getMessage(),
            ];
        }

        return [
            'successful' => $result->successful(),
            'exit_code' => $result->exitCode(),
            'output' => trim($result->output() ?: $result->errorOutput()),
        ];
    }
}
