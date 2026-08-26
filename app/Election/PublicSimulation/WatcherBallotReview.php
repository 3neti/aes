<?php

namespace App\Election\PublicSimulation;

use App\Election\Counting\TallyPresentation;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\BallotQrPayload;
use Illuminate\Support\Facades\Crypt;

final class WatcherBallotReview
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly BallotQrPayload $qrPayload,
        private readonly TallyPresentation $presentation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(bool $allowed, bool $downloadEnabled): array
    {
        $records = $allowed ? $this->records() : [];
        $ballots = [];
        $runningTally = [];

        foreach ($records as $record) {
            $decoded = $this->decodeRecordPayload($record);
            $selections = (array) ($decoded['selections'] ?? []);
            $this->addSelectionsToTally($runningTally, $selections);

            $ballots[] = [
                'sequence' => (int) ($record['sequence'] ?? (count($ballots) + 1)),
                'ballot_id' => (string) ($record['ballot_id'] ?? ''),
                'paper_ballot_serial' => $record['paper_ballot_serial'] ?? null,
                'payload_hash' => (string) ($record['payload_hash'] ?? ($decoded['payload_hash'] ?? '')),
                'record_hash' => (string) ($record['record_hash'] ?? ''),
                'deposited_at' => $record['deposited_at'] ?? null,
                'qr_decode_status' => $decoded === [] ? 'unreadable' : 'decoded',
                'qr_payload_hash' => $decoded['payload_hash'] ?? null,
                'selected_candidates' => $this->selectedCandidates((array) ($decoded['selections'] ?? [])),
                'this_ballot_tally' => $this->presentation->displayTally($this->tallyFromSelections($selections)),
                'cumulative_tally' => $this->presentation->displayTally($runningTally),
                'pdf_available' => $this->pdfAvailable($record),
                'pdf_url' => null,
            ];
        }

        return [
            'enabled' => (bool) config('election.public_simulation.watcher_ballot_viewer.enabled', true),
            'allowed' => $allowed,
            'download_enabled' => $downloadEnabled,
            'qr_audit_tally_enabled' => (bool) config('election.public_simulation.watcher_ballot_viewer.qr_audit_tally_enabled', true),
            'record_count' => count($ballots),
            'ballots' => $ballots,
            'qr_audit_tally' => $this->presentation->displayTally($runningTally),
        ];
    }

    public function pdfPath(int $sequence): ?string
    {
        $record = collect($this->records())->firstWhere('sequence', $sequence);

        if (! is_array($record)) {
            return null;
        }

        $job = $this->storage->readJson('print-jobs/'.($record['ballot_id'] ?? '').'.json');
        $path = $job['pdf_artifact_path'] ?? $job['selected_pdf_artifact_path'] ?? null;

        if (! is_string($path) || ! is_file($path)) {
            return null;
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function pdfAvailable(array $record): bool
    {
        $job = $this->storage->readJson('print-jobs/'.($record['ballot_id'] ?? '').'.json');
        $path = $job['pdf_artifact_path'] ?? $job['selected_pdf_artifact_path'] ?? null;

        return is_string($path) && is_file($path);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function records(): array
    {
        return collect($this->storage->files('counting/sealed'))
            ->map(function (string $path): array {
                $record = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

                return $record;
            })
            ->sortBy([
                ['deposited_at', 'asc'],
                ['paper_ballot_serial', 'asc'],
                ['payload_hash', 'asc'],
            ])
            ->values()
            ->map(function (array $record, int $index): array {
                $record['sequence'] = $index + 1;

                return $record;
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function decodeRecordPayload(array $record): array
    {
        try {
            return $this->qrPayload->decode(Crypt::decryptString((string) ($record['encrypted_payload'] ?? '')));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, array<int, string>>  $selections
     * @return array<int, array{contest_id: string, contest_title: string, candidates: array<int, array{id: string, name: string}>}>
     */
    private function selectedCandidates(array $selections): array
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');

        return collect($configuration['contests'] ?? [])
            ->filter(fn (mixed $contest): bool => is_array($contest))
            ->map(function (array $contest) use ($selections): array {
                $contestId = (string) ($contest['id'] ?? '');
                $candidates = collect($contest['candidates'] ?? [])
                    ->filter(fn (mixed $candidate): bool => is_array($candidate))
                    ->keyBy(fn (array $candidate): string => (string) ($candidate['id'] ?? ''));

                return [
                    'contest_id' => $contestId,
                    'contest_title' => (string) ($contest['title'] ?? $contestId),
                    'candidates' => collect($selections[$contestId] ?? [])
                        ->map(function (mixed $candidateId) use ($candidates): array {
                            $candidate = $candidates->get((string) $candidateId, []);

                            return [
                                'id' => (string) $candidateId,
                                'name' => trim(implode(' ', array_filter([
                                    $candidate['ballot_number'] ?? null,
                                    $candidate['name'] ?? $candidateId,
                                ]))),
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->filter(fn (array $contest): bool => $contest['candidates'] !== [])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<int, string>>  $selections
     * @return array<string, array<string, int>>
     */
    private function tallyFromSelections(array $selections): array
    {
        $tally = [];
        $this->addSelectionsToTally($tally, $selections);

        return $tally;
    }

    /**
     * @param  array<string, array<string, int>>  $tally
     * @param  array<string, array<int, string>>  $selections
     */
    private function addSelectionsToTally(array &$tally, array $selections): void
    {
        foreach ($selections as $contestId => $candidateIds) {
            if (! is_array($candidateIds)) {
                continue;
            }

            foreach ($candidateIds as $candidateId) {
                $contestKey = (string) $contestId;
                $candidateKey = (string) $candidateId;
                $tally[$contestKey][$candidateKey] = ($tally[$contestKey][$candidateKey] ?? 0) + 1;
            }
        }
    }
}
