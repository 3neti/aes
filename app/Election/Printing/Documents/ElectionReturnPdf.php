<?php

namespace App\Election\Printing\Documents;

use App\Election\Returns\ElectionReturnContestScopes;
use App\Election\Returns\ElectionReturnScope;

final class ElectionReturnPdf
{
    public function __construct(
        private readonly ContestResultTable $results,
        private readonly ElectionReturnContestScopes $scopes,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $return
     */
    public function render(array $configuration, array $return, ElectionReturnScope $scope = ElectionReturnScope::Combined): string
    {
        $precinctId = (string) ($return['precinct_id'] ?? 'unknown');
        $configuration = $this->scopes->configurationFor($configuration, $scope);
        $document = new ElectionPdfDocument(
            $scope->label(),
            (string) ($return['return_hash'] ?? 'return'),
            $precinctId,
            $scope->title(),
            $scope === ElectionReturnScope::Combined ? 'evidence' : 'plain',
        );
        $page = $document->addPage('Return summary');
        $tableTop = 552.0;

        if ($scope === ElectionReturnScope::Combined) {
            $document->rectangle($page, 42, 688, 511, 36, 0.88);
            $document->text($page, 'SIMULATION COPY - SUBJECT TO COMELEC FORM APPROVAL', 297.5, 701, 9.5, true, 'center');
            $document->text($page, mb_strtoupper($scope->title()), 297.5, 682, 10.5, true, 'center');
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
                'The Electoral Board certifies that the following totals were generated from the accepted paper-ballot records for this clustered precinct. Paper ballots, VVDAT records, and signed forms remain controlling evidence.',
                42,
                594,
                511,
                8.5,
                11,
            );
        } else {
            $this->renderOfficialHeader($document, $page, $configuration, $return, $scope);
            $tableTop = 574.0;
        }

        $result = $scope === ElectionReturnScope::Combined
            ? $this->results->render(
                $document,
                $configuration,
                (array) ($return['tally'] ?? []),
                $page,
                $tableTop,
            )
            : $this->renderCompactScopedReturn(
                $document,
                $configuration,
                (array) ($return['tally'] ?? []),
                $scope,
                $page,
                $tableTop,
            );
        $page = $result['page'];
        $y = $result['y'];

        $minimumCertificationSpace = $scope === ElectionReturnScope::Combined ? 252 : 220;

        if ($y < $minimumCertificationSpace) {
            $page = $document->addPage('Electoral Board certification');
            $y = $scope === ElectionReturnScope::Combined ? ElectionPdfDocument::ContentTop : 734;
        }

        if ($scope !== ElectionReturnScope::Combined) {
            $this->renderOfficialCertification($document, $page, $return, $scope, $y);

            return $document->render();
        }

        $document->rectangle($page, 42, $y - 178, 511, 178, 0.94, false);
        $document->text($page, 'ELECTORAL BOARD CERTIFICATION', 54, $y - 22, 10, true);
        $document->wrappedText(
            $page,
            'We certify that this '.$scope->label().' contains the complete candidate listing and vote totals for the positions shown above, subject to verification against the paper ballots, tally sheet, minutes, and prescribed COMELEC forms.',
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

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, array<string, int>>  $tally
     * @return array{page: int, y: float, candidate_count: int}
     */
    private function renderCompactScopedReturn(ElectionPdfDocument $document, array $configuration, array $tally, ElectionReturnScope $scope, int $page, float $y): array
    {
        if ($scope === ElectionReturnScope::National) {
            return $this->renderCompactNationalReturn($document, $configuration, $tally, $page, $y);
        }

        return $this->renderCompactLocalReturn($document, $configuration, $tally, $page, $y);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, array<string, int>>  $tally
     * @return array{page: int, y: float, candidate_count: int}
     */
    private function renderCompactNationalReturn(ElectionPdfDocument $document, array $configuration, array $tally, int $page, float $y): array
    {
        $columns = [
            ['title' => 'PRESIDENT', 'contest' => $this->findContest($configuration, ['president'], ['vice']), 'x' => 42.0, 'width' => 86.0],
            ['title' => 'VICE PRESIDENT', 'contest' => $this->findContest($configuration, ['vice president', 'vice_president']), 'x' => 128.0, 'width' => 86.0],
            ['title' => 'SENATOR', 'contest' => $this->findContest($configuration, ['senator']), 'x' => 214.0, 'width' => 170.0],
            ['title' => 'PARTY-LIST', 'contest' => $this->findContest($configuration, ['party list', 'party_list', 'partylist']), 'x' => 384.0, 'width' => 169.0],
        ];

        return $this->renderColumnPages($document, $columns, $tally, $page, $y, 218);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, array<string, int>>  $tally
     * @return array{page: int, y: float, candidate_count: int}
     */
    private function renderCompactLocalReturn(ElectionPdfDocument $document, array $configuration, array $tally, int $page, float $y): array
    {
        $leftX = 42.0;
        $rightX = 300.0;
        $columnWidth = 253.0;
        $rows = [
            [
                ['title' => 'REPRESENTATIVE', 'contest' => $this->findContest($configuration, ['representative', 'house of representative', 'house of representatives']), 'x' => $leftX, 'width' => $columnWidth],
                ['title' => 'MAYOR', 'contest' => $this->findContest($configuration, ['mayor'], ['vice']), 'x' => $rightX, 'width' => $columnWidth],
            ],
            [
                ['title' => 'GOVERNOR', 'contest' => $this->findContest($configuration, ['governor'], ['vice']), 'x' => $leftX, 'width' => $columnWidth],
                ['title' => 'VICE-MAYOR', 'contest' => $this->findContest($configuration, ['vice mayor', 'vice_mayor']), 'x' => $rightX, 'width' => $columnWidth],
            ],
            [
                ['title' => 'VICE-GOVERNOR', 'contest' => $this->findContest($configuration, ['vice governor', 'vice_governor']), 'x' => $leftX, 'width' => $columnWidth],
                ['title' => 'SANGGUNIAN BAYAN', 'contest' => $this->findContest($configuration, ['sanggunian bayan', 'councilor', 'council']), 'x' => $rightX, 'width' => $columnWidth],
            ],
            [
                ['title' => 'SANGGUNIAN PANLALAWIGAN', 'contest' => $this->findContest($configuration, ['sanggunian panlalawigan', 'provincial board']), 'x' => $leftX, 'width' => 511.0],
            ],
        ];

        return $this->renderLocalRows($document, $rows, $tally, $page, $y);
    }

    /**
     * @param  array<int, array{title: string, contest: array<string, mixed>|null, x: float, width: float}>  $columns
     * @param  array<string, array<string, int>>  $tally
     * @return array{page: int, y: float, candidate_count: int}
     */
    private function renderColumnPages(ElectionPdfDocument $document, array $columns, array $tally, int $page, float $top, float $bottom): array
    {
        $offsets = array_fill(0, count($columns), 0);
        $candidateCount = 0;
        $pageIndex = 0;
        $lastY = $top;

        do {
            if ($pageIndex > 0) {
                $page = $document->addPage('Election Return continuation');
                $top = ElectionPdfDocument::ContentTop;
            }

            $remaining = false;
            $pageCandidateCount = 0;
            $lastY = $top;

            foreach ($columns as $index => $column) {
                $result = $this->renderContestColumn($document, $page, $column, $tally, $offsets[$index], $top, $bottom);
                $offsets[$index] = $result['offset'];
                $candidateCount += $result['rendered'];
                $pageCandidateCount += $result['rendered'];
                $lastY = min($lastY, $result['y']);
                $remaining = $remaining || $result['remaining'];
            }

            if ($pageCandidateCount === 0 && $candidateCount === 0) {
                $document->text($page, 'No contests are configured for this return scope.', 54, $top - 36, 9, true);
                $lastY = $top - 58;
                break;
            }

            $pageIndex++;
        } while ($remaining);

        return [
            'page' => $page,
            'y' => $lastY - 16,
            'candidate_count' => $candidateCount,
        ];
    }

    /**
     * @param  array{title: string, contest: array<string, mixed>|null, x: float, width: float}  $column
     * @param  array<string, array<string, int>>  $tally
     * @return array{offset: int, rendered: int, remaining: bool, y: float}
     */
    private function renderContestColumn(ElectionPdfDocument $document, int $page, array $column, array $tally, int $offset, float $top, float $bottom): array
    {
        $x = $column['x'];
        $width = $column['width'];
        $headerHeight = 32.0;
        $rowHeight = 9.2;
        $candidates = (array) ($column['contest']['candidates'] ?? []);
        $contestId = (string) ($column['contest']['id'] ?? '');
        $rowsPerPage = max(1, (int) floor(($top - $bottom - $headerHeight - 8) / $rowHeight));
        $slice = array_slice($candidates, $offset, $rowsPerPage);

        $document->rectangle($page, $x, $bottom, $width, $top - $bottom, 0.98, false);
        $document->rectangle($page, $x, $top - $headerHeight, $width, $headerHeight, 0.90);
        $document->wrappedText($page, $column['title'], $x + 4, $top - 10, $width - 8, 7.2, 8.2, true);
        $this->renderContestSubtitle($document, $page, $column['contest'], $column['title'], $x + 4, $top - 19, $width - 38);
        $document->text($page, 'VOTES', $x + $width - 4, $top - 25, 5.8, true, 'right');

        if ($column['contest'] === null) {
            $document->wrappedText($page, 'No contest for this precinct.', $x + 6, $top - 48, $width - 12, 6.4, 8);

            return [
                'offset' => $offset,
                'rendered' => 0,
                'remaining' => false,
                'y' => $top - 64,
            ];
        }

        $y = $top - $headerHeight - 8;
        $rendered = 0;

        foreach ($slice as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $candidateId = (string) ($candidate['id'] ?? '');
            $votes = (int) ($tally[$contestId][$candidateId] ?? 0);
            $number = (string) ($candidate['ballot_number'] ?? $candidate['ordinal'] ?? '-');
            $name = $this->compactCandidateName((string) ($candidate['name'] ?? $candidateId), $width < 100 ? 16 : 34);
            $document->text($page, $number.'.', $x + 4, $y, 5.6);
            $document->text($page, $name, $x + 17, $y, 5.6);
            $document->text($page, (string) $votes, $x + $width - 4, $y, 5.8, true, 'right');
            $y -= $rowHeight;
            $rendered++;
        }

        return [
            'offset' => $offset + count($slice),
            'rendered' => $rendered,
            'remaining' => $offset + count($slice) < count($candidates),
            'y' => $y,
        ];
    }

    /**
     * @param  array<int, array<int, array{title: string, contest: array<string, mixed>|null, x: float, width: float}>>  $rows
     * @param  array<string, array<string, int>>  $tally
     * @return array{page: int, y: float, candidate_count: int}
     */
    private function renderLocalRows(ElectionPdfDocument $document, array $rows, array $tally, int $page, float $y): array
    {
        $candidateCount = 0;

        foreach ($rows as $row) {
            $offsets = array_fill(0, count($row), 0);
            $remaining = true;

            while ($remaining) {
                $rowCandidateCount = max(
                    ...collect($row)
                        ->map(fn (array $slot, int $index): int => max(1, count((array) ($slot['contest']['candidates'] ?? [])) - $offsets[$index]))
                        ->push(1)
                        ->all(),
                );
                $rowHeight = min(
                    $y - ElectionPdfDocument::ContentBottom,
                    min(250.0, max(82.0, 38.0 + ($rowCandidateCount * 10.2))),
                );

                if ($rowHeight < 82.0 || $y - $rowHeight < ElectionPdfDocument::ContentBottom) {
                    $page = $document->addPage('Local Election Return continuation');
                    $y = ElectionPdfDocument::ContentTop;
                    $rowHeight = min(250.0, max(82.0, 38.0 + ($rowCandidateCount * 10.2)));
                }

                $remaining = false;

                foreach ($row as $index => $slot) {
                    $result = $this->renderLocalSlot($document, $page, $slot, $tally, $y, $rowHeight, $offsets[$index]);
                    $offsets[$index] = $result['offset'];
                    $candidateCount += $result['rendered'];
                    $remaining = $remaining || $result['remaining'];
                }

                $y -= $rowHeight + 10;

                if ($remaining) {
                    $page = $document->addPage('Local Election Return continuation');
                    $y = ElectionPdfDocument::ContentTop;
                }
            }
        }

        return [
            'page' => $page,
            'y' => $y,
            'candidate_count' => $candidateCount,
        ];
    }

    /**
     * @param  array{title: string, contest: array<string, mixed>|null, x: float, width: float}  $slot
     * @param  array<string, array<string, int>>  $tally
     */
    private function renderLocalSlot(ElectionPdfDocument $document, int $page, array $slot, array $tally, float $top, float $height, int $offset): array
    {
        $x = $slot['x'];
        $width = $slot['width'];
        $bottom = $top - $height;
        $document->rectangle($page, $x, $bottom, $width, $height, 0.98, false);
        $document->rectangle($page, $x, $top - 26, $width, 26, 0.90);
        $document->text($page, $slot['title'], $x + 8, $top - 16, 8, true);
        $this->renderContestSubtitle($document, $page, $slot['contest'], $slot['title'], $x + 8, $top - 24, $width - 58);
        $document->text($page, 'VOTES', $x + $width - 8, $top - 16, 6.2, true, 'right');

        if ($slot['contest'] === null) {
            $document->text($page, 'No contest for this precinct.', $x + 8, $top - 44, 7.2, true);

            return [
                'offset' => $offset,
                'rendered' => 0,
                'remaining' => false,
            ];
        }

        $contestId = (string) ($slot['contest']['id'] ?? '');
        $candidates = (array) ($slot['contest']['candidates'] ?? []);
        $y = $top - 42;
        $rendered = 0;

        foreach (array_slice($candidates, $offset) as $candidate) {
            if (! is_array($candidate) || $y < $bottom + 12) {
                break;
            }

            $candidateId = (string) ($candidate['id'] ?? '');
            $votes = (int) ($tally[$contestId][$candidateId] ?? 0);
            $number = (string) ($candidate['ballot_number'] ?? $candidate['ordinal'] ?? '-');
            $name = $this->compactCandidateName((string) ($candidate['name'] ?? $candidateId), $width > 300 ? 78 : 44);
            $document->text($page, $number.'.', $x + 8, $y, 6.4);
            $document->text($page, $name, $x + 28, $y, 6.4);
            $document->text($page, (string) $votes, $x + $width - 8, $y, 6.8, true, 'right');
            $y -= 10.2;
            $rendered++;
        }

        return [
            'offset' => $offset + $rendered,
            'rendered' => $rendered,
            'remaining' => $offset + $rendered < count($candidates),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $return
     */
    private function renderOfficialHeader(ElectionPdfDocument $document, int $page, array $configuration, array $return, ElectionReturnScope $scope): void
    {
        $this->registerComelecSeal($document);

        if ($this->hasComelecSeal()) {
            $document->image($page, 'ComelecSeal', 276, 773, 44, 44);
        }

        $document->text($page, 'Republic of the Philippines', 297.5, 762, 8.5, true, 'center');
        $document->text($page, 'COMMISSION ON ELECTIONS', 297.5, 748, 10.5, true, 'center');
        $document->text($page, $this->electionLabel($return), 297.5, 734, 8.5, true, 'center');
        $document->text($page, mb_strtoupper($scope->title()), 297.5, 714, 11, true, 'center');
        $document->line($page, 201, 704, 394, 704, 1.0, 0.08);
        $document->line($page, 201, 696, 394, 696, 0.7, 0.08);

        $leftRows = [
            ['Province', $this->configurationValue($configuration, 'province', (string) config('election.election_return_form.province'))],
            ['City/Municipality', $this->configurationValue($configuration, 'city_municipality', (string) config('election.election_return_form.city_municipality'))],
            ['Barangay', $this->configurationValue($configuration, 'barangay', (string) config('election.election_return_form.barangay'))],
            ['Voting Center', $this->configurationValue($configuration, 'voting_center', (string) config('election.election_return_form.voting_center'))],
            ['Precincts in a Cluster', (string) ($configuration['precinct_id'] ?? $return['precinct_id'] ?? 'unknown')],
        ];
        $rightRows = [
            ['Machine ID', $this->machineId()],
            ['Clustered Precinct ID', (string) ($configuration['precinct_id'] ?? $return['precinct_id'] ?? 'unknown')],
            ['WAES Machine Status', $this->machineStatus()],
        ];

        $this->renderDetailRows($document, $page, $leftRows, 80, 675, 98, 7.2);
        $this->renderDetailRows($document, $page, $rightRows, 315, 675, 98, 7.2);

        $registered = (int) config('election.election_return_form.registered_voters', 0);
        $accepted = (int) ($return['accepted_ballots'] ?? 0);
        $rejected = (int) ($return['rejected_ballots'] ?? 0);
        $voted = max($accepted + $rejected, $accepted);
        $diverted = (int) config('election.election_return_form.ballots_diverted', $rejected);

        $this->renderDetailRows($document, $page, [
            ['No. of registered voters', (string) $registered],
            ['No. of voters who voted', (string) $voted],
            ['No. of valid ballots cast', (string) $accepted],
            ['No. of ballots diverted', (string) $diverted],
        ], 80, 626, 120, 7.2);

        $document->text($page, 'Result hash:', 315, 626, 7.2, true);
        foreach ($this->hashLines((string) ($return['return_hash'] ?? 'unknown')) as $index => $line) {
            $document->text($page, $line, 384, 626 - ($index * 11), 6.9, false, monospace: true);
        }
    }

    /**
     * @param  array<string, mixed>  $return
     */
    private function renderOfficialCertification(ElectionPdfDocument $document, int $page, array $return, ElectionReturnScope $scope, float $y): void
    {
        $top = min($y, 212);
        $document->line($page, 42, $top, 553, $top, 0.9, 0.08);
        $document->wrappedText(
            $page,
            'WE HEREBY CERTIFY that we witnessed the voting at the precinct and that the votes obtained by each candidate appearing in this '.mb_strtoupper($scope->title()).' are true as generated by the WAES Machine with ID no. '.$this->machineId().' on '.$this->generatedAt().'.',
            50,
            $top - 12,
            495,
            6.9,
            8.5,
            true,
        );
        $document->text($page, 'THE ELECTORAL BOARD', 297.5, $top - 44, 9.5, true, 'center');

        $members = [
            [$this->boardMemberName('chairperson'), 'CHAIRPERSON', 118.0],
            [$this->boardMemberName('poll_clerk'), 'POLL CLERK', 297.5],
            [$this->boardMemberName('third_member'), 'THIRD MEMBER', 477.0],
        ];

        foreach ($members as [$name, $role, $centerX]) {
            $this->renderThumbmarkSlot($document, $page, (string) $name, (string) $role, (float) $centerX, $top - 58);
        }

        $watcherTop = $top - 156;
        $document->text($page, 'WATCHERS', 297.5, $watcherTop, 7.5, true, 'center');
        $this->renderWatcherLine($document, $page, 92, $watcherTop - 18, 190);
        $this->renderWatcherLine($document, $page, 314, $watcherTop - 18, 190);
    }

    private function renderThumbmarkSlot(ElectionPdfDocument $document, int $page, string $name, string $role, float $centerX, float $top): void
    {
        $boxWidth = 42.0;
        $boxHeight = 34.0;
        $document->rectangle($page, $centerX - ($boxWidth / 2), $top - $boxHeight, $boxWidth, $boxHeight, 0.94, false);
        $document->text($page, 'Right Thumbmark', $centerX, $top - $boxHeight - 8, 5.7, true, 'center');
        $document->line($page, $centerX - 58, $top - $boxHeight - 30, $centerX + 58, $top - $boxHeight - 30, 0.7, 0.08);
        $document->text($page, mb_strtoupper($name), $centerX, $top - $boxHeight - 43, 7, true, 'center');
        $document->text($page, mb_strtoupper($role), $centerX, $top - $boxHeight - 53, 6.5, true, 'center');
    }

    private function renderWatcherLine(ElectionPdfDocument $document, int $page, float $x, float $y, float $width): void
    {
        $document->line($page, $x, $y, $x + $width, $y, 0.7, 0.08);
        $document->text($page, 'Signature over printed name', $x + ($width / 2), $y - 10, 5.8, false, 'center');
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $rows
     */
    private function renderDetailRows(ElectionPdfDocument $document, int $page, array $rows, float $x, float $y, float $labelWidth, float $size): void
    {
        foreach ($rows as [$label, $value]) {
            $document->text($page, $label.':', $x, $y, $size, true);
            $document->text($page, $value, $x + $labelWidth, $y, $size, true);
            $y -= 11;
        }
    }

    private function registerComelecSeal(ElectionPdfDocument $document): void
    {
        if (! $this->hasComelecSeal()) {
            return;
        }

        $document->registerPng('ComelecSeal', (string) config('election.branding.comelec_logo'), true);
    }

    private function hasComelecSeal(): bool
    {
        return is_file((string) config('election.branding.comelec_logo'));
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function configurationValue(array $configuration, string $key, string $fallback): string
    {
        $value = $configuration[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== ''
            ? (string) $value
            : $fallback;
    }

    /** @param array<string, mixed> $return */
    private function electionLabel(array $return): string
    {
        $configured = (string) config('election.election_return_form.election_label');

        return $configured !== '' ? $configured : (string) ($return['election_id'] ?? 'ELECTION');
    }

    private function machineId(): string
    {
        return (string) config('election.election_return_form.machine_id', 'WAES-DEVICE');
    }

    private function machineStatus(): string
    {
        return mb_strtoupper((string) config('election.election_return_form.machine_status', 'CLOSED'));
    }

    private function generatedAt(): string
    {
        return (string) config('election.election_return_form.generated_at', 'May 9, 2022 at 19:18:50');
    }

    private function boardMemberName(string $role): string
    {
        return (string) config("election.election_return_form.electoral_board.{$role}", 'ELECTORAL BOARD MEMBER');
    }

    /**
     * @return array<int, string>
     */
    private function hashLines(string $hash): array
    {
        $pairs = trim(implode(' ', str_split(mb_strtoupper($hash), 2)));

        return str_split($pairs, 32);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<int, string>  $needles
     * @param  array<int, string>  $without
     * @return array<string, mixed>|null
     */
    private function findContest(array $configuration, array $needles, array $without = []): ?array
    {
        foreach (($configuration['contests'] ?? []) as $contest) {
            if (! is_array($contest)) {
                continue;
            }

            $key = (string) str(implode(' ', [
                $contest['id'] ?? '',
                $contest['office'] ?? '',
                $contest['title'] ?? '',
            ]))
                ->lower()
                ->replace(['-', '_', '/', ','], ' ')
                ->squish();

            if (collect($without)->contains(fn (string $needle): bool => str_contains($key, $needle))) {
                continue;
            }

            if (collect($needles)->contains(fn (string $needle): bool => str_contains($key, $needle))) {
                return $contest;
            }
        }

        return null;
    }

    /** @param array<string, mixed>|null $contest */
    private function renderContestSubtitle(ElectionPdfDocument $document, int $page, ?array $contest, string $fallback, float $x, float $y, float $width): void
    {
        if ($contest === null) {
            return;
        }

        $title = (string) ($contest['title'] ?? $fallback);

        if (mb_strtoupper($title) === mb_strtoupper($fallback)) {
            return;
        }

        $document->wrappedText($page, $title, $x, $y, $width, 4.5, 5.2);
    }

    private function compactCandidateName(string $name, int $limit): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return mb_strlen($name) <= $limit
            ? $name
            : mb_substr($name, 0, max(1, $limit - 1)).'.';
    }
}
