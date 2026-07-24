<?php

namespace App\Election\Certification;

use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\ElectionRunType;
use App\Election\Preparation\DeterministicMapper;
use App\Election\Preparation\PopWorkbookImporter;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;

final class PackageIntegrityService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly Filesystem $files,
        private readonly CanonicalJson $json,
        private readonly DeterministicMapper $mapper,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function verify(): array
    {
        $activation = $this->storage->readJson('packages/configured-precinct-activation.json');
        $package = $this->storage->readJson('packages/active-package.json');
        $registries = $this->storage->readJson('registries/sample.json');
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $run = $this->storage->currentRun();

        if (($package['schema_version'] ?? null) === 'sample-package-1') {
            return $this->verifyAutomatedSample($package, $registries, $configuration, $run);
        }

        $popManifest = $this->storage->readJson('registries/'.PopWorkbookImporter::RegistryVersion.'/manifest.json');
        $clcManifest = $this->storage->readJson('registries/'.config('election.clc.registry_version').'/manifest.json');
        try {
            $derived = $package !== [] && $registries !== []
                ? $this->mapper->derive($registries, $package)
                : [];
        } catch (\Throwable) {
            $derived = [];
        }

        $checks = [
            $this->check('activation_report_present', $activation !== [], 'Configured precinct activation report is present.'),
            $this->check('active_package_present', $package !== [], 'Active precinct package is present.'),
            $this->check('active_registry_present', $registries !== [], 'Active ballot registry is present.'),
            $this->check(
                'configured_precinct_matches',
                ($configuration['precinct_id'] ?? null) === config('election.pop.clustered_precinct'),
                'Active precinct matches the appliance configuration.',
            ),
            $this->hashCheck(
                'pop_source_hash',
                (string) config('election.pop.source_path'),
                $activation['pop']['source_hash'] ?? null,
                'Configured POP workbook matches its activation hash.',
            ),
            $this->hashCheck(
                'pop_registry_hash',
                (string) ($popManifest['precincts_path'] ?? ''),
                $popManifest['registry_hash'] ?? null,
                'POP registry matches its manifest hash.',
            ),
            $this->hashCheck(
                'clc_registry_hash',
                (string) ($clcManifest['candidates_path'] ?? ''),
                $clcManifest['registry_hash'] ?? null,
                'CLC candidate registry matches its manifest hash.',
            ),
            $this->check(
                'ballot_registry_hash',
                $this->json->hash($registries) === ($package['registry_hash'] ?? null),
                'Active ballot registry matches the package registry hash.',
            ),
            $this->check(
                'package_hash',
                $this->packageHash($package) === ($package['package_hash'] ?? null),
                'Active package matches its package hash.',
            ),
            $this->check(
                'mapping_hash',
                ($derived['mapping_hash'] ?? null) === ($configuration['mapping_hash'] ?? null),
                'Precinct mapping is reproducible from the active package and registry.',
            ),
            $this->check(
                'activation_hash',
                $this->activationHash($activation) === ($activation['activation_hash'] ?? null),
                'Activation evidence matches its recorded hash.',
            ),
            $this->check(
                'relevant_clc_review_items',
                $this->relevantReviewCount($clcManifest, $configuration) === 0,
                'No unresolved CLC extraction item affects an active ballot contest.',
            ),
        ];

        $report = [
            'schema_version' => 'package-integrity-report-1',
            'run_id' => $run['run_id'] ?? null,
            'run_type' => $run['run_type'] ?? null,
            'precinct_id' => $configuration['precinct_id'] ?? null,
            'checks' => $checks,
            'checks_passed' => collect($checks)->where('passed', true)->count(),
            'checks_total' => count($checks),
            'global_clc_review_count' => (int) ($clcManifest['needs_review_count'] ?? 0),
            'relevant_clc_review_count' => $this->relevantReviewCount($clcManifest, $configuration),
        ];
        $report['passed'] = $report['checks_passed'] === $report['checks_total'];
        $report['report_hash'] = $this->json->hash($report);
        $report['artifact_path'] = $this->storage->path('certification/package-integrity-report.json');
        $this->storage->writeJson('certification/package-integrity-report.json', $report);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  array<string, mixed>  $registries
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function verifyAutomatedSample(array $package, array $registries, array $configuration, array $run): array
    {
        $allowed = ($run['run_type'] ?? null) === ElectionRunType::AutomatedTest->value;
        $checks = [
            $this->check('automated_sample_only', $allowed, 'Sample packages are restricted to automated-test runs.'),
            $this->check('sample_registry_hash', $this->json->hash($registries) === ($package['registry_hash'] ?? null), 'Sample registry hash matches.'),
            $this->check('sample_mapping_hash', $this->mapper->derive($registries, $package)['mapping_hash'] === ($configuration['mapping_hash'] ?? null), 'Sample mapping is reproducible.'),
        ];
        $report = [
            'schema_version' => 'package-integrity-report-1',
            'run_id' => $run['run_id'] ?? null,
            'run_type' => $run['run_type'] ?? null,
            'precinct_id' => $configuration['precinct_id'] ?? null,
            'checks' => $checks,
            'checks_passed' => collect($checks)->where('passed', true)->count(),
            'checks_total' => count($checks),
            'global_clc_review_count' => 0,
            'relevant_clc_review_count' => 0,
        ];
        $report['passed'] = $report['checks_passed'] === $report['checks_total'];
        $report['report_hash'] = $this->json->hash($report);
        $report['artifact_path'] = $this->storage->path('certification/package-integrity-report.json');
        $this->storage->writeJson('certification/package-integrity-report.json', $report);

        return $report;
    }

    /**
     * @return array{name: string, passed: bool, message: string}
     */
    private function check(string $name, bool $passed, string $message): array
    {
        return ['name' => $name, 'passed' => $passed, 'message' => $message];
    }

    /**
     * @return array{name: string, passed: bool, message: string}
     */
    private function hashCheck(string $name, string $path, mixed $expected, string $message): array
    {
        $actual = $path !== '' && $this->files->isFile($path) ? hash_file('sha256', $path) : null;

        return $this->check($name, is_string($expected) && hash_equals($expected, (string) $actual), $message);
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function packageHash(array $package): string
    {
        unset($package['package_hash'], $package['registry_hash']);

        return $this->json->hash($package);
    }

    /**
     * @param  array<string, mixed>  $activation
     */
    private function activationHash(array $activation): string
    {
        unset($activation['activation_hash'], $activation['artifact_path']);

        return $this->json->hash($activation);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $configuration
     */
    private function relevantReviewCount(array $manifest, array $configuration): int
    {
        $path = (string) ($manifest['needs_review_path'] ?? '');

        if (! $this->files->isFile($path)) {
            return 0;
        }

        $geographies = collect($configuration['contests'] ?? [])
            ->pluck('geography')
            ->filter()
            ->map(fn (string $geography): string => strtoupper($geography))
            ->unique();

        return collect(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
            ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR))
            ->filter(fn (array $item): bool => $geographies->contains(strtoupper((string) ($item['geography'] ?? ''))))
            ->count();
    }
}
