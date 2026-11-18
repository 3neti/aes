<?php

namespace App\Election\Bundle;

use App\Election\Core\CanonicalJson;
use App\Election\Preparation\PopMappingProfiles;
use App\Election\Preparation\XlsxPopSourceAdapter;
use LBHurtado\ElectionCeremonies\Bundle\AuthoritySignature;
use LBHurtado\ElectionCeremonies\Bundle\ElectionBundleBuilder;
use LBHurtado\ElectionCeremonies\Bundle\ElectionBundleVerifier;
use LBHurtado\ElectionPh\Bundle\PhilippineSourceSnapshotBuilder;
use RuntimeException;

final readonly class ResearchElectionBundleService
{
    public function __construct(
        private NationwideSourceSnapshotValidator $validator,
        private XlsxPopSourceAdapter $popAdapter,
        private CanonicalJson $json,
        private PhilippineSourceSnapshotBuilder $registries,
        private ElectionBundleBuilder $bundles,
        private ElectionBundleVerifier $verifier,
        private AuthoritySignature $signatures,
    ) {}

    /** @return array<string, mixed> */
    public function build(string $destination, string $secretKey, ?string $reviewReportPath = null, ?string $decisionLedgerPath = null, ?string $inputBundlePath = null): array
    {
        if (file_exists($destination)) {
            throw new RuntimeException('Election bundle destination already exists.');
        }

        $catalogPath = resource_path('election/clc/2025-national-source-catalog.json');
        $manifestPath = storage_path('app/comelec-clc-2025/download-manifest.json');
        $popPath = resource_path('election/pop/2025NLE_POP.xlsx');
        $report = $this->validator->validate(
            $catalogPath,
            $manifestPath,
            storage_path('app/comelec-clc-2025/raw'),
            $popPath,
            NationwideSourceSnapshotValidator::Pop2025Sha256,
            NationwideSourceSnapshotValidator::Catalog2025Sha256,
        );

        if (! $report['valid']) {
            throw new RuntimeException('Source snapshot validation failed; inspect election:bundle:validate-sources.');
        }

        $profile = PopMappingProfiles::get();
        $pop = $this->popAdapter->extract($popPath, $profile->sourceLabel);
        $profile->validateHeaders($pop->headers);
        $downloads = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $temporary = sys_get_temp_dir().'/aes-research-bundle-'.bin2hex(random_bytes(8));
        mkdir($temporary, 0700);
        $sqlitePath = $temporary.'/election.sqlite';
        $reportPath = $temporary.'/source-snapshot.json';

        try {
            $precincts = (function () use ($pop, $profile): \Generator {
                foreach ($pop->rows as $index => $row) {
                    yield $profile->map($pop->headers, $row, $index + 2, $this->json);
                }
            })();
            $counts = $this->registries->build($precincts, $downloads['files'], $sqlitePath);

            if ($counts !== ['precinct_count' => 93629, 'source_count' => 1730, 'registered_voters' => 69773653]) {
                throw new RuntimeException('POP or CLC counts differ from the frozen 2025 source snapshot.');
            }

            $report['registry'] = $counts;
            file_put_contents($reportPath, $this->json->encode($report));
            $files = [
                'profiles/ceremonies.yaml' => base_path('vendor/3neti/election-ph/profiles/2025-research.yaml'),
                'profiles/truth-qr.yaml' => base_path('vendor/3neti/truth-qr/profiles/election.yaml'),
                'reports/source-snapshot.json' => $reportPath,
            ];
            $suffix = '-review-v1';

            if ($reviewReportPath !== null || $decisionLedgerPath !== null) {
                if ($reviewReportPath === null || $decisionLedgerPath === null
                    || ! is_file($reviewReportPath) || ! is_file($decisionLedgerPath)) {
                    throw new RuntimeException('Both research review evidence files are required.');
                }

                $review = json_decode((string) file_get_contents($reviewReportPath), true, flags: JSON_THROW_ON_ERROR);

                $sourceBundlePath = $inputBundlePath ?? (string) config('election.bundle_workbench.archive');

                if (! is_file($sourceBundlePath)
                    || ! is_array($review) || ($review['schema_version'] ?? null) !== 'research-review-1'
                    || ($review['pop_sha256'] ?? null) !== $report['pop_sha256']
                    || ($review['input_bundle_sha256'] ?? null) !== hash_file('sha256', $sourceBundlePath)
                    || ($review['national_coverage_ready'] ?? null) !== false
                    || ! hash_equals((string) ($review['decision_ledger_sha256'] ?? ''), (string) hash_file('sha256', $decisionLedgerPath))) {
                    throw new RuntimeException('Research review evidence does not match frozen sources.');
                }

                $files['reports/research-review.json'] = $reviewReportPath;
                $files['reports/review-decisions.jsonl'] = $decisionLedgerPath;
                $suffix = '-review-'.substr($review['decision_ledger_sha256'], 0, 12);
            }

            $manifest = $this->bundles->build($sqlitePath, $files, [
                'bundle_id' => '2025NLE-source-'.substr($report['source_catalog_sha256'], 0, 12).$suffix,
                'election_id' => '2025NLE',
                'coverage' => 'source_snapshot',
                'readiness' => 'research',
                'registry_schema' => 'ph-source-snapshot-1',
                'source_catalog_sha256' => $report['source_catalog_sha256'],
                'pop_sha256' => $report['pop_sha256'],
            ], $secretKey, $destination);
            $public = sodium_crypto_sign_publickey_from_secretkey($secretKey);
            $this->verifier->verify($destination, [$this->signatures->keyId($public) => $public]);

            return $manifest;
        } catch (\Throwable $exception) {
            @unlink($destination);

            throw $exception;
        } finally {
            @unlink($reportPath);
            @unlink($sqlitePath);
            rmdir($temporary);
        }
    }
}
