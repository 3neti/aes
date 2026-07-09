<?php

namespace App\Election\Certification;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Preparation\DeterministicMapper;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class InitializationReportService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly DeterministicMapper $mapper,
        private readonly CanonicalJson $json,
        private readonly ElectionClock $clock,
        private readonly ActivityJournal $journal,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function write(): array
    {
        $runPath = $this->storage->activeRunPath();
        $runPathTail = basename($runPath);
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $package = $this->storage->readJson('packages/active-package.json');
        $registries = $this->storage->readJson('registries/sample.json');

        $precinct = (string) ($configuration['precinct_id'] ?? 'unknown');
        $checks = [
            $this->configurationFileCheck($configuration),
            $this->packageVerificationCheck($package),
            $this->mappingVerificationCheck($configuration, $package, $registries),
            $this->zeroStateCheck(),
            $this->deviceCertificationCheck(),
        ];

        $report = [
            'schema_version' => 'initialization-report-1',
            'report_profile' => 'fts-initialization-v1',
            'generated_at' => $this->clock->now()->toIso8601String(),
            'run_id' => $runPathTail,
            'precinct_id' => $precinct,
            'run_path' => $runPath,
            'checks' => $checks,
            'counts' => [
                'accepted_ballots' => count($this->storage->files('counting/accepted')),
                'rejected_ballots' => count($this->storage->files('counting/rejected')),
                'attestations' => count($this->storage->files('attestations')),
                'print_jobs' => count($this->storage->files('print-jobs')),
            ],
            'package_artifact' => [
                'path' => 'packages/active-package.json',
                'present' => $package !== [],
                'sha256' => $this->files->exists($this->storage->path('packages/active-package.json'))
                    ? (string) hash_file('sha256', $this->storage->path('packages/active-package.json'))
                    : null,
                'package_hash' => $package['package_hash'] ?? null,
                'registry_hash' => $package['registry_hash'] ?? null,
            ],
            'configuration_artifact' => [
                'path' => 'runtime/active-precinct.json',
                'present' => $configuration !== [],
                'mapping_hash' => $configuration['mapping_hash'] ?? null,
                'mapping_hash_length' => isset($configuration['mapping_hash'])
                    ? strlen((string) $configuration['mapping_hash'])
                    : 0,
            ],
        ];

        $report['passed'] = collect($checks)->every(fn (array $check): bool => $check['passed'] === true);
        $report['report_hash'] = $this->json->hash($report);
        $report['artifact_path'] = $this->storage->writeJson('diagnostics/initialization-report.json', $report);

        $this->journal->record('initialization_report.generated', [
            'run_id' => $runPathTail,
            'precinct_id' => $precinct,
            'passed' => $report['passed'],
            'report_hash' => $report['report_hash'],
        ]);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $path = $this->storage->path('diagnostics/initialization-report.json');

        if (! $this->files->exists($path)) {
            return [
                'exists' => false,
                'generate_url' => route('election.diagnostics.initialization-report.generate'),
                'download_url' => route('election.diagnostics.initialization-report.download'),
            ];
        }

        $report = $this->storage->readJson('diagnostics/initialization-report.json');

        return [
            'exists' => true,
            'artifact' => basename($path),
            'run_id' => $report['run_id'] ?? null,
            'precinct_id' => $report['precinct_id'] ?? null,
            'generated_at' => $report['generated_at'] ?? null,
            'passed' => $report['passed'] ?? false,
            'report_hash' => $report['report_hash'] ?? null,
            'checks' => $report['checks'] ?? [],
            'counts' => $report['counts'] ?? [],
            'package_artifact' => $report['package_artifact'] ?? [],
            'configuration_artifact' => $report['configuration_artifact'] ?? [],
            'download_url' => route('election.diagnostics.initialization-report.download'),
            'generate_url' => route('election.diagnostics.initialization-report.generate'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function configurationFileCheck(array $configuration): array
    {
        return [
            'name' => 'configuration_present',
            'passed' => $configuration !== [],
            'details' => [
                'configuration_present' => $configuration !== [],
                'mapping_hash_present' => isset($configuration['mapping_hash']),
            ],
            'message' => $configuration === []
                ? 'Active precinct configuration is missing.'
                : 'Active precinct configuration is present.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function packageVerificationCheck(array $package): array
    {
        $storedPackageHash = $package['package_hash'] ?? null;
        $computedPackageHash = null;

        if ($package !== []) {
            $packageWithoutHash = $package;
            unset($packageWithoutHash['package_hash']);
            $computedPackageHash = $this->json->hash($packageWithoutHash);
        }

        return [
            'name' => 'package_verification',
            'passed' => $package !== [] && $storedPackageHash !== null,
            'details' => [
                'stored_hash' => $storedPackageHash,
                'computed_hash' => $computedPackageHash,
                'package_present' => $package !== [],
                'hash_match' => $storedPackageHash !== null && $computedPackageHash !== null
                    ? hash_equals((string) $storedPackageHash, (string) $computedPackageHash)
                    : false,
            ],
            'message' => ! $package
                ? 'Active package is missing.'
                : ($storedPackageHash === null
                    ? 'Active package is missing package hash metadata.'
                    : ($this->packageHashMatches((string) $storedPackageHash, (string) $computedPackageHash ?? '')
                        ? 'Active package hash is valid.'
                        : 'Active package hash metadata is present but does not match the current package digest.')),
        ];
    }

    private function packageHashMatches(string $stored, string $computed): bool
    {
        if ($computed === '') {
            return false;
        }

        return hash_equals($stored, $computed);
    }

    /**
     * @return array<string, mixed>
     */
    private function mappingVerificationCheck(array $configuration, array $package, array $registries): array
    {
        if ($configuration === [] || $package === [] || $registries === []) {
            return [
                'name' => 'mapping_verification',
                'passed' => false,
                'details' => [
                    'derived_mapping_hash' => null,
                    'expected_mapping_hash' => $configuration['mapping_hash'] ?? null,
                    'configuration_present' => $configuration !== [],
                    'package_present' => $package !== [],
                    'registries_present' => $registries !== [],
                    'reason' => 'Missing configuration, package, or registry source file.',
                ],
                'message' => 'Mapping verification skipped because required source artifacts are missing.',
            ];
        }

        try {
            $derived = $this->mapper->derive($registries, $package);
            $derivedHash = $derived['mapping_hash'] ?? null;
            $expectedHash = $configuration['mapping_hash'] ?? null;

            return [
                'name' => 'mapping_verification',
                'passed' => $derivedHash !== null && $expectedHash !== null && hash_equals((string) $derivedHash, (string) $expectedHash),
                'details' => [
                    'derived_mapping_hash' => $derivedHash,
                    'expected_mapping_hash' => $expectedHash,
                    'mapping_match' => $derivedHash !== null && $expectedHash !== null && hash_equals((string) $derivedHash, (string) $expectedHash),
                ],
                'message' => ($derivedHash !== null && $expectedHash !== null && hash_equals((string) $derivedHash, (string) $expectedHash))
                    ? 'Derived mapping hash matches the active configuration.'
                    : 'Derived mapping hash does not match the active configuration.',
            ];
        } catch (Throwable $exception) {
            return [
                'name' => 'mapping_verification',
                'passed' => false,
                'details' => [
                    'derived_mapping_hash' => null,
                    'expected_mapping_hash' => $configuration['mapping_hash'] ?? null,
                    'error' => $exception->getMessage(),
                ],
                'message' => 'Mapping verification failed: '.$exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function zeroStateCheck(): array
    {
        $counts = [
            'accepted_ballots' => count($this->storage->files('counting/accepted')),
            'rejected_ballots' => count($this->storage->files('counting/rejected')),
            'print_jobs' => count($this->storage->files('print-jobs')),
            'spoiled_ballots' => count($this->storage->files('runtime/spoiled-ballots')), // may be zero when no dir exists
        ];

        $passed = $counts['accepted_ballots'] === 0 && $counts['rejected_ballots'] === 0;

        return [
            'name' => 'zero_state',
            'passed' => $passed,
            'details' => $counts,
            'message' => $passed
                ? 'No counted ballots have been recorded yet.'
                : 'There are existing count artifacts before election day initialization.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deviceCertificationCheck(): array
    {
        $deviceReport = $this->storage->readJson('certification/device-certification-report.json');
        $passed = (bool) ($deviceReport['passed'] ?? false);

        return [
            'name' => 'device_certification',
            'passed' => $passed,
            'details' => [
                'present' => $deviceReport !== [],
                'passed' => $passed,
            ],
            'message' => $passed
                ? 'Device certification report shows passing status.'
                : 'Device certification report is missing or not passing.',
        ];
    }
}
