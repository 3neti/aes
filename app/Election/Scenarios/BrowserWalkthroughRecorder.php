<?php

namespace App\Election\Scenarios;

use Illuminate\Support\Facades\Process;
use Throwable;

final class BrowserWalkthroughRecorder
{
    /**
     * @return array<string, mixed>
     */
    public function record(
        string $scenario,
        string $baseUrl,
        string $token,
        string $artifactDirectory,
        int $ballots,
        bool $headed,
        int $slowMotion,
    ): array {
        try {
            $process = Process::path(base_path())
                ->timeout(900)
                ->env([
                    'ELECTION_WALKTHROUGH_SCENARIO' => $scenario,
                    'ELECTION_WALKTHROUGH_BASE_URL' => rtrim($baseUrl, '/'),
                    'ELECTION_WALKTHROUGH_TOKEN' => $token,
                    'ELECTION_WALKTHROUGH_ARTIFACT_DIR' => $artifactDirectory,
                    'ELECTION_WALKTHROUGH_BALLOTS' => (string) $ballots,
                    'ELECTION_WALKTHROUGH_HEADED' => $headed ? '1' : '0',
                    'ELECTION_WALKTHROUGH_SLOW_MO' => (string) $slowMotion,
                    'ELECTION_WALKTHROUGH_GHOSTSCRIPT' => (string) config('election.pdf.ghostscript_binary', 'gs'),
                ])
                ->run([
                    'node',
                    base_path('scripts/election-browser-walkthrough.mjs'),
                ]);
        } catch (Throwable $exception) {
            return $this->failedResult($exception->getMessage());
        }

        $decoded = json_decode(trim($process->output()), true);

        if (! is_array($decoded)) {
            return $this->failedResult(
                trim($process->errorOutput()) ?: 'The browser recorder returned an invalid report.',
                $process->exitCode(),
            );
        }

        return [
            ...$decoded,
            'passed' => $process->successful() && ($decoded['passed'] ?? false) === true,
            'process_exit_code' => $process->exitCode(),
            'process_error_output' => trim($process->errorOutput()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failedResult(string $message, ?int $exitCode = null): array
    {
        return [
            'schema_version' => 'browser-walkthrough-recorder-result-1',
            'passed' => false,
            'process_exit_code' => $exitCode,
            'process_error_output' => $message,
            'error' => $message,
            'artifacts' => [],
            'statistics' => [],
        ];
    }
}
