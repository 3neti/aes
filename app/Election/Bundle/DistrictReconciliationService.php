<?php

namespace App\Election\Bundle;

use RuntimeException;

final class DistrictReconciliationService
{
    /**
     * @param  iterable<array<string, mixed>>  $precincts
     * @return array<string, mixed>
     */
    public function reconcile(iterable $precincts, string $popHash, string $evidencePath, string $outputDirectory): array
    {
        $evidence = json_decode((string) file_get_contents($evidencePath), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($evidence) || ($evidence['schema_version'] ?? null) !== 'ph-2025-district-evidence-1'
            || ! is_array($evidence['district_rules'] ?? null) || ! is_array($evidence['city_rules'] ?? null)
            || ! is_array($evidence['precinct_evidence'] ?? null)
            || ! is_array($evidence['corrections'] ?? null)) {
            throw new RuntimeException('Unsupported district evidence file.');
        }

        $directory = rtrim($outputDirectory, '/');
        $crosswalkPath = $directory.'/district-crosswalk.csv';
        $reportPath = $directory.'/district-reconciliation.json';
        $ledgerPath = $directory.'/correction-ledger.json';

        if (! is_dir($directory) || is_file($crosswalkPath) || is_file($reportPath) || is_file($ledgerPath)) {
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

        $handle = fopen($crosswalkPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to write district crosswalk.');
        }

        try {
            fputcsv($handle, ['clustered_precinct', 'region', 'province', 'city_municipality', 'barangay', 'polling_place', 'precinct_cluster', 'registered_voters', 'source_row', 'legislative_district', 'status', 'evidence_id', 'source_url']);
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

                fputcsv($handle, [
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
            fflush($handle);
            $ledger = [
                'schema_version' => 'ph-2025-correction-ledger-1',
                'election_id' => '2025NLE',
                'items' => $evidence['corrections'],
            ];
            file_put_contents($ledgerPath, json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
            $report = [
                'schema_version' => 'ph-2025-district-reconciliation-1',
                'election_id' => '2025NLE',
                'readiness' => 'research',
                'pop_sha256' => $popHash,
                'evidence_sha256' => hash_file('sha256', $evidencePath),
                'crosswalk_sha256' => hash_file('sha256', $crosswalkPath),
                'correction_ledger_sha256' => hash_file('sha256', $ledgerPath),
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

            file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

            return $report;
        } catch (\Throwable $exception) {
            @unlink($crosswalkPath);
            @unlink($reportPath);
            @unlink($ledgerPath);

            throw $exception;
        } finally {
            fclose($handle);
        }
    }
}
