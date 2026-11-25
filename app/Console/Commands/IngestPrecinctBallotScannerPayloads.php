<?php

namespace App\Console\Commands;

use App\Election\PublicSimulation\RoleDemoPrecinctTallyIngestion;
use App\Election\Support\ElectionRunScopeLock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;

#[Signature('election:precinct-ballot-scanner-ingest
    {--station-id=role-demo-precinct : Scanner station identifier}
    {--source=scanner_bridge : Source stored with each scan event}
    {--payload=* : Ballot QR payload text. Use multiple times, or pipe/type newline-delimited payloads through STDIN.}')]
#[Description('Record WAES ballot QR payloads from a precinct scanner bridge.')]
final class IngestPrecinctBallotScannerPayloads extends Command
{
    private const EtxTerminator = "\x03";

    /**
     * Execute the console command.
     */
    public function handle(RoleDemoPrecinctTallyIngestion $ingestion): int
    {
        $stationId = (string) $this->option('station-id');
        $source = (string) $this->option('source');
        $payloadOptions = $this->payloadOptions();

        if ($payloadOptions !== []) {
            return $this->ingestBatch($payloadOptions, $stationId, $source, $ingestion);
        }

        if (! stream_isatty(STDIN)) {
            return $this->ingestFromStream(STDIN, $stationId, $source, $ingestion, requirePayloads: true);
        }

        $this->line('Precinct ballot scanner listener ready. Scan ballot QR codes now. Press Ctrl+C to stop.');

        return $this->ingestFromStream(STDIN, $stationId, $source, $ingestion, requirePayloads: false);
    }

    /**
     * @return list<string>
     */
    private function payloadOptions(): array
    {
        return collect((array) $this->option('payload'))
            ->map(fn (mixed $payload): string => trim((string) $payload))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $payloads
     */
    private function ingestBatch(array $payloads, string $stationId, string $source, RoleDemoPrecinctTallyIngestion $ingestion): int
    {
        $hasRejectedPayload = false;

        foreach ($payloads as $payload) {
            if (! $this->ingestOne($payload, $stationId, $source, $ingestion)) {
                $hasRejectedPayload = true;
            }
        }

        return $hasRejectedPayload ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  resource  $stream
     */
    private function ingestFromStream($stream, string $stationId, string $source, RoleDemoPrecinctTallyIngestion $ingestion, bool $requirePayloads): int
    {
        $hasRejectedPayload = false;
        $receivedAny = false;
        $buffer = '';

        $flush = function () use (&$buffer, $stationId, $source, $ingestion, &$hasRejectedPayload, &$receivedAny): void {
            $payload = trim($buffer);
            $buffer = '';

            if ($payload === '') {
                return;
            }

            $receivedAny = true;

            if (! $this->ingestOne($payload, $stationId, $source, $ingestion)) {
                $hasRejectedPayload = true;
            }
        };

        while (($byte = fread($stream, 1)) !== false && $byte !== '') {
            if ($byte === "\n" || $byte === "\r" || $byte === self::EtxTerminator) {
                $flush();

                continue;
            }

            $buffer .= $byte;
        }

        $flush();

        if ($requirePayloads && ! $receivedAny) {
            $this->error('No QR payloads were provided.');

            return self::FAILURE;
        }

        return $hasRejectedPayload ? self::FAILURE : self::SUCCESS;
    }

    private function ingestOne(string $payload, string $stationId, string $source, RoleDemoPrecinctTallyIngestion $ingestion): bool
    {
        $started = false;
        $operation = function () use ($ingestion, $payload, $stationId, $source, &$started): array {
            $started = true;

            return $ingestion->ingest($payload, $stationId, $source);
        };
        try {
            $result = config('election.voting_lock_driver') === 'local'
                ? app(ElectionRunScopeLock::class)->execute(function () use ($operation): array {
                    app(ElectionRunScopeLock::class)->assertNoActiveWalkthrough();

                    return $operation();
                })
                : $operation();
        } catch (LockTimeoutException $exception) {
            if ($started) {
                throw $exception;
            }
            $this->error('Scan was not recorded because the election system is busy. Retain the document and rescan when ready.');

            return false;
        } catch (\RuntimeException $exception) {
            if ($started) {
                throw $exception;
            }
            $this->error('Scan was not recorded. '.$exception->getMessage());

            return false;
        }
        $event = $result['event'];
        $status = (string) ($event['status'] ?? 'unknown');
        $message = (string) ($result['state']['latest_message'] ?? $event['meta'] ?? 'Recorded scan.');

        $this->line(strtoupper($status).': '.$message);

        return $status !== 'rejected';
    }
}
