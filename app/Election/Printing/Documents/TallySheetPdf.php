<?php

namespace App\Election\Printing\Documents;

final class TallySheetPdf
{
    public function __construct(private readonly ContestResultTable $results) {}

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $tally
     */
    public function render(array $configuration, array $tally): string
    {
        $precinctId = (string) ($configuration['precinct_id'] ?? 'unknown');
        $document = new ElectionPdfDocument(
            'Precinct Tally Sheet',
            (string) ($tally['tally_hash'] ?? 'tally'),
            $precinctId,
            'Candidates with votes from accepted paper ballots',
        );
        $page = $document->addPage('Tally summary');
        $document->rectangle($page, 42, 650, 511, 74, 0.93);
        $document->text($page, 'COUNTING SUMMARY', 54, 704, 10, true);
        $document->text($page, 'Accepted paper ballots', 54, 683, 8);
        $document->text($page, (string) ($tally['accepted_ballots'] ?? 0), 180, 681, 14, true);
        $document->text($page, 'Rejected scans', 242, 683, 8);
        $document->text($page, (string) ($tally['rejected_ballots'] ?? 0), 330, 681, 14, true);
        $document->text($page, 'Clustered precinct', 390, 683, 8);
        $document->text($page, $precinctId, 543, 681, 10, true, 'right');
        $document->text($page, 'Tally SHA-256', 54, 661, 7.5, true);
        $document->text($page, (string) ($tally['tally_hash'] ?? 'unknown'), 132, 661, 7, false, monospace: true);

        $result = $this->results->render(
            $document,
            $configuration,
            (array) ($tally['tally'] ?? []),
            $page,
            628,
            true,
            true,
        );
        $page = $result['page'];
        $y = $result['y'];

        if ($y < 190) {
            $page = $document->addPage('Tally reconciliation');
            $y = ElectionPdfDocument::ContentTop;
        }

        $document->rectangle($page, 42, $y - 108, 511, 108, 0.94, false);
        $document->text($page, 'ELECTORAL BOARD RECONCILIATION', 54, $y - 20, 9, true);
        $document->text($page, 'Candidate rows printed', 54, $y - 42, 8);
        $document->text($page, (string) $result['candidate_count'], 190, $y - 42, 8.5, true);
        $document->text($page, 'Compared with accepted ballot records and physical ballot accounting:', 54, $y - 62, 8);
        $document->line($page, 345, $y - 64, 539, $y - 64, 0.6, 0.35);
        $document->text($page, 'Chairperson', 54, $y - 91, 7.5);
        $document->line($page, 110, $y - 90, 235, $y - 90, 0.6, 0.35);
        $document->text($page, 'Poll Clerk', 270, $y - 91, 7.5);
        $document->line($page, 324, $y - 90, 449, $y - 90, 0.6, 0.35);
        $document->text($page, 'Date/time', 466, $y - 91, 7.5);
        $document->line($page, 510, $y - 90, 539, $y - 90, 0.6, 0.35);

        return $document->render();
    }
}
