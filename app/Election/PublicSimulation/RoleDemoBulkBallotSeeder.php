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
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly PrivateBallotRelease $releases,
        private readonly BallotPrinter $printer,
        private readonly SealedBallotBox $ballotBox,
        private readonly RoleDemoInterimCloseout $forms,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @return array{requested: int, generated: int, rendered_pdfs: int, accepted_ballots: int, tally_hash: string, return_hash: string}
     */
    public function generate(SimulationPrecinct $precinct, int $count): array
    {
        $this->guard($count);

        $configuration = $this->storage->readJson('runtime/active-precinct.json');

        if ($configuration === []) {
            throw new RuntimeException('No active role-demo precinct configuration is available.');
        }

        $renderedPdfLimit = min(
            $count,
            max(0, (int) config('election.public_simulation.role_demo_bulk_ballots.rendered_pdf_limit', 50)),
        );
        $generated = 0;
        $renderedPdfs = 0;

        foreach (range(1, $count) as $index) {
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
            $generated++;
        }

        $closeout = $this->forms->generate($precinct, 'role-demo-bulk-ballots');

        $this->journal->record('role_demo.bulk_ballots_generated', [
            'precinct_code' => $precinct->code,
            'requested' => $count,
            'generated' => $generated,
            'rendered_pdfs' => $renderedPdfs,
            'tally_hash' => $closeout['tally']['tally_hash'],
            'return_hash' => $closeout['return']['return_hash'],
        ]);

        return [
            'requested' => $count,
            'generated' => $generated,
            'rendered_pdfs' => $renderedPdfs,
            'accepted_ballots' => (int) $closeout['tally']['accepted_ballots'],
            'tally_hash' => (string) $closeout['tally']['tally_hash'],
            'return_hash' => (string) $closeout['return']['return_hash'],
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
