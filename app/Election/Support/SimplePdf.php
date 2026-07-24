<?php

namespace App\Election\Support;

use App\Election\Printing\Documents\ElectionPdfDocument;

final class SimplePdf
{
    /**
     * @param  array<int, string>  $lines
     */
    public function render(string $title, array $lines): string
    {
        $document = new ElectionPdfDocument(
            $title,
            substr(hash('sha256', $title.implode("\n", $lines)), 0, 16),
            'see document',
            'Alternative Election System - Simulation Evidence Artifact',
        );
        $page = $document->addPage('Evidence record');
        $y = ElectionPdfDocument::ContentTop;

        foreach ($lines as $line) {
            $wrapped = $document->wrap($line, 511, 9, true);
            $height = max(14, count($wrapped) * 12);

            if ($y - $height < ElectionPdfDocument::ContentBottom) {
                $page = $document->addPage('Evidence record continued');
                $y = ElectionPdfDocument::ContentTop;
            }

            $bold = $line !== '' && (
                str_ends_with($line, ':')
                || $line === strtoupper($line)
            );

            foreach ($wrapped as $wrappedLine) {
                $document->text($page, $wrappedLine, 42, $y, 9, $bold, monospace: ! $bold);
                $y -= 12;
            }

            if ($line === '') {
                $y -= 5;
            }
        }

        return $document->render();
    }
}
