<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Printing\BallotPrinter;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\AnonymousVoterAuthorization;
use App\Election\Voting\PrivateBallotRelease;
use App\Election\Voting\SealedBallotBox;
use App\Models\SimulationPrecinct;
use RuntimeException;

final class PublicSimulationFieldRehearsal
{
    public function __construct(
        private readonly PublicSimulationService $simulations,
        private readonly PublicSimulationAdmissionCapacity $capacity,
        private readonly PublicSimulationVotingGate $voting,
        private readonly AnonymousVoterAuthorization $authorizations,
        private readonly PrivateBallotRelease $releases,
        private readonly BallotPrinter $printer,
        private readonly SealedBallotBox $ballotBox,
        private readonly PublicSimulationCloseout $closeout,
        private readonly PublicSimulationPublication $publication,
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
    ) {}

    /** @return array<string, mixed> */
    public function run(SimulationPrecinct $precinct, int $voterCount): array
    {
        if ($precinct->status !== 'ready') {
            throw new RuntimeException('A field rehearsal requires a ready public simulation precinct. Start a fresh round or select another ready precinct.');
        }

        $maximum = max(2, (int) config('election.public_simulation.maximum_active_admissions', 10));

        if ($voterCount < 2 || $voterCount > $maximum) {
            throw new RuntimeException("Field rehearsal voter count must be between 2 and {$maximum}.");
        }

        $this->simulations->open($precinct, $precinct->officer_code, '123456');
        $precinct->refresh();
        $this->simulations->applyScope($precinct);
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $selections = collect($configuration['contests'] ?? [])
            ->mapWithKeys(fn (array $contest): array => [
                $contest['id'] => collect($contest['candidates'] ?? [])
                    ->take(min(1, (int) ($contest['max_selections'] ?? 1)))
                    ->pluck('id')
                    ->all(),
            ])
            ->all();

        $issued = [];

        foreach (range(1, $voterCount) as $sequence) {
            $issued[] = $this->voting->execute(fn (): array => $this->capacity->issue($this->authorizations));
        }

        foreach ($issued as $authorization) {
            $this->voting->execute(fn (): array => $this->authorizations->claim($authorization['code']));
        }

        $closeBlocked = false;

        try {
            $this->closeout->close($precinct, $precinct->officer_code, '123456');
        } catch (RuntimeException $exception) {
            $closeBlocked = str_contains($exception->getMessage(), 'voter session');
        }

        if (! $closeBlocked) {
            throw new RuntimeException('The rehearsal did not prove that closeout is blocked while the voter cohort is active.');
        }

        foreach ($issued as $authorization) {
            $release = $this->voting->execute(function () use ($authorization, $selections): array {
                $release = $this->releases->create($authorization['authorization_id'], $selections);
                $this->authorizations->complete($authorization['authorization_id']);

                return $release;
            });

            $this->voting->execute(function () use ($release): void {
                $this->releases->redeem($release['release_code']);
                $this->releases->print($release['release_id'], $this->printer);
                $this->ballotBox->deposit($release['release_id']);
            });
        }

        $closeout = $this->closeout->close($precinct, $precinct->officer_code, '123456');
        $publication = $this->publication->publish($precinct->fresh('round'));
        $precinct->forceFill(['status' => 'published'])->save();

        $report = [
            'schema_version' => 'public-simulation-field-rehearsal-1',
            'generated_at' => $this->clock->now()->toIso8601String(),
            'round_code' => $precinct->round->code,
            'precinct_code' => $precinct->code,
            'voter_cohort_size' => $voterCount,
            'observations' => [
                'cohort_claimed_before_completion' => count($issued),
                'closeout_blocked_while_active' => $closeBlocked,
                'private_releases_completed' => count($issued),
                'device_tabulated_ballots' => $closeout['tally']['accepted_ballots'],
                'results_published' => $publication['manifest_hash'] !== null,
            ],
            'evidence' => [
                'vvdat_ledger_root' => $publication['vvdat_ledger_root'],
                'tally_hash' => $publication['tally_hash'],
                'return_hash' => $publication['return_hash'],
                'publication_manifest_hash' => $publication['manifest_hash'],
            ],
            'privacy_notice' => 'This rehearsal report contains cohort-level counts and evidence hashes only. It excludes voter identities, control numbers, print releases, ballot identifiers, selections, and QR payloads.',
        ];
        $report['report_hash'] = $this->json->hash($report);
        $report['artifact_path'] = $this->storage->writeJson('field-rehearsals/field-rehearsal-000001.json', $report);
        $this->journal->record('public_simulation.field_rehearsal_completed', [
            'voter_cohort_size' => $voterCount,
            'report_hash' => $report['report_hash'],
            'publication_manifest_hash' => $publication['manifest_hash'],
        ]);

        return $report;
    }
}
