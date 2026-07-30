<?php

namespace App\Election\Printing\Documents;

final class ThermalContestResultTable
{
    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, array<string, int>>  $tally
     * @return array{page: int, y: float, candidate_count: int}
     */
    public function render(ThermalPdfDocument $document, array $configuration, array $tally, int $page, float $y): array
    {
        $candidateCount = 0;
        $width = $document->right() - $document->left();
        $candidateX = $document->left() + 18;
        $marksX = $document->left() + ($width * 0.52);
        $marksWidth = max(26, ($document->right() - 27) - $marksX);

        foreach (($configuration['contests'] ?? []) as $contest) {
            if (! is_array($contest)) {
                continue;
            }
            $contestId = (string) ($contest['id'] ?? '');
            $title = (string) ($contest['title'] ?? $contestId);
            if ($y < $document->contentBottom() + 50) {
                $page = $document->addPage($title);
                $y = $document->contentTop();
            }
            [$page, $y] = $this->header($document, $page, $y, $title, (int) ($contest['max_selections'] ?? 1));
            $rowIndex = 0;

            foreach ((array) ($contest['candidates'] ?? []) as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }
                $candidateId = (string) ($candidate['id'] ?? '');
                $votes = (int) ($tally[$contestId][$candidateId] ?? 0);
                $nameLines = $document->wrap((string) ($candidate['name'] ?? $candidateId), $marksX - $candidateX - 5, 6.4);
                $height = max(15, (count($nameLines) * 8) + 5, $document->tallyMarkHeight($votes, $marksWidth));
                if ($y - $height < $document->contentBottom()) {
                    $page = $document->addPage($title.' continued');
                    $y = $document->contentTop();
                    [$page, $y] = $this->header($document, $page, $y, $title, (int) ($contest['max_selections'] ?? 1), true);
                }
                if ($rowIndex % 2 === 1) {
                    $document->rectangle($page, $document->left(), $y - $height, $width, $height, 0.97);
                }
                $document->text($page, (string) ($candidate['ballot_number'] ?? $candidate['ordinal'] ?? '-'), $document->left() + 8, $y - 10, 6.3, false, 'center');
                foreach ($nameLines as $index => $line) {
                    $document->text($page, $line, $candidateX, $y - 10 - ($index * 8), 6.4);
                }
                $document->tallyMarks($page, $votes, $marksX, $y, $marksWidth);
                $document->text($page, (string) $votes, $document->right(), $y - 10, 7, true, 'right');
                $document->line($page, $document->left(), $y - $height, $document->right(), $y - $height, 0.35, 0.82);
                $y -= $height;
                $rowIndex++;
                $candidateCount++;
            }
            $y -= 14;
        }

        return ['page' => $page, 'y' => $y, 'candidate_count' => $candidateCount];
    }

    /** @return array{int, float} */
    private function header(ThermalPdfDocument $document, int $page, float $y, string $title, int $maximum, bool $continued = false): array
    {
        $width = $document->right() - $document->left();
        $lines = $document->wrap($title, $width - 12, 7.3);
        $height = max(27, 12 + (count($lines) * 9));
        $document->rectangle($page, $document->left(), $y - $height, $width, $height, 0.90);
        foreach ($lines as $index => $line) {
            $document->text($page, $line, $document->left() + 6, $y - 10 - ($index * 9), 7.3, true);
        }
        $suffix = $continued ? ' continued' : '';
        $document->text($page, "Up to {$maximum}{$suffix}", $document->right() - 4, $y - $height + 7, 5.5, false, 'right');
        $y -= $height;
        $document->rectangle($page, $document->left(), $y - 15, $width, 15, 0.82);
        $document->text($page, 'NO.', $document->left() + 8, $y - 10, 5.4, true, 'center');
        $document->text($page, 'CANDIDATE', $document->left() + 18, $y - 10, 5.4, true);
        $document->text($page, 'MARKS', $document->left() + ($width * 0.52), $y - 10, 5.4, true);
        $document->text($page, 'VOTES', $document->right(), $y - 10, 5.4, true, 'right');

        return [$page, $y - 15];
    }
}
