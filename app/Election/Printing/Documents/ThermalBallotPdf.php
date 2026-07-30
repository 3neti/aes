<?php

namespace App\Election\Printing\Documents;

use App\Election\Printing\PrintFormProfile;

final class ThermalBallotPdf
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $configuration
     */
    public function render(array $payload, array $configuration, PrintFormProfile $profile): string
    {
        $ballotId = (string) ($payload['ballot_id'] ?? 'unknown');
        $precinctId = (string) ($payload['precinct_id'] ?? 'unknown');
        $document = new ThermalPdfDocument(
            'OFFICIAL SIMULATION BALLOT',
            $ballotId,
            $precinctId,
            $profile,
            'Voter-verifiable paper ballot - simulation only',
        );
        $document->registerPng('BallotQr', (string) $payload['qr_artifact_path']);
        $page = $document->addPage('Ballot selections');
        $y = $document->contentTop();
        $width = $document->right() - $document->left();

        $document->rectangle($page, $document->left(), $y - 25, $width, 25, 0.90);
        $document->text($page, 'SIMULATION - NOT AN OFFICIAL COMELEC FORM', $document->width() / 2, $y - 15, 6.2, true, 'center');
        $y -= 38;

        foreach ([
            ['Election', (string) ($payload['election_id'] ?? 'unknown')],
            ['Precinct', $precinctId],
            ['Ballot style', (string) ($payload['ballot_style_id'] ?? 'unknown')],
            ['Paper serial', (string) ($payload['paper_ballot_serial'] ?? 'CERTIFICATION/UNNUMBERED')],
            ['Ballot ID', $ballotId],
        ] as [$label, $value]) {
            $document->text($page, $label.':', $document->left(), $y, 6.6, true);
            $y = $document->wrappedText($page, $value, $document->left() + 46, $y, $width - 46, 6.6, 8.5) - 3;
        }

        $document->line($page, $document->left(), $y, $document->right(), $y, 0.9, 0.12);
        $y -= 15;
        $document->text($page, "VOTER'S RECORDED SELECTIONS", $document->left(), $y, 8.2, true);
        $y -= 15;

        foreach (($configuration['contests'] ?? []) as $contest) {
            if (! is_array($contest)) {
                continue;
            }

            $contestId = (string) ($contest['id'] ?? '');
            $selectedIds = array_values((array) (($payload['selections'] ?? [])[$contestId] ?? []));
            $candidates = collect((array) ($contest['candidates'] ?? []))
                ->filter(fn (mixed $candidate): bool => is_array($candidate) && isset($candidate['id']))
                ->keyBy(fn (array $candidate): string => (string) $candidate['id']);
            $selected = collect($selectedIds)
                ->map(function (string $candidateId) use ($candidates): string {
                    $candidate = $candidates->get($candidateId);
                    if (! is_array($candidate)) {
                        return $candidateId;
                    }

                    return ($candidate['ballot_number'] ?? $candidate['ordinal'] ?? '-').'. '.($candidate['name'] ?? $candidateId);
                })
                ->implode('; ');
            $selection = $selected === '' ? 'UNDERVOTE - No candidate selected.' : $selected;
            $titleLines = $document->wrap((string) ($contest['title'] ?? $contestId), $width - 12, 7.2);
            $selectionLines = $document->wrap($selection, $width - 12, 6.8);
            $height = 12 + (count($titleLines) * 9) + (count($selectionLines) * 8.5);

            if ($y - $height < $document->contentBottom()) {
                $page = $document->addPage('Selections continued');
                $y = $document->contentTop();
            }

            $document->rectangle($page, $document->left(), $y - $height, $width, $height, 0.96);
            foreach ($titleLines as $line) {
                $document->text($page, $line, $document->left() + 6, $y - 9, 7.2, true);
                $y -= 9;
            }
            foreach ($selectionLines as $line) {
                $document->text($page, $line, $document->left() + 6, $y - 4, 6.8);
                $y -= 8.5;
            }
            $y -= 10;
        }

        $qrSize = min(124, $width - 20);
        $finalHeight = $qrSize + 68;
        if ($y - $finalHeight < $document->contentBottom()) {
            $page = $document->addPage('Ballot QR and voter verification');
            $y = $document->contentTop();
        }
        $qrX = ($document->width() - $qrSize) / 2;
        $document->rectangle($page, $qrX - 3, $y - $qrSize - 3, $qrSize + 6, $qrSize + 6, 0.97);
        $document->image($page, 'BallotQr', $qrX, $y - $qrSize, $qrSize, $qrSize);
        $y -= $qrSize + 14;
        $document->text($page, 'SCAN DURING COUNTING OR RANDOM MANUAL AUDIT', $document->width() / 2, $y, 5.8, true, 'center');
        $y -= 13;
        $document->text($page, 'Payload SHA-256', $document->left(), $y, 5.8, true);
        $document->wrappedText($page, (string) ($payload['payload_hash'] ?? 'unknown'), $document->left(), $y - 9, $width, 5.2, 6.5, monospace: true);

        return $document->render();
    }
}
