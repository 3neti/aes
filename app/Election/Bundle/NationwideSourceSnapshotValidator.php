<?php

namespace App\Election\Bundle;

use RuntimeException;
use Symfony\Component\Process\Process;

final class NationwideSourceSnapshotValidator
{
    public const Pop2025Sha256 = 'd2843072240c42c7df683d3ea6480895331224b6922d2ef1d6d386492af509f0';

    public const Catalog2025Sha256 = '775b5c9aabc0c116d9de974cf5795b96c5d84748796a6171cab4f27a3f91e0a2';

    /** @return array<string, mixed> */
    public function validate(string $catalogPath, string $manifestPath, string $rawRoot, string $popPath, string $expectedPopSha256, ?string $expectedCatalogSha256 = null): array
    {
        $catalog = $this->readJson($catalogPath);
        $manifest = $this->readJson($manifestPath);
        $sources = $catalog['sources'] ?? null;
        $files = $manifest['files'] ?? null;

        if (($catalog['schema_version'] ?? null) !== 'comelec-2025-clc-source-catalog-1'
            || ($manifest['schema_version'] ?? null) !== 'comelec-2025-clc-download-manifest-1'
            || ! is_array($sources) || ! is_array($files)) {
            throw new RuntimeException('Source catalog or download manifest has an unsupported shape.');
        }

        $catalogHash = hash_file('sha256', $catalogPath);
        $issues = [];

        if ($expectedCatalogSha256 !== null && ! hash_equals($expectedCatalogSha256, $catalogHash)) {
            $issues[] = ['code' => 'frozen_catalog_hash_mismatch'];
        }

        if (! hash_equals((string) ($manifest['source_catalog_sha256'] ?? ''), $catalogHash)) {
            $issues[] = ['code' => 'catalog_hash_mismatch'];
        }

        if (($catalog['pdf_count'] ?? null) !== count($sources)
            || ($manifest['source_count'] ?? null) !== count($sources)
            || ($manifest['downloaded_count'] ?? null) !== count($files)
            || ($manifest['failure_count'] ?? null) !== 0
            || ($manifest['failures'] ?? null) !== []) {
            $issues[] = ['code' => 'source_counts_mismatch'];
        }

        $catalogByUrl = [];
        $usedPaths = [];

        foreach ($sources as $source) {
            $url = $source['url'] ?? null;
            $region = $source['region'] ?? null;

            if (! is_string($url) || ! is_string($region) || ! preg_match('/^[A-Z0-9]+$/', $region)
                || ! str_starts_with($url, 'https://www.comelec.gov.ph/php-tpls-attachments/2025NLE/COC_2025NLE/CLC2025_')) {
                $issues[] = ['code' => 'invalid_catalog_source', 'url' => $url];

                continue;
            }

            $filename = basename((string) parse_url($url, PHP_URL_PATH));
            $relative = $region.'/'.$filename;

            if ($filename === '' || ! str_ends_with(strtolower($filename), '.pdf')
                || isset($catalogByUrl[$url]) || isset($usedPaths[$relative])) {
                $issues[] = ['code' => 'duplicate_or_invalid_catalog_entry', 'url' => $url];

                continue;
            }

            $catalogByUrl[$url] = ['region' => $region, 'relative' => $relative];
            $usedPaths[$relative] = true;
        }

        $downloadsByUrl = [];

        foreach ($files as $file) {
            $url = $file['url'] ?? null;

            if (! is_string($url) || isset($downloadsByUrl[$url])) {
                $issues[] = ['code' => 'duplicate_or_invalid_download', 'url' => $url];

                continue;
            }

            $downloadsByUrl[$url] = $file;

            if (! isset($catalogByUrl[$url])) {
                $issues[] = ['code' => 'uncatalogued_download', 'url' => $url];
            }
        }

        $verified = 0;

        foreach ($catalogByUrl as $url => $source) {
            $file = $downloadsByUrl[$url] ?? null;

            if (! is_array($file)) {
                $issues[] = ['code' => 'missing_download', 'url' => $url];

                continue;
            }

            $path = rtrim($rawRoot, '/').'/'.$source['relative'];

            if (($file['region'] ?? null) !== $source['region'] || ! is_file($path)
                || ($file['bytes'] ?? null) !== filesize($path) || ! is_string($file['sha256'] ?? null)
                || ! hash_equals($file['sha256'], hash_file('sha256', $path))) {
                $issues[] = ['code' => 'download_integrity_failed', 'url' => $url];

                continue;
            }

            $handle = fopen($path, 'rb');
            $header = $handle === false ? '' : fread($handle, 5);

            if ($handle !== false) {
                fclose($handle);
            }

            if ($header !== '%PDF-') {
                $issues[] = ['code' => 'invalid_pdf_header', 'url' => $url];

                continue;
            }

            $pdfInfo = new Process(['pdfinfo', $path]);
            $pdfInfo->run();

            if (! $pdfInfo->isSuccessful()) {
                $issues[] = ['code' => 'unreadable_pdf', 'url' => $url];

                continue;
            }

            $verified++;
        }

        $popHash = is_file($popPath) ? hash_file('sha256', $popPath) : null;

        if ($popHash === null || ! hash_equals($expectedPopSha256, $popHash)) {
            $issues[] = ['code' => 'pop_hash_mismatch'];
        }

        usort($issues, fn (array $left, array $right): int => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));

        return [
            'schema_version' => 'national-source-snapshot-report-1',
            'source_catalog_sha256' => $catalogHash,
            'pop_sha256' => $popHash,
            'expected_source_count' => count($sources),
            'verified_source_count' => $verified,
            'valid' => $issues === [],
            'issues' => $issues,
        ];
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Source file [{$path}] does not exist.");
        }

        $value = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($value)) {
            throw new RuntimeException("Source file [{$path}] must contain a JSON object.");
        }

        return $value;
    }
}
