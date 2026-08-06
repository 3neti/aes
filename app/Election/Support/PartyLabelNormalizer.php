<?php

namespace App\Election\Support;

final class PartyLabelNormalizer
{
    public function normalize(?string $party): ?string
    {
        if ($party === null) {
            return null;
        }

        $party = trim((string) preg_replace('/\s+/u', ' ', $party));

        if ($party === '') {
            return null;
        }

        $party = $this->stripBoilerplate($party);

        return $party === '' ? null : $party;
    }

    private function stripBoilerplate(string $party): string
    {
        $markers = [
            ' pertinent documents attached thereto',
            ' documents attached thereto',
            ' that are shared by the Commission on Elections',
            ' in compliance with the existing laws and rules',
            ' and in conformity with the Data Privacy Act',
            ' relating to data privacy',
        ];

        $lower = mb_strtolower($party);
        $positions = collect($markers)
            ->map(fn (string $marker): int|false => mb_strpos($lower, $marker))
            ->filter(fn (int|false $position): bool => $position !== false)
            ->values();

        if ($positions->isEmpty()) {
            return $party;
        }

        return trim(mb_substr($party, 0, (int) $positions->min()));
    }
}
