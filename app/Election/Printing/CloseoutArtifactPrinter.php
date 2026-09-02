<?php

namespace App\Election\Printing;

use App\Election\Core\ActivityJournal;
use App\Election\Returns\ElectionReturnScope;
use App\Election\Support\ElectionStorage;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

final class CloseoutArtifactPrinter
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'driver' => $this->driver(),
            'default_profile' => $this->defaultProfile(),
            'printer_name' => $this->printerName(),
            'enabled' => $this->driver() !== 'disabled',
            'submit_label' => $this->driver() === 'cups' ? 'Send to printer' : 'Prepare for local print',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function print(string $artifact, PrintFormProfile $profile, string $precinctCode, string $precinctId): array
    {
        $artifact = $this->artifact($artifact);
        $label = $this->label($artifact);
        $path = $this->artifactPath($artifact, $profile, $precinctId);
        $driver = $this->driver();

        $result = [
            'schema_version' => 'closeout-print-job-1',
            'artifact' => $artifact,
            'artifact_label' => $label,
            'profile' => $profile->value,
            'profile_label' => $profile->label(),
            'precinct_code' => $precinctCode,
            'precinct_id' => $precinctId,
            'driver' => $driver,
            'printer' => $driver === 'cups' ? $this->printerName() : null,
            'pdf_path' => $path,
            'requested_at' => now()->toIso8601String(),
        ];

        $this->journal->record('closeout.print_requested', [
            'artifact' => $artifact,
            'profile' => $profile->value,
            'driver' => $driver,
            'precinct_code' => $precinctCode,
        ]);

        if ($driver === 'disabled') {
            return $this->finish($result, 'disabled', 'Physical closeout printing is disabled.');
        }

        if (! is_file($path)) {
            return $this->finish($result, 'missing', "{$label} PDF is not available for {$profile->label()}.");
        }

        if ($driver === 'file') {
            return $this->finish($result, 'manual', "{$label} {$profile->label()} PDF is ready for browser printing.");
        }

        if ($driver !== 'cups') {
            return $this->finish($result, 'failed', "Unsupported closeout printer driver [{$driver}].");
        }

        if ($this->printerName() === '') {
            return $this->finish($result, 'failed', 'CUPS printer name is not configured.');
        }

        $submission = $this->submitToCups($path, "AES {$precinctCode} {$label} {$profile->label()}");
        $result['cups_command'] = ['lp', '-d', $this->printerName(), '-t', "AES {$precinctCode} {$label} {$profile->label()}", $path];
        $result['cups_exit_code'] = $submission['exit_code'];
        $result['cups_output'] = $submission['output'];

        return $this->finish(
            $result,
            $submission['successful'] ? 'submitted' : 'failed',
            $submission['successful']
                ? "{$label} submitted to {$this->printerName()}."
                : ($submission['output'] !== '' ? $submission['output'] : "{$label} could not be submitted to {$this->printerName()}."),
        );
    }

    private function driver(): string
    {
        $driver = (string) config('election.closeout_printer.driver', 'file');

        return in_array($driver, ['file', 'cups', 'disabled'], true) ? $driver : 'file';
    }

    private function printerName(): string
    {
        return (string) config('election.closeout_printer.cups.name', '');
    }

    private function defaultProfile(): string
    {
        $profile = (string) config('election.closeout_printer.default_profile', PrintFormProfile::A4->value);

        return PrintFormProfile::tryFrom($profile)?->value ?? PrintFormProfile::A4->value;
    }

    private function artifact(string $artifact): string
    {
        if (! in_array($artifact, ['tally-sheet', 'election-return', 'election-return-combined', 'election-return-national', 'election-return-local'], true)) {
            throw new RuntimeException("Unsupported closeout print artifact [{$artifact}].");
        }

        return $artifact;
    }

    private function label(string $artifact): string
    {
        return match ($artifact) {
            'tally-sheet' => 'Tally sheet',
            'election-return', 'election-return-combined' => 'Combined Election Return',
            'election-return-national' => 'National Election Return',
            'election-return-local' => 'Local Election Return',
            default => throw new RuntimeException("Unsupported closeout print artifact [{$artifact}]."),
        };
    }

    private function artifactPath(string $artifact, PrintFormProfile $profile, string $precinctId): string
    {
        return match ($artifact) {
            'tally-sheet' => $this->storage->path("print-forms/tally-sheet/{$profile->value}.pdf"),
            'election-return', 'election-return-combined' => $this->storage->path("print-forms/election-return/{$precinctId}/{$profile->value}.pdf"),
            'election-return-national', 'election-return-local' => $this->storage->path("print-forms/election-return/{$precinctId}/".$this->scopeFromArtifact($artifact)->value."/{$profile->value}.pdf"),
            default => throw new RuntimeException("Unsupported closeout print artifact [{$artifact}]."),
        };
    }

    private function scopeFromArtifact(string $artifact): ElectionReturnScope
    {
        return match ($artifact) {
            'election-return-national' => ElectionReturnScope::National,
            'election-return-local' => ElectionReturnScope::Local,
            default => ElectionReturnScope::Combined,
        };
    }

    /**
     * @return array{successful: bool, exit_code: int|null, output: string}
     */
    private function submitToCups(string $path, string $title): array
    {
        try {
            $result = Process::timeout((int) config('election.closeout_printer.cups.timeout', 10))
                ->run(['lp', '-d', $this->printerName(), '-t', $title, $path]);
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

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function finish(array $result, string $status, string $message): array
    {
        $result['status'] = $status;
        $result['message'] = $message;
        $result['finished_at'] = now()->toIso8601String();

        $path = $this->storage->writeJson(
            "print-jobs/closeout/{$result['artifact']}-{$result['profile']}-".now()->format('YmdHis').'.json',
            $result,
        );
        $result['job_path'] = $path;

        $this->journal->record($status === 'submitted' || $status === 'manual'
            ? 'closeout.print_submitted'
            : 'closeout.print_failed', [
                'artifact' => $result['artifact'],
                'profile' => $result['profile'],
                'driver' => $result['driver'],
                'printer' => $result['printer'],
                'status' => $status,
                'message' => $message,
            ]);

        return $result;
    }
}
