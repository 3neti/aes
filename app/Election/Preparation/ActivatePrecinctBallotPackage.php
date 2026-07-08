<?php

namespace App\Election\Preparation;

use App\Election\Core\ActivityJournal;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionStorage;

final class ActivatePrecinctBallotPackage
{
    public function __construct(
        private readonly PrecinctBallotDefinitionBuilder $builder,
        private readonly DeterministicMapper $mapper,
        private readonly ElectionStorage $storage,
        private readonly LifecycleState $lifecycle,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @return array{configuration: array<string, mixed>, package: array<string, mixed>, report: array<string, mixed>}
     */
    public function handle(string $clusteredPrecinct, ?string $district = null): array
    {
        $definition = $this->builder->build($clusteredPrecinct, $district);
        $configuration = $this->mapper->derive($definition['registries'], $definition['package']);

        $this->storage->writeJson('registries/sample.json', $definition['registries']);
        $this->storage->writeJson('packages/active-package.json', $definition['package']);
        $this->storage->writeJson('runtime/active-precinct.json', $configuration);
        $this->storage->writeJson('precinct-candidates/active-ballot-definition.json', $definition['report']);
        $this->lifecycle->set(Lifecycle::Certification, false);
        $this->journal->record('pop_clc.lifecycle_package_activated', [
            'precinct_id' => $configuration['precinct_id'],
            'mapping_hash' => $configuration['mapping_hash'],
            'registry_hash' => $definition['report']['registry_hash'],
            'package_hash' => $definition['report']['package_hash'],
        ]);
        $this->journal->record('lifecycle.stage_set', ['stage' => Lifecycle::Certification]);

        return [
            'configuration' => $configuration,
            'package' => $definition['package'],
            'report' => [
                ...$definition['report'],
                'artifact_path' => $this->storage->path('precinct-candidates/active-ballot-definition.json'),
            ],
        ];
    }
}
