<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Printing\BallotPrinter;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\PrivateBallotRelease;
use App\Election\Voting\SealedBallotBox;
use App\Models\SimulationPrecinct;
use RuntimeException;

final class RoleDemoBulkBallotSeeder
{
    private const StatePath = 'runtime/role-demo-bulk-ballots.json';

    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly PrivateBallotRelease $releases,
        private readonly BallotPrinter $printer,
        private readonly SealedBallotBox $ballotBox,
        private readonly RoleDemoInterimCloseout $forms,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @return array{enabled: bool, max_count: int, chunk_size: int, target: int|null, generated: int, remaining: int, status: string, updated_at: string|null}
     */
    public function summary(): array
    {
        $state = $this->storage->readJson(self::StatePath);
        $target = isset($state['target']) ? (int) $state['target'] : null;
        $generated = count($this->storage->files('counting/sealed'));

        return [
            'enabled' => (bool) config('election.public_simulation.role_demo_bulk_ballots.enabled', true),
            'max_count' => max(1, (int) config('election.public_simulation.role_demo_bulk_ballots.max_count', 700)),
            'chunk_size' => $this->chunkSize(),
            'target' => $target,
            'generated' => $generated,
            'remaining' => $target === null ? 0 : max(0, $target - $generated),
            'status' => $state['status'] ?? 'idle',
            'updated_at' => $state['updated_at'] ?? null,
        ];
    }

    /**
     * @return array{requested: int, target: int, generated: int, generated_this_chunk: int, rendered_pdfs: int, remaining: int, status: string, accepted_ballots: int, tally_hash: string|null, return_hash: string|null}
     */
    public function generate(SimulationPrecinct $precinct, int $count): array
    {
        $this->guard($count);

        $configuration = $this->storage->readJson('runtime/active-precinct.json');

        if ($configuration === []) {
            throw new RuntimeException('No active role-demo precinct configuration is available.');
        }

        $target = $count;
        $generatedBefore = count($this->storage->files('counting/sealed'));
        $remainingBefore = max(0, $target - $generatedBefore);
        $toGenerate = min($this->chunkSize(), $remainingBefore);
        $renderedPdfLimit = max(0, (int) config('election.public_simulation.role_demo_bulk_ballots.rendered_pdf_limit', 50));
        $generatedThisChunk = 0;
        $renderedPdfs = 0;

        if ($toGenerate > 0) {
            $this->writeState($target, $generatedBefore, 'running');
        }

        for ($index = $generatedBefore + 1; $index <= $generatedBefore + $toGenerate; $index++) {
            $release = $this->releases->create(
                sprintf('role-demo-bulk-%s-%06d', $precinct->code, $index),
                $this->selections($configuration, $index),
            );

            if ($index <= $renderedPdfLimit) {
                $this->releases->print((string) $release['release_id'], $this->printer);
                $renderedPdfs++;
            } else {
                $this->releases->simulatePrintedForRoleDemo((string) $release['release_id']);
            }

            $this->ballotBox->deposit((string) $release['release_id']);
            $generatedThisChunk++;
        }

        $acceptedBallots = count($this->storage->files('counting/sealed'));
        $remaining = max(0, $target - $acceptedBallots);
        $status = $remaining === 0 ? 'complete' : 'running';
        $this->writeState($target, $acceptedBallots, $status);

        $closeout = null;

        if ($status === 'complete') {
            $closeout = $this->forms->generate($precinct, 'role-demo-bulk-ballots');
        }

        $this->journal->record('role_demo.bulk_ballots_chunk_generated', [
            'precinct_code' => $precinct->code,
            'target' => $target,
            'generated_before' => $generatedBefore,
            'generated_this_chunk' => $generatedThisChunk,
            'generated_total' => $acceptedBallots,
            'remaining' => $remaining,
            'status' => $status,
            'rendered_pdfs' => $renderedPdfs,
            'tally_hash' => $closeout['tally']['tally_hash'] ?? null,
            'return_hash' => $closeout['return']['return_hash'] ?? null,
        ]);

        return [
            'requested' => $count,
            'target' => $target,
            'generated' => $acceptedBallots,
            'generated_this_chunk' => $generatedThisChunk,
            'rendered_pdfs' => $renderedPdfs,
            'remaining' => $remaining,
            'status' => $status,
            'accepted_ballots' => $acceptedBallots,
            'tally_hash' => isset($closeout['tally']['tally_hash']) ? (string) $closeout['tally']['tally_hash'] : null,
            'return_hash' => isset($closeout['return']['return_hash']) ? (string) $closeout['return']['return_hash'] : null,
        ];
    }

    private function guard(int $count): void
    {
        if (! (bool) config('election.public_simulation.role_demo_bulk_ballots.enabled', true)) {
            throw new RuntimeException('Bulk demo ballot generation is disabled.');
        }

        $max = max(1, (int) config('election.public_simulation.role_demo_bulk_ballots.max_count', 700));

        if ($count < 1 || $count > $max) {
            throw new RuntimeException("Bulk demo ballot count must be between 1 and {$max}.");
        }
    }

    private function chunkSize(): int
    {
        return max(1, (int) config('election.public_simulation.role_demo_bulk_ballots.chunk_size', 25));
    }

    private function writeState(int $target, int $generated, string $status): void
    {
        $this->storage->writeJson(self::StatePath, [
            'schema_version' => 'role-demo-bulk-ballots-1',
            'target' => $target,
            'generated' => $generated,
            'remaining' => max(0, $target - $generated),
            'status' => $status,
            'chunk_size' => $this->chunkSize(),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, array<int, string>>
     */
    private function selections(array $configuration, int $ballotNumber): array
    {
        return collect($configuration['contests'] ?? [])
            ->filter(fn (mixed $contest): bool => is_array($contest))
            ->mapWithKeys(function (array $contest) use ($ballotNumber): array {
                $contestId = (string) ($contest['id'] ?? '');
                $candidateIds = collect($contest['candidates'] ?? [])
                    ->filter(fn (mixed $candidate): bool => is_array($candidate) && isset($candidate['id']))
                    ->pluck('id')
                    ->map(fn (mixed $candidateId): string => (string) $candidateId)
                    ->values();
                $maximumSelections = max(0, min((int) ($contest['max_selections'] ?? 1), $candidateIds->count()));

                if ($contestId === '' || $maximumSelections === 0 || $candidateIds->isEmpty()) {
                    return [$contestId => []];
                }

                $offset = ($ballotNumber - 1) % $candidateIds->count();
                $selected = [];

                foreach (range(0, $maximumSelections - 1) as $selectionOffset) {
                    $selected[] = $candidateIds[($offset + $selectionOffset) % $candidateIds->count()];
                }

                return [
                    $contestId => $selected,
                ];
            })
            ->all();
    }
}
