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
        $artifactProfile = (string) config('election.voter.ballot_artifact_profile', 'selected_candidates_official');

        if ($artifactProfile === 'comelec_2022_facsimile') {
            return $this->renderComelecFacsimile($payload, $configuration);
        }

        if ($artifactProfile === 'recorded_selections') {
            return $this->renderRecordedSelections($payload, $configuration);
        }

        return $this->renderSelectedCandidatesOfficial($payload, $configuration);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $configuration
     */
    private function renderSelectedCandidatesOfficial(array $payload, array $configuration): string
    {
        $ballotId = (string) ($payload['ballot_id'] ?? 'unknown');
        $precinctId = (string) ($payload['precinct_id'] ?? 'unknown');
        $document = new ElectionPdfDocument(
            "Voter's Result Ballot",
            $ballotId,
            $precinctId,
            'Selected-candidates paper ballot artifact - simulation only',
        );
        $document->registerPng('BallotQr', (string) $payload['qr_artifact_path']);
        $this->registerBrandingImages($document);
        $page = $document->addPage('Selected candidates');
        $this->drawTimingMarks($document, $page);

        $document->text($page, 'MAY 9, 2022 NATIONAL AND LOCAL', 42, 740, 8, true);
        $document->text($page, 'ELECTIONS', 42, 729, 8, true);
        $document->wrappedText(
            $page,
            (string) ($configuration['jurisdiction_label'] ?? 'BARANGAY 147, TONDO, NATIONAL CAPITAL REGION - MANILA'),
            42,
            716,
            210,
            6.5,
            8,
            true,
        );
        $document->text($page, 'PRINTED PAPER BALLOT - SELECTED CANDIDATES ONLY', 42, 690, 6.8, true);
        $document->wrappedText(
            $page,
            'Verify every printed selection before depositing this paper ballot in the ballot box. Do not sign, mark, or remove this ballot from the precinct.',
            42,
            679,
            230,
            5.9,
            7.2,
        );

        $document->image($page, 'BallotQr', 269, 676, 86, 86);
        $document->text($page, 'SCAN FOR AUDIT VERIFICATION', 312, 666, 6.8, true, 'center');
        $this->drawBrandingLogos($document, $page, 428, 714, 28);
        $document->text($page, 'Clustered Precinct ID: '.$precinctId, 535, 702, 7, true, 'right');
        $document->text($page, 'Precincts in Cluster: '.(string) ($configuration['precincts_in_cluster'] ?? '0538A, 0538B, 0538C'), 535, 689, 6.5, false, 'right');
        $document->wrappedText($page, 'Paper serial: '.(string) ($payload['paper_ballot_serial'] ?? 'UNNUMBERED'), 370, 676, 165, 6, 7, false);
        $document->wrappedText($page, 'Mapping hash: '.substr((string) ($payload['mapping_hash'] ?? 'unknown'), 0, 16), 370, 657, 165, 5.8, 7, false, true);

        $document->line($page, 42, 646, 553, 646, 0.7, 0.15);
        $y = 628.0;

        foreach (($configuration['contests'] ?? []) as $index => $contest) {
            if (! is_array($contest)) {
                continue;
            }

            $blockHeight = $this->selectedContestHeight($document, $contest, $payload);

            if ($y - $blockHeight < 84) {
                $page = $document->addPage('Selected candidates continued');
                $this->drawTimingMarks($document, $page);
                $y = 750.0;
            }

            $this->drawSelectedContest($document, $page, $contest, $payload, $y, $index);
            $y -= $blockHeight + 7;
        }

        if ($y < 144) {
            $page = $document->addPage('Voter verification');
            $this->drawTimingMarks($document, $page);
            $y = 750.0;
        }

        $document->rectangle($page, 42, $y - 58, 511, 58, 0.94, false);
        $document->text($page, 'VOTER VERIFICATION BEFORE DEPOSIT', 54, $y - 17, 8.5, true);
        $document->wrappedText(
            $page,
            'If this printout is incorrect, incomplete, damaged, or unreadable, return it to the Electoral Board for spoilage and replacement before depositing any ballot.',
            54,
            $y - 32,
            480,
            7.2,
            9,
        );

        $this->drawLargeQrPage($document, $payload);

        return $document->render();
    }

    /**
     * @param  array<string, mixed>  $contest
     * @param  array<string, mixed>  $payload
     */
    private function selectedContestHeight(ElectionPdfDocument $document, array $contest, array $payload): float
    {
        $selectedCandidates = $this->selectedCandidateRows($contest, $payload);
        $rowCount = max(1, count($selectedCandidates));
        $candidateLineCount = collect($selectedCandidates)
            ->map(fn (array $candidate): int => max(1, count($document->wrap($this->selectedCandidateLabel($candidate), 420, 7.3))))
            ->sum();

        return 21 + max($rowCount, $candidateLineCount) * 17;
    }

    /**
     * @param  array<string, mixed>  $contest
     * @param  array<string, mixed>  $payload
     */
    private function drawSelectedContest(ElectionPdfDocument $document, int $page, array $contest, array $payload, float $top, int $index): void
    {
        $width = 511.0;
        $left = 42.0;
        $height = $this->selectedContestHeight($document, $contest, $payload);
        $selectedCandidates = $this->selectedCandidateRows($contest, $payload);

        $this->contestHeaderRectangle($document, $page, $left, $top - 16, $width, 16, $this->contestHeaderColor($index));
        $document->text($page, sprintf('%s / Vote for %d', mb_strtoupper((string) ($contest['office'] ?? $contest['title'] ?? 'CONTEST')), (int) ($contest['max_selections'] ?? 1)), $left + ($width / 2), $top - 11, 7.2, true, 'center');
        $document->rectangle($page, $left, $top - $height, $width, $height - 16, 1, false);

        if ($selectedCandidates === []) {
            $document->text($page, 'No selection recorded for this contest.', $left + 16, $top - 37, 7.5, false);

            return;
        }

        $rowTop = $top - 16;

        foreach ($selectedCandidates as $candidate) {
            $rowHeight = max(17, count($document->wrap($this->selectedCandidateLabel($candidate), 420, 7.3)) * 9 + 8);
            $rowBottom = $rowTop - $rowHeight;
            $document->line($page, $left, $rowBottom, $left + $width, $rowBottom, 0.25, 0.5);
            $document->circle($page, $left + 16, $rowTop - 9.5, 3.8, fill: true);
            $document->text($page, (string) ($candidate['ballot_number'] ?? $candidate['ordinal'] ?? ''), $left + 30, $rowTop - 12, 6.8, true);
            $document->wrappedText($page, $this->selectedCandidateLabel($candidate), $left + 58, $rowTop - 9, 420, 7.3, 8.5, true);
            $rowTop = $rowBottom;
        }
    }

    /**
     * @param  array<string, mixed>  $contest
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function selectedCandidateRows(array $contest, array $payload): array
    {
        $contestId = (string) ($contest['id'] ?? '');
        $selectedIds = array_values((array) (($payload['selections'] ?? [])[$contestId] ?? []));
        $candidateMap = [];

        foreach (($contest['candidates'] ?? []) as $candidate) {
            if (is_array($candidate) && isset($candidate['id'])) {
                $candidateMap[(string) $candidate['id']] = $candidate;
            }
        }

        return collect($selectedIds)
            ->map(fn (mixed $candidateId): array => $candidateMap[(string) $candidateId] ?? [
                'id' => (string) $candidateId,
                'name' => (string) $candidateId,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function selectedCandidateLabel(array $candidate): string
    {
        $party = trim((string) ($candidate['political_party'] ?? $candidate['party'] ?? ''));
        $name = (string) ($candidate['name'] ?? $candidate['id'] ?? 'UNKNOWN CANDIDATE');

        return $party === '' ? $name : "{$name} ({$party})";
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $configuration
     */
    private function renderRecordedSelections(array $payload, array $configuration): string
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
            ['QR payload version', str_starts_with((string) ($payload['qr_payload'] ?? ''), 'aes-ballot-compact-1:') ? 'aes-ballot-compact-1' : 'aes-ballot-zlib-1'],
            ['Mapping hash', substr((string) ($payload['mapping_hash'] ?? 'unknown'), 0, 16)],
        ];
        $y = 674.0;

        foreach ($metadata as [$label, $value]) {
            $document->text($page, $label, 42, $y, 7.5, true);
            $document->wrappedText($page, $value, 145, $y, 208, 8.5, 10.5);
            $y -= 23;
        }

        $document->rectangle($page, 363, 499, 190, 191, 0.97);
        $document->image($page, 'BallotQr', 366, 505, 184, 184);
        $document->text($page, 'SCAN FOR QR-ASSISTED AUDIT', 458, 501, 7.5, true, 'center');
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
        $document->wrappedText(
            $page,
            'The QR stores precinct data, paper serial, mapping hash, and mapped candidate codes. It is not a server ballot lookup.',
            42,
            508,
            300,
            7.5,
            9,
        );

        $y = 474;
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

        $qrPage = $document->addPage('Large QR verification copy');
        $document->text($qrPage, 'BALLOT QR VERIFICATION COPY', 42, 714, 13, true);
        $document->wrappedText(
            $qrPage,
            'This enlarged QR code carries the same compact ballot payload as the first page. It is provided for scanner compatibility during audit and demonstration review.',
            42,
            694,
            500,
            9,
            12,
        );
        $document->rectangle($qrPage, 92, 236, 412, 412, 0.97);
        $document->image($qrPage, 'BallotQr', 106, 250, 384, 384);
        $document->text($qrPage, 'SCAN THIS LARGE QR FOR AUDIT VERIFICATION', 298, 218, 9, true, 'center');
        $document->text($qrPage, 'Paper ballot serial', 42, 176, 8, true);
        $document->wrappedText($qrPage, (string) ($payload['paper_ballot_serial'] ?? 'SERIAL UNAVAILABLE'), 170, 176, 360, 8.5, 10);
        $document->text($qrPage, 'Payload SHA-256', 42, 150, 8, true);
        $document->wrappedText(
            $qrPage,
            (string) ($payload['payload_hash'] ?? 'unknown'),
            170,
            150,
            360,
            7.5,
            9,
            monospace: true,
        );

        return $document->render();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $configuration
     */
    private function renderComelecFacsimile(array $payload, array $configuration): string
    {
        $ballotId = (string) ($payload['ballot_id'] ?? 'unknown');
        $precinctId = (string) ($payload['precinct_id'] ?? '39010402');
        $document = new ElectionPdfDocument(
            'COMELEC-Style Simulation Ballot',
            $ballotId,
            $precinctId,
            'Paper ballot facsimile - simulation only',
        );
        $document->registerPng('BallotQr', (string) $payload['qr_artifact_path']);
        $this->registerBrandingImages($document);

        $page = $document->addPage('Official ballot facsimile front');
        $this->drawTimingMarks($document, $page);
        $this->drawComelecHeader($document, $page, $payload, $precinctId);
        $y = 598.0;

        foreach (($configuration['contests'] ?? []) as $index => $contest) {
            if (! is_array($contest)) {
                continue;
            }

            if (($contest['office'] ?? null) === 'PARTY LIST') {
                continue;
            }

            $height = $this->contestHeight($contest);

            if ($y - $height < 80) {
                $page = $document->addPage('Official ballot facsimile continued');
                $this->drawTimingMarks($document, $page);
                $y = 750.0;
            }

            $this->drawContest($document, $page, $contest, (array) ($payload['selections'] ?? []), $y, $index);
            $y -= $height + 5;
        }

        $partyList = collect($configuration['contests'] ?? [])
            ->first(fn (mixed $contest): bool => is_array($contest) && ($contest['office'] ?? null) === 'PARTY LIST');

        if (is_array($partyList)) {
            $page = $document->addPage('Official ballot facsimile back');
            $this->drawTimingMarks($document, $page);
            $this->drawContest($document, $page, $partyList, (array) ($payload['selections'] ?? []), 750, 1);
            $document->text($page, 'Page 2 - Party List side', 506, 40, 7, true, 'right');
        }

        $this->drawLargeQrPage($document, $payload);

        return $document->render();
    }

    private function drawTimingMarks(ElectionPdfDocument $document, int $page): void
    {
        foreach (range(0, 23) as $index) {
            $document->rectangle($page, 32 + ($index * 22), 824, 9, 8, 0.02);
            $document->rectangle($page, 32 + ($index * 22), 10, 9, 8, 0.02);
        }

        foreach (range(0, 30) as $index) {
            $document->rectangle($page, 16, 58 + ($index * 23), 8, 8, 0.02);
            $document->rectangle($page, 571, 58 + ($index * 23), 8, 8, 0.02);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function drawComelecHeader(ElectionPdfDocument $document, int $page, array $payload, string $precinctId): void
    {
        $document->text($page, 'MAY 9, 2022 NATIONAL AND LOCAL', 42, 740, 8, true);
        $document->text($page, 'ELECTIONS', 42, 729, 8, true);
        $document->text($page, 'BARANGAY 147, TONDO, NATIONAL CAPITAL REGION - MANILA', 42, 716, 6.5, true);
        $document->text($page, 'Type: National and Local', 42, 705, 6.5, true);
        $document->text($page, 'INSTRUCTIONS FOR VOTING', 42, 690, 6.5, true);
        $document->wrappedText($page, '(1) Completely mark the circle beside the name of the desired candidate. (2) Do not vote for more than the allowed number. (3) Review before printing.', 42, 680, 210, 5.8, 7);
        $document->image($page, 'BallotQr', 267, 692, 72, 72);
        $this->drawBrandingLogos($document, $page, 428, 714, 28);
        $document->text($page, 'Clustered Precinct ID: '.$precinctId, 535, 702, 7, true, 'right');
        $document->text($page, 'Precincts in Cluster: 0538A, 0538B, 0538C', 535, 689, 6.5, false, 'right');
        $document->text($page, 'Paper serial: '.(string) ($payload['paper_ballot_serial'] ?? 'UNNUMBERED'), 535, 676, 6, false, 'right');
        $document->rectangle($page, 424, 650, 111, 46, 1, false);
        $document->text($page, 'Signature of Chairman', 479.5, 656, 5.5, false, 'center');
    }

    /**
     * @param  array<string, mixed>  $contest
     * @param  array<string, array<int, string>>  $selections
     */
    private function drawContest(ElectionPdfDocument $document, int $page, array $contest, array $selections, float $top, int $index): void
    {
        $candidateCount = count((array) ($contest['candidates'] ?? []));
        $columns = $candidateCount >= 40 ? 4 : ($candidateCount >= 6 ? 2 : 1);
        $left = 42.0;
        $width = 511.0;
        $headerColor = $this->contestHeaderColor($index);
        $this->contestHeaderRectangle($document, $page, $left, $top - 14, $width, 14, $headerColor);
        $document->text($page, sprintf('%s / Vote for %d', (string) ($contest['office'] ?? $contest['title'] ?? 'CONTEST'), (int) ($contest['max_selections'] ?? 1)), $left + ($width / 2), $top - 10, 7, true, 'center');

        $columnsOfCandidates = $this->candidateColumns((array) ($contest['candidates'] ?? []), $columns);
        $columnWidth = $width / $columns;
        $rowHeight = $candidateCount >= 40 ? 13.0 : 18.0;
        $selected = array_flip(array_values((array) ($selections[(string) ($contest['id'] ?? '')] ?? [])));
        $maxRows = max(array_map('count', $columnsOfCandidates) ?: [0]);
        $tableTop = $top - 14;
        $tableHeight = $maxRows * $rowHeight;

        foreach (range(0, $columns) as $columnLine) {
            $x = $left + ($columnLine * $columnWidth);
            $document->line($page, $x, $tableTop, $x, $tableTop - $tableHeight, 0.35, 0.2);
        }

        foreach (range(0, $maxRows) as $rowLine) {
            $y = $tableTop - ($rowLine * $rowHeight);
            $document->line($page, $left, $y, $left + $width, $y, 0.25, 0.45);
        }

        foreach ($columnsOfCandidates as $columnIndex => $candidates) {
            foreach ($candidates as $rowIndex => $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                $x = $left + ($columnIndex * $columnWidth);
                $y = $tableTop - 10 - ($rowIndex * $rowHeight);
                $candidateId = (string) ($candidate['id'] ?? '');
                $marked = isset($selected[$candidateId]);
                $document->circle($page, $x + 10, $y + 2.5, 3.6, fill: $marked);
                $document->text($page, (string) ($candidate['ballot_number'] ?? $candidate['ordinal'] ?? ''), $x + 22, $y, 5.8, true);
                $document->wrappedText($page, (string) ($candidate['name'] ?? $candidateId), $x + 36, $y + 2, $columnWidth - 40, $candidateCount >= 40 ? 5.2 : 6, $candidateCount >= 40 ? 6 : 7, true);
                $party = trim((string) ($candidate['political_party'] ?? ''));

                if ($party !== '') {
                    $document->wrappedText($page, '('.$party.')', $x + 36, $y - 5, $columnWidth - 40, 4.8, 5.5);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $contest
     */
    private function contestHeight(array $contest): float
    {
        $candidateCount = count((array) ($contest['candidates'] ?? []));
        $columns = $candidateCount >= 40 ? 4 : ($candidateCount >= 6 ? 2 : 1);
        $rowHeight = $candidateCount >= 40 ? 13.0 : 18.0;

        return 14 + (ceil($candidateCount / $columns) * $rowHeight);
    }

    /**
     * @param  array<int, mixed>  $candidates
     * @return array<int, array<int, mixed>>
     */
    private function candidateColumns(array $candidates, int $columns): array
    {
        $columnSize = (int) ceil(count($candidates) / $columns);

        return collect(range(0, $columns - 1))
            ->map(fn (int $index): array => array_slice($candidates, $index * $columnSize, $columnSize))
            ->filter(fn (array $column): bool => $column !== [])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function drawLargeQrPage(ElectionPdfDocument $document, array $payload): void
    {
        $qrPage = $document->addPage('Large QR verification copy');
        $this->drawBrandingLogos($document, $qrPage, 452, 696, 28);
        $document->text($qrPage, 'BALLOT QR VERIFICATION COPY', 42, 714, 13, true);
        $document->wrappedText(
            $qrPage,
            'This enlarged QR code carries the same compact ballot payload as the ballot face. It is provided for scanner compatibility during audit and demonstration review.',
            42,
            694,
            500,
            9,
            12,
        );
        $document->rectangle($qrPage, 92, 236, 412, 412, 0.97);
        $document->image($qrPage, 'BallotQr', 106, 250, 384, 384);
        $document->text($qrPage, 'SCAN THIS LARGE QR FOR AUDIT VERIFICATION', 298, 218, 9, true, 'center');
        $document->text($qrPage, 'Paper ballot serial', 42, 176, 8, true);
        $document->wrappedText($qrPage, (string) ($payload['paper_ballot_serial'] ?? 'SERIAL UNAVAILABLE'), 170, 176, 360, 8.5, 10);
        $document->text($qrPage, 'Payload SHA-256', 42, 150, 8, true);
        $document->wrappedText(
            $qrPage,
            (string) ($payload['payload_hash'] ?? 'unknown'),
            170,
            150,
            360,
            7.5,
            9,
            monospace: true,
        );
    }

    private function printColored(): bool
    {
        return (bool) config('election.branding.print_colored', true);
    }

    private function registerBrandingImages(ElectionPdfDocument $document): void
    {
        foreach ($this->brandingImagePaths() as $name => $path) {
            if (is_file($path)) {
                $document->registerImage($name, $path, $this->printColored());
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function brandingImagePaths(): array
    {
        return [
            'RepublicSeal' => (string) config('election.branding.republic_seal', ''),
            'BagongPilipinasLogo' => (string) config('election.branding.bagong_pilipinas_logo', ''),
            'ComelecLogo' => (string) config('election.branding.comelec_logo', ''),
        ];
    }

    private function drawBrandingLogos(ElectionPdfDocument $document, int $page, float $x, float $y, float $size): void
    {
        $paths = $this->brandingImagePaths();
        $offset = 0.0;

        foreach (['RepublicSeal', 'BagongPilipinasLogo', 'ComelecLogo'] as $name) {
            if (! is_file($paths[$name] ?? '')) {
                continue;
            }

            $document->image($page, $name, $x + $offset, $y, $size, $size);
            $offset += $size + 7;
        }
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private function contestHeaderColor(int $index): array
    {
        return $index % 2 === 0
            ? [0.72, 0.86, 0.98]
            : [0.72, 0.91, 0.78];
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $rgb
     */
    private function contestHeaderRectangle(ElectionPdfDocument $document, int $page, float $x, float $y, float $width, float $height, array $rgb): void
    {
        if ($this->printColored()) {
            $document->rectangleRgb($page, $x, $y, $width, $height, $rgb);

            return;
        }

        $document->rectangle($page, $x, $y, $width, $height, 0.82);
    }
}
