<?php

namespace App\Console\Commands;

use App\Election\PublicSimulation\CanvassingScannerIngestion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:canvassing-scanner-ingest
    {--station-id=canvassing-demo-city : Scanner station identifier}
    {--source=scanner_bridge : Source stored with each scan event}
    {--payload=* : QR payload text. Use multiple times, or pipe/type newline-delimited payloads through STDIN.}')]
#[Description('Record WAES election return QR payloads from a scanner bridge.')]
final class IngestCanvassingScannerPayloads extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CanvassingScannerIngestion $ingestion): int
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

        $this->line('Canvassing scanner listener ready. Scan ER QR codes now. Press Ctrl+C to stop.');

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
    private function ingestBatch(array $payloads, string $stationId, string $source, CanvassingScannerIngestion $ingestion): int
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
     * Byte value some keyboard-wedge scanners send as a scan terminator
     * instead of Enter (CR/LF). On an interactive TTY with signal
     * generation enabled, this byte is normally intercepted by the
     * terminal driver as SIGINT before it ever reaches PHP, so callers
     * running this command live must first disable it (e.g. `stty -isig`).
     */
    private const EtxTerminator = "\x03";

    /**
     * Reads payloads from the given stream, ingesting each one as soon as
     * a terminator is seen. Used both for piped STDIN batches and for a
     * live, interactive keyboard-wedge scanner session. Payloads may be
     * terminated by CR, LF, or a raw ETX (0x03) byte, since some scanners
     * are configured to send a control character instead of Enter.
     *
     * @param  resource  $stream
     */
    private function ingestFromStream($stream, string $stationId, string $source, CanvassingScannerIngestion $ingestion, bool $requirePayloads): int
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

    private function ingestOne(string $payload, string $stationId, string $source, CanvassingScannerIngestion $ingestion): bool
    {
        $result = $ingestion->ingest($payload, $stationId, $source);
        $event = $result['event'];
        $status = (string) ($event['status'] ?? 'unknown');
        $message = (string) ($result['state']['latest_message'] ?? $event['meta'] ?? 'Recorded scan.');

        $this->line(strtoupper($status).': '.$message);

        return $status !== 'rejected';
    }
}
