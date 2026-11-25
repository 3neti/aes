<?php

namespace App\Election\Bundle;

use App\Election\Support\ElectionRunScopeLock;
use RuntimeException;
use Throwable;

final class DistrictReconciliationService
{
    public function __construct(private readonly ElectionRunScopeLock $runLock) {}

    /**
     * @param  iterable<array<string, mixed>>  $precincts
     * @return array<string, mixed>
     */
    public function reconcile(iterable $precincts, string $popHash, string $evidencePath, string $outputDirectory): array
    {
        $operation = fn (): array => $this->reconcileWithinLock($precincts, $popHash, $evidencePath, $outputDirectory);

        return config('election.voting_lock_driver') === 'local'
            ? $this->runLock->executeCommand($operation)
            : $operation();
    }

    /** @param iterable<array<string, mixed>> $precincts @return array<string, mixed> */
    private function reconcileWithinLock(iterable $precincts, string $popHash, string $evidencePath, string $outputDirectory): array
    {
        $evidence = json_decode((string) file_get_contents($evidencePath), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($evidence) || ($evidence['schema_version'] ?? null) !== 'ph-2025-district-evidence-1'
            || ! is_array($evidence['district_rules'] ?? null) || ! is_array($evidence['city_rules'] ?? null)
            || ! is_array($evidence['precinct_evidence'] ?? null)
            || ! is_array($evidence['corrections'] ?? null)) {
            throw new RuntimeException('Unsupported district evidence file.');
        }

        $directory = realpath($outputDirectory);
        if ($directory === false || ! is_dir($directory)) {
            throw new RuntimeException('The output directory must exist and must not contain reconciliation outputs.');
        }
        $protectedRoot = $this->canonicalProtectedRoot();
        if ($directory === $protectedRoot || str_starts_with($directory, $protectedRoot.'/')) {
            throw new RuntimeException('Reconciliation output cannot be written inside election storage.');
        }
        $crosswalkPath = $directory.'/district-crosswalk.csv';
        $reportPath = $directory.'/district-reconciliation.json';
        $ledgerPath = $directory.'/correction-ledger.json';

        if (file_exists($crosswalkPath) || is_link($crosswalkPath) || file_exists($reportPath) || is_link($reportPath) || file_exists($ledgerPath) || is_link($ledgerPath)) {
            throw new RuntimeException('The output directory must exist and must not contain reconciliation outputs.');
        }

        $rules = [];

        foreach ($evidence['district_rules'] as $rule) {
            foreach ($rule['barangays'] as $barangay) {
                $key = implode('|', [$rule['province'], $rule['city_municipality'], $barangay]);

                if (isset($rules[$key])) {
                    throw new RuntimeException("Conflicting district rules for [{$key}].");
                }

                $rules[$key] = $rule;
            }
        }

        $cityRules = [];

        foreach ($evidence['city_rules'] as $rule) {
            $key = implode('|', [$rule['province'], $rule['city_municipality']]);

            if (isset($cityRules[$key])) {
                throw new RuntimeException("Conflicting city district rules for [{$key}].");
            }

            $cityRules[$key] = $rule;
        }

        $direct = [];

        foreach ($evidence['precinct_evidence'] as $item) {
            $id = (string) $item['clustered_precinct'];

            if (isset($direct[$id])) {
                throw new RuntimeException("Conflicting direct precinct evidence for [{$id}].");
            }

            $direct[$id] = $item;
        }

        $handles = [];
        try {
            foreach ([$crosswalkPath, $ledgerPath, $reportPath] as $path) {
                $handle = @fopen($path, 'x+b');
                if ($handle === false) {
                    throw new RuntimeException('Unable to create reconciliation output without replacing existing files.');
                }
                $handles[$path] = $handle;
            }
            $handle = $handles[$crosswalkPath];
            $this->writeCsv($handle, ['clustered_precinct', 'region', 'province', 'city_municipality', 'barangay', 'polling_place', 'precinct_cluster', 'registered_voters', 'source_row', 'legislative_district', 'status', 'evidence_id', 'source_url']);
            $seen = [];
            $resolved = 0;
            $unresolved = 0;
            $unresolvedLocations = [];
            $evidenceCounts = [];

            foreach ($precincts as $precinct) {
                $id = (string) $precinct['clustered_precinct'];

                if (isset($seen[$id])) {
                    throw new RuntimeException("Duplicate POP clustered precinct [{$id}].");
                }

                $seen[$id] = true;
                $key = implode('|', [$precinct['province'], $precinct['city_municipality'], $precinct['barangay']]);
                $cityKey = implode('|', [$precinct['province'], $precinct['city_municipality']]);
                $barangayRule = $rules[$key] ?? null;
                $cityRule = $cityRules[$cityKey] ?? null;

                if ($barangayRule !== null && $cityRule !== null
                    && $barangayRule['legislative_district'] !== $cityRule['legislative_district']) {
                    throw new RuntimeException("District rules conflict for POP precinct [{$id}].");
                }

                $rule = $barangayRule ?? $cityRule;
                $item = $direct[$id] ?? null;

                if ($item !== null && ($item['province'] !== $precinct['province']
                    || $item['city_municipality'] !== $precinct['city_municipality']
                    || $item['barangay'] !== $precinct['barangay'])) {
                    throw new RuntimeException("Direct evidence location does not match POP precinct [{$id}].");
                }

                if ($item !== null && $rule !== null && $item['legislative_district'] !== $rule['legislative_district']) {
                    throw new RuntimeException("District evidence conflicts for POP precinct [{$id}].");
                }

                $match = $item ?? $rule;
                $district = $match['legislative_district'] ?? '';
                $status = $match === null ? 'unresolved' : 'source_supported_pending_review';
                $evidenceId = $match['id'] ?? '';
                $sourceUrl = $match['source_url'] ?? '';

                $this->writeCsv($handle, [
                    $id,
                    $precinct['region'] ?? '',
                    $precinct['province'],
                    $precinct['city_municipality'],
                    $precinct['barangay'],
                    $precinct['polling_place'] ?? '',
                    $precinct['precinct_cluster'] ?? '',
                    $precinct['cluster_total'] ?? '',
                    $precinct['source_row'] ?? '',
                    $district,
                    $status,
                    $evidenceId,
                    $sourceUrl,
                ]);

                if ($match === null) {
                    $unresolved++;
                    $unresolvedLocations[$key] = ($unresolvedLocations[$key] ?? 0) + 1;
                } else {
                    $resolved++;
                    $evidenceCounts[$evidenceId] = ($evidenceCounts[$evidenceId] ?? 0) + 1;
                }
            }

            foreach ($direct as $id => $item) {
                if (! isset($seen[$id])) {
                    throw new RuntimeException("Direct evidence precinct [{$id}] is absent from the POP snapshot.");
                }
            }

            ksort($unresolvedLocations);
            ksort($evidenceCounts);
            if (! fflush($handle)) {
                throw new RuntimeException('Unable to flush district crosswalk.');
            }
            $ledger = [
                'schema_version' => 'ph-2025-correction-ledger-1',
                'election_id' => '2025NLE',
                'items' => $evidence['corrections'],
            ];
            $this->writeJson($handles[$ledgerPath], $ledger);
            $crosswalkHash = hash_file('sha256', $crosswalkPath);
            $ledgerHash = hash_file('sha256', $ledgerPath);
            $evidenceHash = hash_file('sha256', $evidencePath);
            if (! is_string($crosswalkHash) || ! is_string($ledgerHash) || ! is_string($evidenceHash)) {
                throw new RuntimeException('Unable to hash district reconciliation evidence.');
            }
            $report = [
                'schema_version' => 'ph-2025-district-reconciliation-1',
                'election_id' => '2025NLE',
                'readiness' => 'research',
                'pop_sha256' => $popHash,
                'evidence_sha256' => $evidenceHash,
                'crosswalk_sha256' => $crosswalkHash,
                'correction_ledger_sha256' => $ledgerHash,
                'precinct_count' => $resolved + $unresolved,
                'source_supported_pending_review_count' => $resolved,
                'unresolved_precinct_count' => $unresolved,
                'unresolved_location_count' => count($unresolvedLocations),
                'unresolved_locations' => $unresolvedLocations,
                'evidence_counts' => $evidenceCounts,
                'correction_count' => count($evidence['corrections']),
                'unreviewed_correction_count' => count(array_filter($evidence['corrections'], fn (array $correction): bool => $correction['review_state'] !== 'reviewed_applied')),
                'national_coverage_ready' => false,
            ];

            $this->writeJson($handles[$reportPath], $report);

            return $report;
        } catch (Throwable $exception) {
            foreach ($handles as $path => $ownedHandle) {
                $owned = fstat($ownedHandle);
                $current = @lstat($path);
                if (is_array($owned) && is_array($current) && $owned['dev'] === $current['dev'] && $owned['ino'] === $current['ino']) {
                    @unlink($path);
                }
            }
            throw $exception;
        } finally {
            foreach ($handles as $ownedHandle) {
                fclose($ownedHandle);
            }
        }
    }

