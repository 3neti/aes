<?php

namespace App\Election\Printing\Documents;

use App\Election\Printing\PrintFormProfile;

final class ThermalTallySheetPdf
{
    public function __construct(private readonly ThermalContestResultTable $results) {}

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $tally
     */
    public function render(array $configuration, array $tally, PrintFormProfile $profile): string
    {
        $document = new ThermalPdfDocument('PRECINCT TALLY SHEET', (string) ($tally['tally_hash'] ?? 'tally'), (string) ($configuration['precinct_id'] ?? 'unknown'), $profile, 'Candidates with votes from the configured tally source');
        $page = $document->addPage('Counting summary');
        $y = $document->contentTop();
        $width = $document->right() - $document->left();
        $document->rectangle($page, $document->left(), $y - 48, $width, 48, 0.93);
        $document->text($page, 'COUNTING SUMMARY', $document->left() + 6, $y - 11, 7.5, true);
        $document->text($page, 'Accepted ballots: '.($tally['accepted_ballots'] ?? 0), $document->left() + 6, $y - 25, 6.6);
        $document->text($page, 'Rejected scans: '.($tally['rejected_ballots'] ?? 0), $document->left() + 6, $y - 37, 6.6);
        $y -= 62;
        $result = $this->results->render($document, $configuration, (array) ($tally['tally'] ?? []), $page, $y, true, true);
        $page = $result['page'];
        $y = $result['y'];
        if ($y < $document->contentBottom() + 56) {
            $page = $document->addPage('Reconciliation');
            $y = $document->contentTop();
        }
        $document->rectangle($page, $document->left(), $y - 48, $width, 48, 0.94, false);
        $document->text($page, 'ELECTORAL BOARD RECONCILIATION', $document->left() + 6, $y - 12, 6.7, true);
        $document->text($page, 'Candidate rows printed: '.$result['candidate_count'], $document->left() + 6, $y - 25, 6.2);
        $document->text($page, 'Compare against custody and paper ballot records.', $document->left() + 6, $y - 37, 6.2);

        return $document->render();
    }
}
