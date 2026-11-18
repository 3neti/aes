<?php

namespace App\Election\Bundle;

use LBHurtado\ElectionCeremonies\Bundle\AuthoritySignature;
use LBHurtado\ElectionCeremonies\Bundle\ElectionBundleVerifier;
use RuntimeException;
use Throwable;

final readonly class ResearchBundleWorkbench
{
    public function __construct(
        private ElectionBundleVerifier $verifier,
        private AuthoritySignature $signatures,
    ) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $archivePath = (string) config('election.bundle_workbench.archive');
        $keyPath = (string) config('election.bundle_workbench.trusted_key');
        $directory = $this->directory();
        $reportPath = $directory.'/district-reconciliation.json';
        $crosswalkPath = $directory.'/district-crosswalk.csv';
        $ledgerPath = $directory.'/correction-ledger.json';

        if (! is_file($archivePath) || ! is_file($keyPath) || ! is_file($reportPath)
            || ! is_file($crosswalkPath) || ! is_file($ledgerPath)) {
            return ['available' => false, 'reason' => 'The signed research bundle or reconciliation outputs are missing.'];
        }

        $publicKey = file_get_contents($keyPath);

        if (! is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return ['available' => false, 'reason' => 'The configured bundle trust key is invalid.'];
        }

        try {
            $manifest = $this->verifier->verify($archivePath, [
                $this->signatures->keyId($publicKey) => $publicKey,
            ]);
            $report = $this->readJson($reportPath);
            $ledger = $this->readJson($ledgerPath);
            $evidencePath = resource_path('election/pop/2025-district-evidence.json');

            if (($manifest['coverage'] ?? null) !== 'source_snapshot'
                || ($manifest['readiness'] ?? null) !== 'research'
                || ($report['schema_version'] ?? null) !== 'ph-2025-district-reconciliation-1'
                || ($ledger['schema_version'] ?? null) !== 'ph-2025-correction-ledger-1'
                || ! hash_equals((string) ($manifest['pop_sha256'] ?? ''), (string) ($report['pop_sha256'] ?? ''))
                || ! hash_equals((string) ($report['crosswalk_sha256'] ?? ''), (string) hash_file('sha256', $crosswalkPath))
                || ! hash_equals((string) ($report['correction_ledger_sha256'] ?? ''), (string) hash_file('sha256', $ledgerPath))
                || ! is_file($evidencePath)
                || ! hash_equals((string) ($report['evidence_sha256'] ?? ''), (string) hash_file('sha256', $evidencePath))) {
                throw new RuntimeException('The research bundle and reconciliation outputs do not match.');
            }

            return [
                'available' => true,
                'bundle' => [
                    'id' => $manifest['bundle_id'],
                    'election_id' => $manifest['election_id'],
                    'coverage' => $manifest['coverage'],
                    'readiness' => $manifest['readiness'],
                    'archive_sha256' => $manifest['archive_sha256'],
                    'pop_sha256' => $manifest['pop_sha256'],
                ],
                'report' => [
                    'precinct_count' => $report['precinct_count'],
                    'source_supported_pending_review_count' => $report['source_supported_pending_review_count'],
                    'unresolved_precinct_count' => $report['unresolved_precinct_count'],
                    'unresolved_location_count' => $report['unresolved_location_count'],
                    'unreviewed_correction_count' => $report['unreviewed_correction_count'],
                    'national_coverage_ready' => $report['national_coverage_ready'],
                ],
                'corrections' => $ledger['items'],
            ];
        } catch (Throwable) {
            return ['available' => false, 'reason' => 'Bundle verification or reconciliation integrity failed.'];
        }
    }

    /**
     * @param  array<string, string|int>  $filters
     * @param  array<string, string>  $reviews
     * @return array<string, mixed>
     */
    public function browse(array $filters, array $reviews = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $groupLevel = $this->nextGroupLevel($filters);
        $groups = [];
        $total = 0;
        $precincts = [];
        $limit = 25;

        foreach ($this->rows() as $row) {
            if (! $this->matches($row, $filters)) {
                continue;
            }

            $row['review_decision'] = $reviews[$row['clustered_precinct']] ?? 'undecided';

            if (! empty($filters['review_decision']) && $row['review_decision'] !== $filters['review_decision']) {
                continue;
            }

            $total++;
            $group = $row[$groupLevel] ?? '';

            if ($groupLevel !== 'clustered_precinct') {
                $groups[$group] ??= ['name' => $group, 'precinct_count' => 0, 'supported_count' => 0, 'unresolved_count' => 0];
                $groups[$group]['precinct_count']++;

                if ($row['status'] === 'unresolved') {
                    $groups[$group]['unresolved_count']++;
                } else {
                    $groups[$group]['supported_count']++;
                }
            }

            if ($total > ($page - 1) * $limit && count($precincts) < $limit) {
                $precincts[] = $row;
            }
        }

        ksort($groups, SORT_NATURAL);

        return [
            'group_level' => $groupLevel,
            'groups' => array_values($groups),
            'precincts' => $precincts,
            'total' => $total,
            'page' => $page,
            'last_page' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /** @return array<string, string>|null */
    public function precinct(string $id): ?array
    {
        foreach ($this->rows() as $row) {
            if ($row['clustered_precinct'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** @return \Generator<array<string, string>> */
    private function rows(): \Generator
    {
        $handle = fopen($this->directory().'/district-crosswalk.csv', 'rb');

        if ($handle === false) {
            throw new RuntimeException('The district crosswalk cannot be opened.');
        }

        try {
            $headers = fgetcsv($handle);

            if (! is_array($headers) || ! in_array('clustered_precinct', $headers, true)
                || ! in_array('region', $headers, true) || ! in_array('status', $headers, true)) {
                throw new RuntimeException('The district crosswalk has an unsupported header.');
            }

            while (($values = fgetcsv($handle)) !== false) {
                if (count($values) !== count($headers)) {
                    throw new RuntimeException('The district crosswalk has a malformed row.');
                }

                yield array_combine($headers, $values);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string, string|int> $filters */
    private function nextGroupLevel(array $filters): string
    {
        if (empty($filters['region'])) {
            return 'region';
        }

        if ($filters['region'] !== 'NCR' && empty($filters['province'])) {
            return 'province';
        }

        if (empty($filters['city_municipality'])) {
            return 'city_municipality';
        }

        if (empty($filters['barangay'])) {
            return 'barangay';
        }

        return 'clustered_precinct';
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string|int>  $filters
     */
    private function matches(array $row, array $filters): bool
    {
        foreach (['region', 'province', 'city_municipality', 'barangay', 'status', 'legislative_district'] as $field) {
            if (! empty($filters[$field]) && $row[$field] !== $filters[$field]) {
                return false;
            }
        }

        $search = trim((string) ($filters['q'] ?? ''));

        if ($search === '') {
            return true;
        }

        foreach (['clustered_precinct', 'city_municipality', 'barangay', 'polling_place', 'evidence_id'] as $field) {
            if (mb_stripos($row[$field], $search) !== false) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $value = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($value)) {
            throw new RuntimeException('Research report is malformed.');
        }

        return $value;
    }

    private function directory(): string
    {
        return rtrim((string) config('election.bundle_workbench.reconciliation_directory'), '/');
    }
}
