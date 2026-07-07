<?php

namespace App\Election\Preparation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Support\PdfPageText;
use App\Election\Support\PdfTextExtractor;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class ClcCandidateImporter
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly Filesystem $files,
        private readonly PdfTextExtractor $extractor,
        private readonly CanonicalJson $json,
        private readonly ElectionClock $clock,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(?string $source = null): array
    {
        $source ??= (string) config('election.clc.source_path');
        $profile = (string) config('election.clc.profile');
        $registryVersion = self::registryVersion();

        try {
            $pdfs = $this->pdfs($source);
            $registryRoot = $this->storage->path("registries/{$registryVersion}");
            $this->files->ensureDirectoryExists($registryRoot);
            $this->files->ensureDirectoryExists($this->storage->path('imports/clc'));

            $candidatesPath = "{$registryRoot}/candidates.jsonl";
            $needsReviewPath = "{$registryRoot}/needs-review.jsonl";
            $candidateHandle = fopen($candidatesPath, 'wb');
            $needsReviewHandle = fopen($needsReviewPath, 'wb');

            if ($candidateHandle === false || $needsReviewHandle === false) {
                throw new RuntimeException('Unable to open CLC registry files for writing.');
            }

            $candidateHash = hash_init('sha256');
            $sourceRecords = [];
            $contests = [];
            $seenCandidates = [];
            $candidateCount = 0;
            $needsReviewCount = 0;

            foreach ($pdfs as $pdf) {
                $copiedPath = $this->storage->path('imports/clc/'.basename($pdf));
                $this->files->copy($pdf, $copiedPath);
                $sourceHash = hash_file('sha256', $pdf);
                $pages = $this->extractor->extract($pdf);
                $sourceRecords[] = [
                    'filename' => basename($pdf),
                    'original_path' => $pdf,
                    'copied_path' => $copiedPath,
                    'bytes' => filesize($pdf),
                    'sha256' => $sourceHash,
                    'pages' => count($pages),
                ];

                foreach ($this->parsePdf($pdf, $pages) as $item) {
                    if (($item['type'] ?? null) === 'candidate') {
                        $record = $item['record'];
                        $dedupeKey = implode('|', [
                            $record['source_file'],
                            $record['geography'],
                            $record['office'],
                            $record['district'] ?? '',
                            $record['ballot_number'],
                            $record['name_on_ballot'],
                        ]);

                        if (isset($seenCandidates[$dedupeKey])) {
                            continue;
                        }

                        $seenCandidates[$dedupeKey] = true;
                        $record['candidate_hash'] = $this->json->hash($record);
                        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
                        fwrite($candidateHandle, $line);
                        hash_update($candidateHash, $line);
                        $candidateCount++;
                        $contestKey = $this->contestKey($record);
                        $contests[$contestKey] ??= [
                            'scope' => $record['scope'],
                            'geography' => $record['geography'],
                            'district' => $record['district'],
                            'office' => $record['office'],
                            'candidate_count' => 0,
                        ];
                        $contests[$contestKey]['candidate_count']++;
                    } else {
                        $line = json_encode($item['record'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
                        fwrite($needsReviewHandle, $line);
                        $needsReviewCount++;
                    }
                }
            }

            fclose($candidateHandle);
            fclose($needsReviewHandle);
            ksort($contests);

            $this->files->put("{$registryRoot}/contests.json", $this->json->encode([
                'schema_version' => 'clc-contests-1',
                'contests' => array_values($contests),
            ]));
            $this->files->put("{$registryRoot}/contest-index.json", $this->json->encode($contests));
            $this->files->put("{$registryRoot}/source-files.json", $this->json->encode([
                'schema_version' => 'clc-source-files-1',
                'sources' => $sourceRecords,
            ]));

            $manifest = [
                'schema_version' => 'clc-registry-manifest-1',
                'imported_at' => $this->clock->now()->toIso8601String(),
                'profile' => $profile,
                'registry_version' => $registryVersion,
                'source_path' => $source,
                'source_count' => count($pdfs),
                'candidate_count' => $candidateCount,
                'contest_count' => count($contests),
                'needs_review_count' => $needsReviewCount,
                'registry_hash' => hash_final($candidateHash),
                'candidates_path' => $candidatesPath,
                'contests_path' => "{$registryRoot}/contests.json",
                'contest_index_path' => "{$registryRoot}/contest-index.json",
                'source_files_path' => "{$registryRoot}/source-files.json",
                'needs_review_path' => $needsReviewPath,
            ];
            $manifest['manifest_hash'] = $this->json->hash($manifest);
            $manifest['artifact_path'] = $this->storage->writeJson("registries/{$registryVersion}/manifest.json", $manifest);

            $this->journal->record('clc.imported', [
                'registry_version' => $registryVersion,
                'candidate_count' => $candidateCount,
                'needs_review_count' => $needsReviewCount,
                'registry_hash' => $manifest['registry_hash'],
            ]);

            return $manifest;
        } catch (\Throwable $exception) {
            $this->journal->record('clc.import_failed', [
                'source_path' => $source,
                'reason' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public static function registryVersion(): string
    {
        return (string) config('election.clc.registry_version', 'clc-2025-nle');
    }

    /**
     * @return array<int, string>
     */
    private function pdfs(string $source): array
    {
        if ($this->files->isFile($source)) {
            return [realpath($source) ?: $source];
        }

        if (! $this->files->isDirectory($source)) {
            throw new RuntimeException("CLC source path [{$source}] was not found.");
        }

        return collect($this->files->files($source))
            ->map(fn ($file): string => $file->getPathname())
            ->filter(fn (string $path): bool => strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, PdfPageText>  $pages
     * @return array<int, array{type: string, record: array<string, mixed>}>
     */
    private function parsePdf(string $pdf, array $pages): array
    {
        $items = [];
        $geography = null;
        $office = null;
        $mode = null;
        $active = null;

        foreach ($pages as $page) {
            foreach (explode("\n", $page->text) as $line) {
                $raw = rtrim($line);
                $trimmed = trim((string) preg_replace('/\s+/u', ' ', $raw));

                if ($trimmed === '' || $this->isBoilerplate($trimmed)) {
                    continue;
                }

                if ($trimmed === 'PHILIPPINES' && $geography !== null) {
                    continue;
                }

                if ($this->isGeography($trimmed)) {
                    $this->flush($items, $active);
                    $geography = $trimmed;
                    $office = null;
                    $mode = null;

                    continue;
                }

                if ($this->isOffice($trimmed)) {
                    $this->flush($items, $active);
                    $office = $trimmed;
                    $mode = null;

                    continue;
                }

                if (str_contains($trimmed, 'NAME TO APPEAR ON THE BALLOT')) {
                    $this->flush($items, $active);
                    $mode = 'standard';

                    continue;
                }

                if (str_contains($trimmed, 'NAME ON BALLOT')) {
                    $this->flush($items, $active);
                    $mode = 'party-list';
                    $office = 'PARTY LIST';

                    continue;
                }

                $row = $mode === 'party-list'
                    ? $this->partyListRow($raw, $pdf, $page->page, $geography, $office)
                    : $this->standardRow($raw, $pdf, $page->page, $geography, $office);

                if ($row !== null) {
                    $this->flush($items, $active);
                    $active = $row;

                    continue;
                }

                if ($active !== null && ! preg_match('/^\d+\s+/', $trimmed)) {
                    $this->appendContinuation($active, $trimmed);

                    continue;
                }

                if ($mode !== null && preg_match('/^\d+\s+/', $trimmed)) {
                    $items[] = [
                        'type' => 'needs-review',
                        'record' => [
                            'schema_version' => 'clc-needs-review-1',
                            'source_file' => basename($pdf),
                            'source_page' => $page->page,
                            'geography' => $geography,
                            'office' => $office,
                            'line' => $trimmed,
                            'reason' => 'Unable to parse candidate row.',
                        ],
                    ];
                }
            }
        }

        $this->flush($items, $active);

        return $items;
    }

    private function standardRow(string $line, string $pdf, int $page, ?string $geography, ?string $office): ?array
    {
        if (! preg_match('/^\s*(\d+)\s+(.+?)\s{2,}(MALE|FEMALE)\s+(.+?)(?:\s{2,}(.+))?$/u', $line, $matches)) {
            return null;
        }

        return $this->candidate($pdf, $page, $geography, $office, (int) $matches[1], $matches[2], $matches[3], $matches[4], $matches[5] ?? '');
    }

    private function partyListRow(string $line, string $pdf, int $page, ?string $geography, ?string $office): ?array
    {
        if (! preg_match('/^\s*(\d+)\s+(.+?)\s{2,}(.+)$/u', $line, $matches)) {
            return null;
        }

        return $this->candidate($pdf, $page, $geography, $office, (int) $matches[1], $matches[2], null, $matches[3], '');
    }

    private function candidate(string $pdf, int $page, ?string $geography, ?string $office, int $number, string $nameOnBallot, ?string $sex, string $fullName, string $party): array
    {
        $geography ??= 'UNKNOWN';
        $office ??= 'UNKNOWN';
        $district = $this->district($geography);
        $scope = str_starts_with($geography, 'PHILIPPINES') ? 'national' : ($district === null ? 'municipal' : 'district');
        $nameOnBallot = trim($nameOnBallot);

        return [
            'type' => 'candidate',
            'record' => [
                'schema_version' => 'clc-candidate-1',
                'election_id' => '2025NLE-CLC',
                'source_file' => basename($pdf),
                'source_page' => $page,
                'scope' => $scope,
                'geography' => $geography,
                'office' => $office,
                'district' => $district,
                'ballot_number' => $number,
                'name_on_ballot' => $nameOnBallot,
                'sex' => $sex,
                'full_name' => trim($fullName),
                'political_party' => trim($party),
                'candidate_image' => [
                    'status' => 'placeholder',
                    'type' => null,
                    'uri' => null,
                    'source' => null,
                    'sha256' => null,
                    'alt_text' => "Candidate photo placeholder for {$nameOnBallot}",
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array{type: string, record: array<string, mixed>}>  $items
     * @param  array{type: string, record: array<string, mixed>}|null  $active
     */
    private function flush(array &$items, ?array &$active): void
    {
        if ($active !== null) {
            $items[] = $active;
            $active = null;
        }
    }

    /**
     * @param  array{type: string, record: array<string, mixed>}  $active
     */
    private function appendContinuation(array &$active, string $line): void
    {
        $record = &$active['record'];

        if (($record['political_party'] ?? '') !== '') {
            $record['political_party'] = trim($record['political_party'].' '.$line);

            return;
        }

        if (($record['sex'] ?? null) === null) {
            $record['full_name'] = trim($record['full_name'].' '.$line);

            return;
        }

        $record['name_on_ballot'] = trim($record['name_on_ballot'].' '.$line);
        $record['candidate_image']['alt_text'] = 'Candidate photo placeholder for '.$record['name_on_ballot'];
    }

    private function isBoilerplate(string $line): bool
    {
        return str_starts_with($line, 'Republic of the Philippines')
            || str_starts_with($line, 'COMMISSION ON ELECTIONS')
            || str_starts_with($line, 'Intramuros')
            || str_starts_with($line, 'MAY 12')
            || str_starts_with($line, 'Certified List')
            || str_starts_with($line, '(')
            || str_starts_with($line, 'NOTICE:')
            || str_starts_with($line, 'Report generated')
            || preg_match('/^[a-f0-9]{32}$/i', $line) === 1;
    }

    private function isGeography(string $line): bool
    {
        if (preg_match('/^\d+\b/', $line) === 1) {
            return false;
        }

        return $line === 'PHILIPPINES' || str_contains($line, ' - CITY') || str_contains($line, ' - MUNICIPALITY') || str_contains($line, ' - ');
    }

    private function isOffice(string $line): bool
    {
        return in_array($line, ['SENATOR', 'PARTY LIST', 'MAYOR', 'VICE-MAYOR', 'COUNCILOR', 'MEMBER, HOUSE OF REPRESENTATIVES'], true);
    }

    private function district(string $geography): ?string
    {
        if (preg_match('/-\s*([A-Z]+)\s+(?:LEG)?DIST$/', $geography, $matches)) {
            return $matches[1].' DIST';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function contestKey(array $record): string
    {
        return implode('|', [
            $record['scope'],
            $record['geography'],
            $record['office'],
            $record['district'] ?? '',
        ]);
    }
}
