<?php

namespace App\Election\Preparation;

use App\Election\Support\PdfTextExtractor;

final readonly class BallotPdfPartyReference
{
    public function __construct(private PdfTextExtractor $extractor) {}

    /**
     * @return array<string, string>
     */
    public function parties(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json') {
            $parties = json_decode((string) file_get_contents($path), true);

            if (! is_array($parties)) {
                return [];
            }

            return collect($parties)
                ->filter(fn (mixed $party, mixed $name): bool => is_string($name) && is_string($party) && trim($party) !== '')
                ->mapWithKeys(fn (string $party, string $name): array => [$this->key($name) => trim($party)])
                ->all();
        }

        $records = [];

        foreach ($this->extractor->extract($path) as $page) {
            $records = array_merge($records, $this->recordsFromText($page->text));
        }

        return collect($records)
            ->filter(fn (array $record): bool => ($record['party'] ?? '') !== '')
            ->mapWithKeys(fn (array $record): array => [$this->key((string) $record['name']) => (string) $record['party']])
            ->all();
    }

    public function key(string $name): string
    {
        $name = strtoupper($name);
        $name = str_replace(['Ñ', 'ñ'], 'N', $name);

        return trim((string) preg_replace('/[^A-Z0-9]+/', ' ', $name));
    }

    /**
     * @return array<int, array{name: string, party: string}>
     */
    private function recordsFromText(string $text): array
    {
        $records = [];
        $activeOffice = null;
        $active = [];

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            $office = $this->office($line);

            if ($office !== null) {
                $this->flushActive($records, $active, $activeOffice);
                $activeOffice = $office;

                continue;
            }

            if ($activeOffice === null) {
                continue;
            }

            $starts = $this->numberedStarts($line);

            if ($starts !== []) {
                foreach ($starts as $index => $start) {
                    $offset = $start['offset'];
                    $nextOffset = $starts[$index + 1]['offset'] ?? strlen($line);
                    $this->flushNearestColumn($records, $active, $offset, $activeOffice);
                    $active[$offset] = trim(substr($line, $offset, $nextOffset - $offset));
                }

                continue;
            }

            foreach ($this->continuations($line, array_keys($active)) as $offset => $continuation) {
                if ($continuation !== '') {
                    $active[$offset] = trim(($active[$offset] ?? '').' '.$continuation);
                }
            }
        }

        $this->flushActive($records, $active, $activeOffice);

        return $records;
    }

    private function office(string $line): ?string
    {
        $normalized = strtoupper(trim((string) preg_replace('/\s+/', ' ', $line)));

        if (! str_contains($normalized, 'VOTE FOR')) {
            return null;
        }

        foreach (['VICE PRESIDENT', 'PRESIDENT', 'SENATOR', 'MEMBER, HOUSE OF REPRESENTATIVES', 'MAYOR', 'VICE-MAYOR', 'MEMBER, SANGGUNIANG PANLUNGSOD', 'PARTY LIST'] as $office) {
            if (str_contains($normalized, $office)) {
                return $office;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{name: string, party: string}>  $records
     * @param  array<int, string>  $active
     */
    private function flushActive(array &$records, array &$active, ?string $office): void
    {
        foreach (array_keys($active) as $column) {
            $this->flushColumn($records, $active, $column, $office);
        }
    }

    /**
     * @param  array<int, array{name: string, party: string}>  $records
     * @param  array<int, string>  $active
     */
    private function flushColumn(array &$records, array &$active, int $column, ?string $office): void
    {
        if (! isset($active[$column]) || $office === null || $office === 'PARTY LIST') {
            unset($active[$column]);

            return;
        }

        $record = $this->record($active[$column]);

        if ($record !== null) {
            $records[] = $record;
        }

        unset($active[$column]);
    }

    /**
     * @param  array<int, array{name: string, party: string}>  $records
     * @param  array<int, string>  $active
     */
    private function flushNearestColumn(array &$records, array &$active, int $offset, ?string $office): void
    {
        $nearest = collect(array_keys($active))
            ->sortBy(fn (int $activeOffset): int => abs($activeOffset - $offset))
            ->first();

        if (! is_int($nearest) || abs($nearest - $offset) > 10) {
            return;
        }

        $this->flushColumn($records, $active, $nearest, $office);
    }

    /**
     * @return array{name: string, party: string}|null
     */
    private function record(string $text): ?array
    {
        if (preg_match('/^\d{1,3}\.?\s+(.+?)(?:\s*\(([A-Z0-9._ -]+)\))?\s*$/', $text, $matches) !== 1) {
            return null;
        }

        $name = trim((string) $matches[1]);
        $party = trim((string) ($matches[2] ?? ''));

        if ($name === '' || $party === '') {
            return null;
        }

        return [
            'name' => $name,
            'party' => $party,
        ];
    }

    /**
     * @return array<int, array{offset: int}>
     */
    private function numberedStarts(string $line): array
    {
        preg_match_all('/(?<!\S)\d{1,3}\.?\s+[A-Z0-9]/u', $line, $matches, PREG_OFFSET_CAPTURE);

        return collect($matches[0] ?? [])
            ->map(fn (array $match): array => ['offset' => (int) $match[1]])
            ->all();
    }

    /**
     * @param  array<int, int>  $activeOffsets
     * @return array<int, string>
     */
    private function continuations(string $line, array $activeOffsets): array
    {
        if ($activeOffsets === []) {
            return [];
        }

        sort($activeOffsets);
        $segments = [];

        foreach ($activeOffsets as $index => $offset) {
            $left = $index === 0 ? $offset : (int) floor(($activeOffsets[$index - 1] + $offset) / 2);
            $right = isset($activeOffsets[$index + 1])
                ? (int) floor(($offset + $activeOffsets[$index + 1]) / 2)
                : strlen($line);
            $segment = trim((string) preg_replace('/\s+/', ' ', substr($line, max(0, $left), max(0, $right - $left))));

            if ($segment !== '') {
                $segments[$offset] = $segment;
            }
        }

        return $segments;
    }
}
