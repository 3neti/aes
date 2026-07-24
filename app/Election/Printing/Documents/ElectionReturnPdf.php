<?php

namespace App\Election\Printing\Documents;

final class ElectionReturnPdf
{
    public function __construct(private readonly ContestResultTable $results) {}

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $return
     */
    public function render(array $configuration, array $return): string
    {
        $precinctId = (string) ($return['precinct_id'] ?? 'unknown');
        $document = new ElectionPdfDocument(
            'Election Return',
            (string) ($return['return_hash'] ?? 'return'),
            $precinctId,
            'Precinct results for posting, distribution, and custody review',
        );
        $page = $document->addPage('Return summary');
        $document->rectangle($page, 42, 688, 511, 36, 0.88);
        $document->text($page, 'SIMULATION COPY - SUBJECT TO COMELEC FORM APPROVAL', 297.5, 701, 9.5, true, 'center');
        $document->text($page, 'Election', 42, 665, 7.5, true);
        $document->text($page, (string) ($return['election_id'] ?? 'unknown'), 105, 665, 9);
        $document->text($page, 'Clustered precinct', 320, 665, 7.5, true);
        $document->text($page, $precinctId, 543, 665, 9, true, 'right');
        $document->text($page, 'Accepted paper ballots', 42, 643, 7.5, true);
        $document->text($page, (string) ($return['accepted_ballots'] ?? 0), 165, 643, 10, true);
        $document->text($page, 'Rejected scans', 215, 643, 7.5, true);
        $document->text($page, (string) ($return['rejected_ballots'] ?? 0), 300, 643, 10, true);
        $document->text($page, 'Return SHA-256', 42, 619, 7.5, true);
        $document->text($page, (string) ($return['return_hash'] ?? 'unknown'), 128, 619, 7, false, monospace: true);
        $document->wrappedText(
            $page,
            'The Electoral Board certifies that the following totals were generated from the accepted paper-ballot records for this clustered precinct. Paper ballots and signed records remain controlling evidence.',
            42,
            594,
            511,
            8.5,
            11,
        );

        $result = $this->results->render(
            $document,
            $configuration,
            (array) ($return['tally'] ?? []),
            $page,
            552,
        );
        $page = $result['page'];
        $y = $result['y'];

        if ($y < 252) {
            $page = $document->addPage('Electoral Board certification');
            $y = ElectionPdfDocument::ContentTop;
        }

        $document->rectangle($page, 42, $y - 178, 511, 178, 0.94, false);
        $document->text($page, 'ELECTORAL BOARD CERTIFICATION', 54, $y - 22, 10, true);
        $document->wrappedText(
            $page,
            'We certify that this Election Return contains the complete candidate listing and vote totals produced for the clustered precinct identified above, subject to verification against the paper ballots, tally sheet, minutes, and prescribed COMELEC forms.',
            54,
            $y - 42,
            485,
            8.5,
            11,
        );
        $signatureY = $y - 118;

        foreach ([
            ['Chairperson', 54],
            ['Poll Clerk', 225],
            ['Third Member', 396],
        ] as [$role, $x]) {
            $document->line($page, $x, $signatureY, $x + 130, $signatureY, 0.7, 0.25);
            $document->text($page, $role, $x + 65, $signatureY - 13, 7.5, false, 'center');
        }

        $document->text($page, 'Generated date/time', 54, $y - 157, 7.5);
        $document->line($page, 145, $y - 156, 270, $y - 156, 0.6, 0.35);
        $document->text($page, 'Posted copy number', 320, $y - 157, 7.5);
        $document->line($page, 410, $y - 156, 539, $y - 156, 0.6, 0.35);

        return $document->render();
    }
}
