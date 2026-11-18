<?php

namespace App\Election\Bundle;

use LBHurtado\ElectionCeremonies\Bundle\ElectionBundleBuilder;
use LBHurtado\ElectionPh\Bundle\PhilippineRegistryBuilder;
use RuntimeException;

class PilotElectionBundleService
{
    public function __construct(
        private readonly PhilippineRegistryBuilder $registries,
        private readonly ElectionBundleBuilder $bundles,
    ) {}

    /** @return array<string, mixed> */
    public function build(string $destination, string $authoritySecretKey, ?string $registrySource = null): array
    {
        $source = $registrySource ?? resource_path('election/sample/registries.json');
        $registries = json_decode((string) file_get_contents($source), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($registries) || ! is_string($registries['election_id'] ?? null)) {
            throw new RuntimeException('Pilot election registry is malformed.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'aes-pilot-registry-');

        if ($temporary === false) {
            throw new RuntimeException('Cannot allocate pilot registry file.');
        }

        unlink($temporary);

        try {
            $this->registries->build($registries, $temporary);

            return $this->bundles->build($temporary, [
                'profiles/ceremonies.yaml' => base_path('vendor/3neti/election-ph/profiles/aes-pilot.yaml'),
                'profiles/truth-qr.yaml' => base_path('vendor/3neti/truth-qr/profiles/election.yaml'),
            ], [
                'bundle_id' => $registries['election_id'].'-pilot-1',
                'election_id' => $registries['election_id'],
                'coverage' => 'pilot',
                'registry_schema' => 'ph-election-registry-1',
            ], $authoritySecretKey, $destination);
        } finally {
            @unlink($temporary);
        }
    }
}
