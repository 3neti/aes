<?php

namespace App\Console\Commands;

use App\Election\PublicSimulation\CanvassingScannerIngestion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:canvassing-scanner-ingest
    {--station-id=canvassing-demo-city : Scanner station identifier}
    {--source=scanner_bridge : Source stored with each scan event}
    {--payload=* : QR payload text. Use multiple times, or pipe newline-delimited payloads through STDIN.}')]
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
        $payloads = $this->payloads();

        if ($payloads === []) {
            $this->error('No QR payloads were provided.');

            return self::FAILURE;
        }

        $hasRejectedPayload = false;

        foreach ($payloads as $payload) {
            $result = $ingestion->ingest($payload, $stationId, $source);
            $event = $result['event'];
            $status = (string) ($event['status'] ?? 'unknown');
            $message = (string) ($result['state']['latest_message'] ?? $event['meta'] ?? 'Recorded scan.');

            $this->line(strtoupper($status).': '.$message);

            if ($status === 'rejected') {
                $hasRejectedPayload = true;
            }
        }

        return $hasRejectedPayload ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function payloads(): array
    {
        $payloads = collect((array) $this->option('payload'))
            ->map(fn (mixed $payload): string => trim((string) $payload))
            ->filter()
            ->values()
            ->all();

        if ($payloads !== [] || stream_isatty(STDIN)) {
            return $payloads;
        }

        return collect(explode(PHP_EOL, (string) stream_get_contents(STDIN)))
            ->map(fn (string $payload): string => trim($payload))
            ->filter()
            ->values()
            ->all();
    }
}
