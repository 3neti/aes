<?php

namespace App\Election\Preparation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;

final class SupplyVerificationBaselineService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
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
        $precinct = (string) ($configuration['precinct_id'] ?? 'unknown');

        $checkedAt = $this->clock->now()->toIso8601String();
        $supplies = $this->collectSupplies($runPathTail);

        $requiredSupplies = collect($supplies)->filter(fn (array $supply): bool => (bool) $supply['required']);
        $missingRequired = $requiredSupplies->filter(fn (array $supply): bool => ! $supply['found']);
        $optionalSupplies = collect($supplies)->reject(fn (array $supply): bool => (bool) $supply['required']);

        $report = [
            'schema_version' => 'supply-verification-baseline-1',
            'baseline_profile' => 'supply-verification-v1',
            'generated_at' => $checkedAt,
            'run_id' => $runPathTail,
            'precinct_id' => $precinct,
            'run_path' => $runPath,
            'total_supply_count' => count($supplies),
            'required_supply_count' => $requiredSupplies->count(),
            'required_supplies_present' => $requiredSupplies->count() - $missingRequired->count(),
            'required_supply_missing_count' => $missingRequired->count(),
            'optional_supply_count' => $optionalSupplies->count(),
            'passed' => $missingRequired->count() === 0,
            'supplies' => collect($supplies)->values()->all(),
        ];

        $report['baseline_hash'] = $this->json->hash($report);
        $artifactPath = $this->storage->writeJson('runtime/supply-verification-baseline.json', $report);
        $report['artifact_path'] = $artifactPath;

        $this->journal->record('supply_verification_baseline.generated', [
            'run_id' => $runPathTail,
            'precinct_id' => $precinct,
            'required_supply_count' => $report['required_supply_count'],
            'required_supply_present' => $report['required_supplies_present'],
            'required_supply_missing_count' => $report['required_supply_missing_count'],
            'supplies_present' => count(collect($supplies)->filter(fn (array $supply): bool => $supply['found'])->all()),
            'baseline_hash' => $report['baseline_hash'],
        ]);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $path = $this->storage->path('runtime/supply-verification-baseline.json');

        if (! $this->files->exists($path)) {
            return [
                'exists' => false,
                'generate_url' => route('election.provision.supply-verification-baseline'),
            ];
        }

        $baseline = $this->storage->readJson('runtime/supply-verification-baseline.json');

        return [
            'exists' => true,
            'artifact' => basename($path),
            'artifact_path' => $path,
            'run_id' => $baseline['run_id'] ?? null,
            'precinct_id' => $baseline['precinct_id'] ?? null,
            'baseline_hash' => $baseline['baseline_hash'] ?? null,
            'required_supply_count' => $baseline['required_supply_count'] ?? 0,
            'required_supplies_present' => $baseline['required_supplies_present'] ?? 0,
            'required_supply_missing_count' => $baseline['required_supply_missing_count'] ?? 0,
            'optional_supply_count' => $baseline['optional_supply_count'] ?? 0,
            'total_supply_count' => $baseline['total_supply_count'] ?? 0,
            'passed' => $baseline['passed'] ?? false,
            'generated_at' => $baseline['generated_at'] ?? null,
            'supplies' => $baseline['supplies'] ?? [],
            'generate_url' => route('election.provision.supply-verification-baseline'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectSupplies(string $runId): array
    {
        return collect([
            [
                'supply_code' => 'active_precinct',
                'label' => 'Active Precinct Configuration',
                'relative_path' => 'runtime/active-precinct.json',
                'required' => true,
            ],
            [
                'supply_code' => 'active_package',
                'label' => 'Active Package Definition',
                'relative_path' => 'packages/active-package.json',
                'required' => true,
            ],
            [
                'supply_code' => 'device_certification_report',
                'label' => 'Device Certification Report',
                'relative_path' => 'certification/device-certification-report.json',
                'required' => true,
            ],
            [
                'supply_code' => 'precinct_bundle',
                'label' => 'Precinct Evidence Bundle Root',
                'relative_path' => '10-journal',
                'required' => false,
            ],
        ])->map(function (array $supply) use ($runId): array {
            $relativePath = (string) $supply['relative_path'];
            $absolutePath = $this->storage->path($relativePath);

            if ($this->files->isDirectory($absolutePath)) {
                $entries = collect($this->files->allFiles($absolutePath))
                    ->map(fn ($file): array => [
                        'relative_path' => str_replace($this->storage->activeRunPath().'/', '', $file->getPathname()),
                        'bytes' => $file->getSize(),
                        'sha256' => (string) hash_file('sha256', $file->getPathname()),
                    ])
                    ->values()
                    ->all();

                return [
                    'supply_code' => $supply['supply_code'],
                    'label' => $supply['label'],
                    'required' => $supply['required'],
                    'found' => $entries !== [],
                    'count' => count($entries),
                    'entries' => $entries,
                ];
            }

            return [
                'supply_code' => $supply['supply_code'],
                'label' => $supply['label'],
                'required' => $supply['required'],
                'relative_path' => $relativePath,
                'found' => $this->files->exists($absolutePath),
                'bytes' => $this->files->exists($absolutePath) ? (int) $this->files->size($absolutePath) : 0,
                'sha256' => $this->files->exists($absolutePath) ? (string) hash_file('sha256', $absolutePath) : null,
                'run_id' => $runId,
            ];
        })->values()->all();
    }
}
