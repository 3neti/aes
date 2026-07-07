<?php

namespace App\Election\Preparation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionStorage;

final class ActivateImportedPrecinctPackage
{
    public function __construct(
        private readonly PopPrecinctRegistry $registry,
        private readonly ElectionStorage $storage,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $clusteredPrecinct): array
    {
        $precinct = $this->registry->find($clusteredPrecinct);
        $manifest = $this->registry->manifest();
        $package = [
            'schema_version' => 'imported-pop-package-1',
            'election_id' => '2025NLE-POP',
            'precinct_id' => $precinct['clustered_precinct'],
            'ballot_style_id' => 'unassigned',
            'registry_version' => PopWorkbookImporter::RegistryVersion,
            'transport' => 'pop-workbook-import',
            'signature' => 'UNSIGNED-POP-IMPORT-SIMULATION',
            'location' => [
                'region' => $precinct['region'],
                'province' => $precinct['province'],
                'city_municipality' => $precinct['city_municipality'],
                'barangay' => $precinct['barangay'],
                'polling_place' => $precinct['polling_place'],
            ],
            'precinct_cluster' => $precinct['precinct_cluster'],
            'cluster_total' => $precinct['cluster_total'],
            'source' => [
                'row' => $precinct['source_row'],
                'row_hash' => $precinct['row_hash'],
                'registry_hash' => $manifest['registry_hash'] ?? null,
                'source_workbook_hash' => $manifest['source']['sha256'] ?? null,
            ],
        ];
        $package['package_hash'] = $this->json->hash($package);
        $package['artifact_path'] = $this->storage->writeJson("packages/imported/{$package['precinct_id']}.json", $package);

        $this->journal->record('pop.package_created', [
            'precinct_id' => $package['precinct_id'],
            'package_hash' => $package['package_hash'],
        ]);

        return $package;
    }
}
