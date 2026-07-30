<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Models\SimulationPrecinct;
use RuntimeException;

final class PublicSimulationUsabilitySessionKit
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
    ) {}

    /** @return array<string, mixed> */
    public function build(SimulationPrecinct $precinct): array
    {
        if ($precinct->status !== 'ready') {
            throw new RuntimeException('A usability session kit can be prepared only for a ready public simulation precinct.');
        }

        $kit = [
            'schema_version' => 'public-simulation-usability-session-kit-1',
            'prepared_at' => $this->clock->now()->toIso8601String(),
            'round_code' => $precinct->round->code,
            'precinct_code' => $precinct->code,
            'precinct_label' => $precinct->label,
            'purpose' => 'Facilitated external usability session for the public election simulation.',
            'privacy_notice' => 'Do not collect participant names, contact details, control numbers, ballot selections, print-release values, or screenshots showing private voter information.',
            'roles' => [
                'Election Officer: open the precinct, admit volunteers after offline identity simulation, and hand control numbers verbally.',
                'Voter: use the shared tablet entry point, enter the handed control number, make selections privately, and complete the private print flow.',
                'Watcher: observe only post-close published artifacts and the public audit summary.',
                'Facilitator: observe ceremony clarity, privacy cues, handoffs, errors, and accessibility; record structured observations after publication.',
            ],
            'success_criteria' => [
                'Each participant can identify the next action without facilitator prompting.',
                'No participant is asked to disclose a ballot choice or private print-release value.',
                'The Election Officer can explain the admission, closeout, and publication boundaries.',
                'Watchers can locate the tally and Election Return only after publication.',
                'Needs-attention or blocking findings are recorded as structured operational observations.',
            ],
            'follow_up_commands' => [
                'election:public-simulation:observation-review {round} {precinct}',
                'election:public-simulation:review-kit {round}',
            ],
        ];
        $kit['kit_hash'] = $this->json->hash($kit);
        $kit['artifact_path'] = $this->storage->writeJson('usability-session-kit/session-kit.json', $kit);
        $this->storage->writeText('usability-session-kit/facilitator-guide.md', $this->facilitatorGuide($kit));
        $this->storage->writeText('usability-session-kit/participant-observation-sheet.md', $this->observationSheet($kit));
        $this->journal->record('public_simulation.usability_session_kit_prepared', [
            'round_code' => $kit['round_code'],
            'precinct_code' => $kit['precinct_code'],
            'kit_hash' => $kit['kit_hash'],
        ]);

        return $kit;
    }

    /** @param array<string, mixed> $kit */
    private function facilitatorGuide(array $kit): string
    {
        return implode(PHP_EOL, [
            '# Public Simulation Usability Session',
            '',
            "Round: {$kit['round_code']}",
            "Precinct: {$kit['precinct_code']} - {$kit['precinct_label']}",
            '',
            '## Boundary',
            $kit['privacy_notice'],
            '',
            '## Roles',
            ...array_map(fn (string $role): string => "- {$role}", $kit['roles']),
            '',
            '## Success Criteria',
            ...array_map(fn (string $criterion): string => "- {$criterion}", $kit['success_criteria']),
            '',
            'After publication, the Election Officer records structured observations. The facilitator then runs the observation-review command before sharing the private audit review.',
        ]).PHP_EOL;
    }

    /** @param array<string, mixed> $kit */
    private function observationSheet(array $kit): string
    {
        return implode(PHP_EOL, [
            '# Participant Observation Sheet',
            '',
            'Record no participant name, contact detail, control number, ballot choice, print release, or private screenshot.',
            '',
            'Role: Election Officer | Voter | Watcher | Facilitator',
            'Ceremony: Admission | Voting | Private printing | Closeout | Results publication | Audit',
            'Assessment: Clear | Needs attention | Blocking',
            '',
            'Observation:',
            '',
            'Recommended follow-up: record the structured observation after watcher publication.',
        ]).PHP_EOL;
    }
}
