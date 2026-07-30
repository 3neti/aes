<?php

namespace App\Election\Printing\Documents;

final class ContestResultTable
{
    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, array<string, int>>  $tally
     * @return array{page: int, y: float, candidate_count: int}
     */
    public function render(
        ElectionPdfDocument $document,
        array $configuration,
        array $tally,
        int $page,
        float $y,
    ): array {
        $candidateCount = 0;

        foreach (($configuration['contests'] ?? []) as $contest) {
            if (! is_array($contest)) {
                continue;
            }

            $contestId = (string) ($contest['id'] ?? '');
            $title = (string) ($contest['title'] ?? $contestId);

            if ($y < ElectionPdfDocument::ContentBottom + 72) {
                $page = $document->addPage($title);
                $y = ElectionPdfDocument::ContentTop;
            }

            [$page, $y] = $this->contestHeader($document, $page, $y, $title, $contest);
            $rowIndex = 0;

            foreach (($contest['candidates'] ?? []) as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                $candidateId = (string) ($candidate['id'] ?? '');
                $name = (string) ($candidate['name'] ?? $candidateId);
                $nameLines = $document->wrap($name, 225, 8.2);
                $votes = (int) ($tally[$contestId][$candidateId] ?? 0);
                $rowHeight = max(
                    17,
                    (count($nameLines) * 10) + 6,
                    $document->tallyMarkHeight($votes, 180),
                );

                if ($y - $rowHeight < ElectionPdfDocument::ContentBottom) {
                    $page = $document->addPage($title.' - continued');
                    $y = ElectionPdfDocument::ContentTop;
                    [$page, $y] = $this->contestHeader($document, $page, $y, $title, $contest, true);
                }

                if ($rowIndex % 2 === 1) {
                    $document->rectangle($page, 42, $y - $rowHeight, 511, $rowHeight, 0.97);
                }

                $ballotNumber = (string) ($candidate['ballot_number'] ?? $candidate['ordinal'] ?? '-');
                $document->text($page, $ballotNumber, 58, $y - 12, 8.2, false, 'center');
                $nameY = $y - 12;

                foreach ($nameLines as $nameLine) {
                    $document->text($page, $nameLine, 82, $nameY, 8.2);
                    $nameY -= 10;
                }

                $document->tallyMarks($page, $votes, 316, $y, 180);
                $document->text($page, (string) $votes, 535, $y - 12, 9, true, 'right');
                $document->line($page, 42, $y - $rowHeight, 553, $y - $rowHeight, 0.35, 0.82);
                $y -= $rowHeight;
                $rowIndex++;
                $candidateCount++;
            }

            $y -= 18;
        }

        return [
            'page' => $page,
            'y' => $y,
            'candidate_count' => $candidateCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $contest
     * @return array{int, float}
     */
    private function contestHeader(
        ElectionPdfDocument $document,
        int $page,
        float $y,
        string $title,
        array $contest,
        bool $continued = false,
    ): array {
        $titleLines = $document->wrap($title, 410, 10);
        $headingHeight = max(36, 18 + (count($titleLines) * 12));
        $document->rectangle($page, 42, $y - $headingHeight, 511, $headingHeight, 0.90);
        $titleY = $y - 15;

        foreach ($titleLines as $line) {
            $document->text($page, $line, 52, $titleY, 10, true);
            $titleY -= 12;
        }

        $maximum = (int) ($contest['max_selections'] ?? 1);
        $suffix = $continued ? ' | continued' : '';
        $document->text(
            $page,
            "Vote for up to {$maximum}{$suffix}",
            543,
            $y - 15,
            7.5,
            false,
            'right',
        );
        $y -= $headingHeight;
        $document->rectangle($page, 42, $y - 18, 511, 18, 0.82);
        $document->text($page, 'NO.', 58, $y - 12, 7.5, true, 'center');
        $document->text($page, 'CANDIDATE / PARTY AS PRINTED', 82, $y - 12, 7.5, true);
        $document->text($page, 'TALLY MARKS', 316, $y - 12, 7.5, true);
        $document->text($page, 'VOTES', 535, $y - 12, 7.5, true, 'right');

        return [$page, $y - 18];
    }
}
