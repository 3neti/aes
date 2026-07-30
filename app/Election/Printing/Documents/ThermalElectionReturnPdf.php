<?php

namespace App\Election\Printing\Documents;

use App\Election\Printing\PrintFormProfile;

final class ThermalElectionReturnPdf
{
    public function __construct(private readonly ThermalContestResultTable $results) {}

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $return
     */
    public function render(array $configuration, array $return, PrintFormProfile $profile): string
    {
        $document = new ThermalPdfDocument('ELECTION RETURN', (string) ($return['return_hash'] ?? 'return'), (string) ($return['precinct_id'] ?? 'unknown'), $profile, 'Precinct results for posting, distribution, and custody review');
        $page = $document->addPage('Return summary');
        $y = $document->contentTop();
        $width = $document->right() - $document->left();
        $document->rectangle($page, $document->left(), $y - 59, $width, 59, 0.90);
        $document->text($page, 'SIMULATION COPY - SUBJECT TO COMELEC FORM APPROVAL', $document->width() / 2, $y - 11, 5.8, true, 'center');
        $document->text($page, 'Election: '.($return['election_id'] ?? 'unknown'), $document->left() + 6, $y - 26, 6.3);
        $document->text($page, 'Precinct: '.($return['precinct_id'] ?? 'unknown'), $document->left() + 6, $y - 38, 6.3);
        $document->text($page, 'Accepted: '.($return['accepted_ballots'] ?? 0).' | Rejected: '.($return['rejected_ballots'] ?? 0), $document->left() + 6, $y - 50, 6.3);
        $y -= 72;
        $result = $this->results->render($document, $configuration, (array) ($return['tally'] ?? []), $page, $y);
        $page = $result['page'];
        $y = $result['y'];
        if ($y < $document->contentBottom() + 64) {
            $page = $document->addPage('Electoral Board certification');
            $y = $document->contentTop();
        }
        $document->rectangle($page, $document->left(), $y - 56, $width, 56, 0.94, false);
        $document->text($page, 'ELECTORAL BOARD CERTIFICATION', $document->left() + 6, $y - 12, 6.8, true);
        $document->wrappedText($page, 'This return is checked against the tally, the paper ballots, and prescribed custody records.', $document->left() + 6, $y - 25, $width - 12, 6.1, 8);

        return $document->render();
    }
}
