<?php

namespace App\Election\Printing\Documents;

final class OfficialBallotPdf
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $configuration
     */
    public function render(array $payload, array $configuration): string
    {
        $ballotId = (string) ($payload['ballot_id'] ?? 'unknown');
        $precinctId = (string) ($payload['precinct_id'] ?? 'unknown');
        $document = new ElectionPdfDocument(
            'Official Simulation Ballot',
            $ballotId,
            $precinctId,
            'Voter-verifiable paper ballot - simulation only',
        );
        $document->registerPng('BallotQr', (string) $payload['qr_artifact_path']);
        $page = $document->addPage('Ballot and QR');

        $document->rectangle($page, 42, 696, 511, 28, 0.90);
        $document->text($page, 'SIMULATION - NOT AN OFFICIAL COMELEC FORM', 297.5, 706, 10, true, 'center');

        $metadata = [
            ['Election', (string) ($payload['election_id'] ?? 'unknown')],
            ['Clustered precinct', $precinctId],
            ['Ballot style', (string) ($payload['ballot_style_id'] ?? 'unknown')],
            ['Paper ballot serial', (string) ($payload['paper_ballot_serial'] ?? 'CERTIFICATION/UNNUMBERED')],
            ['Ballot identifier', $ballotId],
        ];
        $y = 674.0;

        foreach ($metadata as [$label, $value]) {
            $document->text($page, $label, 42, $y, 7.5, true);
            $document->wrappedText($page, $value, 145, $y, 208, 8.5, 10.5);
            $y -= 23;
        }

        $document->rectangle($page, 363, 499, 190, 191, 0.97);
        $document->image($page, 'BallotQr', 366, 505, 184, 184);
        $document->text($page, 'SCAN DURING COUNTING', 458, 501, 7.5, true, 'center');
        $document->text($page, 'Payload SHA-256', 42, 552, 7.5, true);
        $document->wrappedText(
            $page,
            (string) ($payload['payload_hash'] ?? 'unknown'),
            42,
            538,
            330,
            7.5,
            9,
            monospace: true,
        );

        $y = 497;
        $document->line($page, 42, $y, 553, $y, 1.2, 0.12);
        $y -= 20;
        $document->text($page, "VOTER'S RECORDED SELECTIONS", 42, $y, 12, true);
        $y -= 18;
        $document->text(
            $page,
            'Verify every printed selection before depositing this paper ballot in the ballot box.',
            42,
            $y,
            8.5,
        );
        $y -= 24;

        foreach (($configuration['contests'] ?? []) as $contest) {
            if (! is_array($contest)) {
                continue;
            }

            $contestId = (string) ($contest['id'] ?? '');
            $selectedIds = array_values((array) (($payload['selections'] ?? [])[$contestId] ?? []));
            $candidateMap = [];

            foreach (($contest['candidates'] ?? []) as $candidate) {
                if (! is_array($candidate) || ! isset($candidate['id'])) {
                    continue;
                }

                $candidateMap[(string) $candidate['id']] = $candidate;
            }

            $selected = collect($selectedIds)
                ->map(function (string $candidateId) use ($candidateMap): string {
                    $candidate = $candidateMap[$candidateId] ?? null;

                    if (! is_array($candidate)) {
                        return $candidateId;
                    }

                    return ($candidate['ballot_number'] ?? $candidate['ordinal'] ?? '-').'. '.($candidate['name'] ?? $candidateId);
                })
                ->all();
            $selectionText = $selected === []
                ? 'UNDERVOTE - No candidate selected.'
                : implode('; ', $selected);
            $titleLines = $document->wrap((string) ($contest['title'] ?? $contestId), 350, 9, false);
            $selectionLines = $document->wrap($selectionText, 491, 8.5, false);
            $height = 18 + (count($titleLines) * 11) + (count($selectionLines) * 11);

            if ($y - $height < 118) {
                $page = $document->addPage('Recorded selections continued');
                $y = ElectionPdfDocument::ContentTop;
            }

            $document->rectangle($page, 42, $y - $height + 5, 511, $height, 0.96);
            $titleY = $y - 9;

            foreach ($titleLines as $titleLine) {
                $document->text($page, $titleLine, 52, $titleY, 9, true);
                $titleY -= 11;
            }

            $maximum = (int) ($contest['max_selections'] ?? 1);
            $document->text(
                $page,
                sprintf('%d selected | maximum %d', count($selectedIds), $maximum),
                543,
                $y - 9,
                7.5,
                false,
                'right',
            );
            $selectionY = $titleY - 4;

            foreach ($selectionLines as $selectionLine) {
                $document->text($page, $selectionLine, 52, $selectionY, 8.5);
                $selectionY -= 11;
            }

            $y -= $height + 8;
        }

        if ($y < 148) {
            $page = $document->addPage('Voter verification');
            $y = ElectionPdfDocument::ContentTop;
        }

        $document->rectangle($page, 42, $y - 70, 511, 70, 0.94, false);
        $document->text($page, 'VOTER VERIFICATION BEFORE DEPOSIT', 54, $y - 18, 9, true);
        $document->wrappedText(
            $page,
            'Confirm that the printed names reflect your choices and that the QR area is complete and unobstructed. If the print is incorrect or damaged, return it to the Electoral Board for spoilage and replacement. Do not sign or mark this ballot.',
            54,
            $y - 34,
            485,
            8,
            10,
        );

        return $document->render();
    }
}
