<?php

namespace App\Election\Printing;

use App\Election\Core\ActivityJournal;
use App\Election\Support\ElectionStorage;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

final class CupsBallotPrinter implements BallotPrinter
{
    public function __construct(
        private readonly FileBallotPrinter $files,
        private readonly ElectionStorage $storage,
        private readonly ActivityJournal $journal,
        private readonly string $printerName,
        private readonly int $timeoutSeconds = 10,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function print(array $payload): array
    {
        if ($this->printerName === '') {
            throw new RuntimeException('CUPS printer name is not configured.');
        }

        $this->ensureCertified();

        $job = $this->files->print($payload);
        $artifactPath = $job['pdf_artifact_path'] ?? $job['artifact_path'] ?? null;

        if (! is_string($artifactPath) || $artifactPath === '') {
            throw new RuntimeException('No printable artifact was generated for CUPS submission.');
        }

        $result = $this->submit($artifactPath, (string) $payload['ballot_id']);

        $job['printer'] = 'cups';
        $job['printer_name'] = $this->printerName;
        $job['cups_artifact_path'] = $artifactPath;
        $job['cups_command'] = ['lp', '-d', $this->printerName, '-t', 'AES Ballot '.$payload['ballot_id'], $artifactPath];
        $job['cups_exit_code'] = $result['exit_code'];
        $job['cups_output'] = $result['output'];
        $job['status'] = $result['successful'] ? 'submitted' : 'failed';

        $this->storage->writeJson("print-jobs/{$payload['ballot_id']}.json", $job);
        $this->journal->record($result['successful'] ? 'ballot.print_submitted' : 'ballot.print_failed', [
            'ballot_id' => $job['ballot_id'],
            'payload_hash' => $job['payload_hash'],
            'printer' => 'cups',
            'printer_name' => $this->printerName,
            'status' => $job['status'],
        ]);

        return $job;
    }

    private function ensureCertified(): void
    {
        $report = $this->storage->readJson('certification/device-certification-report.json');
        $printer = $report['devices']['printer'] ?? [];

        if (
            ($report['passed'] ?? false) !== true
            || ($printer['adapter'] ?? null) !== 'cups-printer'
            || ($printer['status'] ?? null) !== 'ready'
            || ($printer['printer'] ?? null) !== $this->printerName
        ) {
            throw PrinterCertificationRequired::forCupsPrinter($this->printerName);
        }
    }

    /**
     * @return array{successful: bool, exit_code: int|null, output: string}
     */
    private function submit(string $artifactPath, string $ballotId): array
    {
        try {
            $result = Process::timeout($this->timeoutSeconds)->run([
                'lp',
                '-d',
                $this->printerName,
                '-t',
                'AES Ballot '.$ballotId,
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
