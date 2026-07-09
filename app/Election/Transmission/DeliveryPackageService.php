<?php

namespace App\Election\Transmission;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use RuntimeException;

final class DeliveryPackageService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly LifecycleState $lifecycle,
        private readonly ActivityJournal $journal,
        private readonly TransmissionService $transmission,
    ) {}

    /**
     * @param  array<string, mixed>|null  $transmission
     * @return array<string, mixed>
     */
    public function prepare(?array $transmission = null): array
    {
        $transmission = $transmission ?? $this->transmission->latestReport();
        $precinct = $this->activePrecinct();
        $configuration = $this->storage->readJson('runtime/active-precinct.json');

        $packageArtifacts = $this->buildArtifacts($precinct);
        $package = [
            'schema_version' => 'delivery-package-1',
            'package_profile' => 'official-handoff-v1',
            'package_id' => $this->packageId($precinct, $transmission['transmission_id'] ?? null, $transmission['transmission_hash'] ?? null),
            'precinct_id' => $precinct,
            'election_id' => $configuration['election_id'] ?? null,
            'mapping_hash' => $configuration['mapping_hash'] ?? null,
            'generated_at' => $this->clock->now()->toIso8601String(),
            'lifecycle_stage' => $this->lifecycle->current(),
            'transmission' => [
                'exists' => $transmission !== [],
                'transmission_id' => $transmission['transmission_id'] ?? null,
                'transmission_hash' => $transmission['transmission_hash'] ?? null,
                'passed' => $transmission['passed'] ?? false,
                'artifact_path' => $transmission ? ($this->storage->path('transmission/transmission-report.json')) : null,
            ],
            'artifacts' => $packageArtifacts,
            'artifact_count' => count($packageArtifacts),
            'required_artifacts_present' => collect($packageArtifacts)
                ->where('required', true)
                ->every(fn (array $artifact): bool => $artifact['exists'] ?? false),
        ];

        $package['delivery_package_hash'] = $this->json->hash($this->forHash($package));
        $package['artifact_path'] = $this->storage->writeJson('transmission/delivery-package.json', $package);

        $this->journal->record('transmission.delivery_package_prepared', [
            'package_id' => $package['package_id'],
            'precinct_id' => $precinct,
            'artifact_count' => $package['artifact_count'],
            'required_artifacts_present' => $package['required_artifacts_present'],
            'delivery_package_hash' => $package['delivery_package_hash'],
            'transmission_id' => $transmission['transmission_id'] ?? null,
        ]);

        return $package;
    }

    /**
     * @return array<string, mixed>
     */
    public function latest(): array
    {
        return $this->storage->readJson('transmission/delivery-package.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $path = $this->storage->path('transmission/delivery-package.json');

        if (! file_exists($path)) {
            return [
                'exists' => false,
                'generate_url' => route('election.transmission.prepare'),
            ];
        }

        $package = $this->latest();

        return [
            'exists' => true,
            'artifact' => basename($path),
            'artifact_path' => str_replace(storage_path('app/election/').'/', '', $path),
            'package_id' => $package['package_id'] ?? null,
            'package_hash' => $package['delivery_package_hash'] ?? null,
            'generated_at' => $package['generated_at'] ?? null,
            'artifact_count' => $package['artifact_count'] ?? 0,
            'transmission_id' => $package['transmission']['transmission_id'] ?? null,
            'transmission_hash' => $package['transmission']['transmission_hash'] ?? null,
            'required_artifacts_present' => $package['required_artifacts_present'] ?? false,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildArtifacts(string $precinct): array
    {
        $candidates = [
            [
                'type' => 'election_return_json',
                'path' => "returns/{$precinct}-return.json",
                'label' => 'Election Return JSON',
                'required' => true,
            ],
            [
                'type' => 'election_return_pdf',
                'path' => "returns/{$precinct}-return.pdf",
                'label' => 'Printed Election Return',
                'required' => true,
            ],
            [
                'type' => 'election_return_legal_evidence',
                'path' => 'returns/election-return-legal-evidence.json',
                'label' => 'Election Return Legal Evidence',
                'required' => true,
            ],
            [
                'type' => 'return_copy_distribution',
                'path' => "returns/{$precinct}-copy-distribution.json",
                'label' => 'Return Copy Distribution',
                'required' => true,
            ],
            [
                'type' => 'transmission_report',
                'path' => 'transmission/transmission-report.json',
                'label' => 'Transmission Report',
                'required' => false,
            ],
            [
                'type' => 'evidence_manifest',
                'path' => 'diagnostics/evidence-manifest.json',
                'label' => 'Evidence Manifest',
                'required' => false,
            ],
        ];

        return collect($candidates)
            ->map(function (array $item): array {
                $relative = (string) $item['path'];
                $path = $this->storage->path($relative);
                $exists = file_exists($path);

                if (! $exists && (bool) $item['required']) {
                    throw new RuntimeException("Required delivery artifact missing: {$relative}.");
                }

                if (! $exists) {
                    return [
                        'type' => $item['type'],
                        'label' => $item['label'],
                        'relative_path' => $relative,
                        'required' => $item['required'],
                        'exists' => false,
                    ];
                }

                return [
                    'type' => $item['type'],
                    'label' => $item['label'],
                    'relative_path' => $relative,
                    'required' => $item['required'],
                    'exists' => true,
                    'bytes' => (int) @filesize($path),
                    'sha256' => hash_file('sha256', $path),
                ];
            })
            ->values()
            ->all();
    }

    private function activePrecinct(): string
    {
        return (string) ($this->storage->readJson('runtime/active-precinct.json')['precinct_id'] ?? '0421-A');
    }

    private function packageId(string $precinct, ?string $transmissionId, ?string $transmissionHash): string
    {
        $seed = implode(
            '|',
            [
                $precinct,
                $transmissionId ?? 'no-transmission',
                $transmissionHash ?? 'no-hash',
                $this->lifecycle->current(),
            ],
        );

        return 'delivery-package-'.$this->clock->now()->format('YmdHis').'-'.substr(hash('sha256', $seed), 0, 8);
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    private function forHash(array $package): array
    {
        return [
            ...$package,
            'artifact_path' => null,
        ];
    }
}
