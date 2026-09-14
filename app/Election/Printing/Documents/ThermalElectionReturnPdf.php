<?php

namespace App\Election\Printing\Documents;

use App\Election\Printing\PrintFormProfile;
use App\Election\Returns\ElectionReturnContestScopes;
use App\Election\Returns\ElectionReturnScope;

final class ThermalElectionReturnPdf
{
    public function __construct(
        private readonly ThermalContestResultTable $results,
        private readonly ElectionReturnContestScopes $scopes,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $return
     */
    public function render(array $configuration, array $return, PrintFormProfile $profile, ElectionReturnScope $scope = ElectionReturnScope::Combined): string
    {
        $configuration = $this->scopes->configurationFor($configuration, $scope);
        $document = new ThermalPdfDocument(mb_strtoupper($scope->label()), (string) ($return['return_hash'] ?? 'return'), (string) ($return['precinct_id'] ?? 'unknown'), $profile, $scope->title());
        $this->registerTruthTallyQrImages($document, $return, $scope);
        $page = $document->addPage('Return summary');
        $y = $document->contentTop();
        $width = $document->right() - $document->left();
        $document->rectangle($page, $document->left(), $y - 69, $width, 69, 0.90);
        $document->text($page, 'SIMULATION COPY - SUBJECT TO COMELEC FORM APPROVAL', $document->width() / 2, $y - 11, 5.8, true, 'center');
        $document->wrappedText($page, mb_strtoupper($scope->title()), $document->left() + 6, $y - 22, $width - 12, 6.2, 8);
        $document->text($page, 'Election: '.($return['election_id'] ?? 'unknown'), $document->left() + 6, $y - 38, 6.3);
        $document->text($page, 'Precinct: '.($return['precinct_id'] ?? 'unknown'), $document->left() + 6, $y - 50, 6.3);
        $document->text($page, 'Accepted: '.($return['accepted_ballots'] ?? 0).' | Rejected: '.($return['rejected_ballots'] ?? 0), $document->left() + 6, $y - 62, 6.3);
        $y -= 82;
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

        $this->renderTruthTallyQrPages($document, $return, $scope);

        return $document->render();
    }

    /**
     * @param  array<string, mixed>  $return
     */
    private function registerTruthTallyQrImages(ThermalPdfDocument $document, array $return, ElectionReturnScope $scope): void
    {
        foreach ((array) ($this->truthTallyForScope($return, $scope)['qr_artifacts'] ?? []) as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }

            $path = (string) ($artifact['artifact_path'] ?? '');

            if ($path !== '' && is_file($path)) {
                $document->registerPng('TruthTallyQr'.(int) ($artifact['sequence'] ?? 1), $path);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $return
     */
    private function renderTruthTallyQrPages(ThermalPdfDocument $document, array $return, ElectionReturnScope $scope): void
    {
        $truthTally = $this->truthTallyForScope($return, $scope);
        $artifacts = array_values(array_filter(
            (array) ($truthTally['qr_artifacts'] ?? []),
            fn (mixed $artifact): bool => is_array($artifact),
        ));

        foreach (array_chunk($artifacts, 2) as $pageIndex => $pageArtifacts) {
            $page = $document->addPage('TruthTally QR copy');
            $width = $document->right() - $document->left();
            $qrSize = min(184, $width - 16);
            $qrX = ($document->width() - $qrSize) / 2;

            $document->text($page, 'TRUTHTALLY '.$scope->label().' QR', $document->left(), 696, 7.4, true);
            $document->wrappedText(
                $page,
                'Scan this QR payload set at canvassing to reconstruct this scoped precinct ER.',
                $document->left(),
                680,
                $width,
                6,
                7.6,
            );

            foreach ($pageArtifacts as $slot => $artifact) {
                $artifactIndex = ($pageIndex * 2) + $slot;
                $labelY = $slot === 0 ? 650.0 : 386.0;
                $qrY = $slot === 0 ? 462.0 : 198.0;
                $name = 'TruthTallyQr'.(int) ($artifact['sequence'] ?? ($artifactIndex + 1));

                $document->text(
                    $page,
                    'QR '.(int) ($artifact['sequence'] ?? ($artifactIndex + 1)).' of '.(int) ($artifact['total'] ?? count($artifacts)),
                    $document->left(),
                    $labelY,
                    6.5,
                    true,
                );
                $document->rectangle($page, $qrX - 3, $qrY - 3, $qrSize + 6, $qrSize + 6, 0.97);
                $document->image($page, $name, $qrX, $qrY, $qrSize, $qrSize);
            }

            $document->text($page, 'Payload SHA-256', $document->left(), 174, 6.2, true);
            $document->wrappedText($page, (string) ($truthTally['payload_hash'] ?? 'unknown'), $document->left(), 162, $width, 5.2, 6.3, monospace: true);
        }
    }

    /**
     * @param  array<string, mixed>  $return
     * @return array<string, mixed>
     */
    private function truthTallyForScope(array $return, ElectionReturnScope $scope): array
    {
        $scoped = $return['truth_tally']['scopes'][$scope->value] ?? null;

        return is_array($scoped) ? $scoped : (array) ($return['truth_tally'] ?? []);
    }
}
