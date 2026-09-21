<?php

namespace App\Election\Printing\Documents;

use App\Election\Printing\PrintFormProfile;

final class ControlNumberReceiptPdf
{
    /**
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $configuration
     */
    public function render(array $release, array $configuration, string $qrArtifactPath): string
    {
        $releaseId = (string) ($release['release_id'] ?? 'unknown');
        $controlNumber = (string) ($release['release_code'] ?? '');
        $precinctId = (string) ($configuration['precinct_id'] ?? 'unknown');
        $document = new ThermalPdfDocument(
            'VOTER CONTROL NUMBER',
            $releaseId,
            $precinctId,
            PrintFormProfile::Thermal80,
            'Private ballot print receipt - simulation only',
        );
        $document->registerPng('ControlNumberQr', $qrArtifactPath);

        $page = $document->addPage('Print station receipt');
        $width = $document->right() - $document->left();
        $y = $document->contentTop();

        $document->rectangle($page, $document->left(), $y - 24, $width, 24, 0.90);
        $document->text($page, 'BRING THIS TO THE PRINT STATION', $document->width() / 2, $y - 15, 7.2, true, 'center');
        $y -= 45;

        foreach ([
            ['Election', (string) ($configuration['election_id'] ?? 'unknown')],
            ['Precinct', $precinctId],
            ['Ballot style', (string) ($configuration['ballot_style_id'] ?? 'unknown')],
        ] as [$label, $value]) {
            $document->text($page, $label.':', $document->left(), $y, 6.5, true);
            $y = $document->wrappedText($page, $value, $document->left() + 47, $y, $width - 47, 6.5, 8.5) - 3;
        }

        $y -= 8;
        $document->text($page, 'CONTROL NUMBER', $document->width() / 2, $y, 9, true, 'center');
        $y -= 42;
        $document->rectangle($page, $document->left(), $y - 12, $width, 50, 0.96);
        $document->text($page, $controlNumber, $document->width() / 2, $y, 30, true, 'center', true);
        $y -= 42;

        $qrSize = min(150, $width - 24);
        $qrX = ($document->width() - $qrSize) / 2;
        $document->rectangle($page, $qrX - 4, $y - $qrSize - 4, $qrSize + 8, $qrSize + 8, 0.97);
        $document->image($page, 'ControlNumberQr', $qrX, $y - $qrSize, $qrSize, $qrSize);
        $y -= $qrSize + 18;

        $document->text($page, 'QR data: aes-print-release:'.$controlNumber, $document->left(), $y, 6.2, false, monospace: true);
        $y -= 13;
        $document->wrappedText(
            $page,
            'Expires: '.(string) ($release['expires_at'] ?? 'unknown'),
            $document->left(),
            $y,
            $width,
            6.2,
            8,
        );

        return $document->render();
    }
}
