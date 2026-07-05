<?php

namespace App\Election\Preparation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionStorage;

final class ActivateSamplePackage
{
    public function __construct(
        private readonly SampleElectionData $sample,
        private readonly DeterministicMapper $mapper,
        private readonly ElectionStorage $storage,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly LifecycleState $lifecycle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $registries = $this->sample->registries();
        $package = $this->sample->package();
        $configuration = $this->mapper->derive($registries, $package);

        $this->storage->writeJson('registries/sample.json', $registries);
        $this->storage->writeJson('packages/active-package.json', [
            ...$package,
            'package_hash' => $this->json->hash($package),
            'registry_hash' => $this->json->hash($registries),
        ]);
        $this->storage->writeJson('runtime/active-precinct.json', $configuration);
        $this->lifecycle->set(Lifecycle::Certification, false);
        $this->journal->record('package.activated', [
            'election_id' => $configuration['election_id'],
            'precinct_id' => $configuration['precinct_id'],
            'mapping_hash' => $configuration['mapping_hash'],
        ]);
        $this->journal->record('lifecycle.stage_set', ['stage' => Lifecycle::Certification]);

        return $configuration;
    }
}