    private function canonicalProtectedRoot(): string
    {
        $path = $this->runLock->baseRoot();
        $suffix = [];
        while (($resolved = realpath($path)) === false) {
            $parent = dirname($path);
            if ($parent === $path || is_link($path)) {
                throw new RuntimeException('Unable to resolve election storage for reconciliation.');
            }
            array_unshift($suffix, basename($path));
            $path = $parent;
        }

        return rtrim($resolved.'/'.implode('/', $suffix), '/');
    }

    /** @param resource $handle @param array<int, mixed> $row */
    private function writeCsv($handle, array $row): void
    {
        $buffer = fopen('php://temp', 'w+b');
        if ($buffer === false) {
            throw new RuntimeException('Unable to write district crosswalk.');
        }
        try {
            if (fputcsv($buffer, $row) === false || ! rewind($buffer)) {
                throw new RuntimeException('Unable to write district crosswalk.');
            }
            $contents = stream_get_contents($buffer);
            if (! is_string($contents) || fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('Unable to write district crosswalk completely.');
            }
        } finally {
            fclose($buffer);
        }
    }

    /** @param resource $handle @param array<string, mixed> $data */
    private function writeJson($handle, array $data): void
    {
        $contents = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
            throw new RuntimeException('Unable to write district reconciliation output completely.');
        }
    }
}
