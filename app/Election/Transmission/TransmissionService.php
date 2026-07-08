<?php

namespace App\Election\Transmission;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Support\SimplePdf;
use RuntimeException;

final class TransmissionService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly SimplePdf $pdf,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $return = $this->latestReturn();
        $precinct = (string) ($return['precinct_id'] ?? 'unknown');
        $attempts = $this->runAttempts();
        $passed = collect($attempts)->every(fn (array $attempt): bool => in_array(
            $attempt['status'],
            ['transmitted', 'deferred'],
            true,
        ));
        $transmission = [
            'schema_version' => 'transmission-report-1',
            'transmission_id' => 'transmission-'.$this->clock->now()->format('YmdHis').'-'.substr((string) hash('sha256', (string) ($return['return_hash'] ?? 'unknown')), 0, 8),
            'precinct_id' => $precinct,
            'election_id' => $return['election_id'] ?? null,
            'mapping_hash' => $return['mapping_hash'] ?? null,
            'return_hash' => $return['return_hash'] ?? null,
            'return_path' => $return['artifact_path'] ?? null,
            'mode' => (string) config('election.transmission.mode', 'deferred'),
            'attempts' => $attempts,
            'attempt_count' => count($attempts),
            'passed' => $passed,
            'generated_at' => $this->clock->now()->toIso8601String(),
        ];

        $transmission['transmission_hash'] = $this->json->hash($transmission);

        $transmission['artifact_path'] = $this->storage->writeJson('transmission/transmission-report.json', $transmission);
        $this->storage->writeText('transmission/transmission-report.txt', $this->renderText($transmission));
        $this->storage->writeText('transmission/transmission-report.pdf', $this->pdf->render('Transmission Report', $this->renderPdfLines($transmission)));

        $this->journal->record('transmission.completed', [
            'transmission_id' => $transmission['transmission_id'],
            'precinct_id' => $precinct,
            'transmission_hash' => $transmission['transmission_hash'],
            'attempt_count' => $transmission['attempt_count'],
            'passed' => $transmission['passed'],
        ]);

        return $transmission;
    }

    /**
     * @return array<string, mixed>
     */
    public function latestReport(): array
    {
        return $this->storage->readJson('transmission/transmission-report.json');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runAttempts(): array
    {
        $destinations = (array) config('election.transmission.destinations', []);

        if ($destinations === []) {
            $destinations = [
                [
                    'id' => 'election-board',
                    'name' => 'Election Board Endpoint',
                    'channel' => 'off-line-bundle',
                ],
            ];
        }

        return collect($destinations)
            ->values()
            ->map(fn (array $destination, int $index): array => $this->attempt($destination, $index + 1))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $destination
     * @return array<string, mixed>
     */
    private function attempt(array $destination, int $index): array
    {
        $id = (string) ($destination['id'] ?? null);

        if ($id === '') {
            throw new RuntimeException('Transmission destination id is required.');
        }

        $status = (string) ($this->forcedStatus($id) ?: $this->statusForMode());

        return [
            'sequence' => $index,
            'destination_id' => $id,
            'destination_name' => (string) ($destination['name'] ?? $id),
            'channel' => (string) ($destination['channel'] ?? 'offline'),
            'status' => $status,
            'attempted_at' => $this->clock->now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function latestReturn(): array
    {
        $return = $this->storage->readJson('returns/'.$this->activePrecinct().'-return.json');

        if ($return === []) {
            $return = $this->storage->readJson('returns/return.json');
        }

        if ($return === []) {
            throw new RuntimeException('No election return artifact exists yet.');
        }

        $return['artifact_path'] = $this->storage->path('returns/'.((string) ($return['precinct_id'] ?? $this->activePrecinct()).'-return.json'));

        return $return;
    }

    private function activePrecinct(): string
    {
        return (string) ($this->storage->readJson('runtime/active-precinct.json', ['precinct_id' => '0421-A'])['precinct_id'] ?? '0421-A');
    }

    private function forcedStatus(string $destinationId): ?string
    {
        $forced = (array) config('election.transmission.force_statuses', []);

        return $forced[$destinationId] ?? null;
    }

    private function statusForMode(): string
    {
        $mode = (string) config('election.transmission.mode', 'deferred');

        return (string) config("election.transmission.status_by_mode.{$mode}", 'deferred');
    }

    /**
     * @param  array<string, mixed>  $transmission
     */
    private function renderText(array $transmission): string
    {
        $text = "TRANSMISSION REPORT\n";
        $text .= "Transmission: {$transmission['transmission_id']}\n";
        $text .= "Precinct: {$transmission['precinct_id']}\n";
        $text .= "Mode: {$transmission['mode']}\n";
        $text .= "Passed: ".($transmission['passed'] ? 'yes' : 'no')."\n";
        $text .= "Return Hash: {$transmission['return_hash']}\n";
        $text .= "Transmission Hash: {$transmission['transmission_hash']}\n\n";

        foreach ($transmission['attempts'] as $attempt) {
            $text .= sprintf(
                "%s [%s] %s\n",
                $attempt['destination_name'],
                $attempt['destination_id'],
                $attempt['status'],
            );
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $transmission
     * @return array<int, string>
     */
    private function renderPdfLines(array $transmission): array
    {
        $lines = [
            'Precinct: '.$transmission['precinct_id'],
            'Mode: '.$transmission['mode'],
            'Election: '.($transmission['election_id'] ?? 'unknown'),
            'Passed: '.($transmission['passed'] ? 'yes' : 'no'),
            'Transmission Hash: '.$transmission['transmission_hash'],
            '',
            'Destinations:',
        ];

        foreach ($transmission['attempts'] as $attempt) {
            $lines[] = sprintf(
                '%s (%s): %s',
                $attempt['destination_name'],
                $attempt['destination_id'],
                $attempt['status'],
            );
        }

        return $lines;
    }
}
