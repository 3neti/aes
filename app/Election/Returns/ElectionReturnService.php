<?php

namespace App\Election\Returns;

use App\Election\Core\ActivityJournal;
use App\Election\Core\BallotConfigurationLabels;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionStorage;
use App\Election\Support\SimplePdf;

final class ElectionReturnService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly SimplePdf $pdf,
        private readonly BallotConfigurationLabels $labels,
    ) {}

    /**
     * @param  array<string, mixed>  $tally
     * @return array<string, mixed>
     */
    public function generate(array $tally): array
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $return = [
            'schema_version' => 'election-return-1',
            'election_id' => $configuration['election_id'] ?? null,
            'precinct_id' => $configuration['precinct_id'] ?? null,
            'mapping_hash' => $configuration['mapping_hash'] ?? null,
            'accepted_ballots' => $tally['accepted_ballots'],
            'rejected_ballots' => $tally['rejected_ballots'],
            'tally' => $tally['tally'],
            'tally_hash' => $tally['tally_hash'],
        ];
        $return['return_hash'] = $this->json->hash($return);

        $this->storage->writeJson("returns/{$return['precinct_id']}-return.json", $return);
        $this->storage->writeText("returns/{$return['precinct_id']}-return.txt", $this->renderText($return));
        $this->storage->writeText("returns/{$return['precinct_id']}-return.pdf", $this->pdf->render('Election Return', $this->renderPdfLines($return)));
        $this->journal->record('return.generated', [
            'precinct_id' => $return['precinct_id'],
            'return_hash' => $return['return_hash'],
        ]);

        return $return;
    }

    /**
     * @param  array<string, mixed>  $return
     */
    private function renderText(array $return): string
    {
        $text = "ELECTION RETURN\n";
        $text .= "Election: {$return['election_id']}\n";
        $text .= "Precinct: {$return['precinct_id']}\n";
        $text .= "Accepted Ballots: {$return['accepted_ballots']}\n";
        $text .= "Rejected Ballots: {$return['rejected_ballots']}\n";
        $text .= "Return Hash: {$return['return_hash']}\n\n";

        foreach ($this->labels->tallyLines($return['tally']) as $line) {
            $text .= $line."\n";
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $return
     * @return array<int, string>
     */
    private function renderPdfLines(array $return): array
    {
        $lines = [
            "Election: {$return['election_id']}",
            "Precinct: {$return['precinct_id']}",
            "Accepted Ballots: {$return['accepted_ballots']}",
            "Rejected Ballots: {$return['rejected_ballots']}",
            "Return Hash: {$return['return_hash']}",
            'Totals:',
        ];

        array_push($lines, ...$this->labels->tallyLines($return['tally']));

        return $lines;
    }
}
