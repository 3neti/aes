<?php

namespace App\Election\Preparation;

use App\Election\Core\CanonicalJson;
use App\Election\Devices\DeviceCertificationService;
use App\Election\Support\ElectionStorage;

final class ActivateConfiguredPrecinct
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly PopWorkbookImporter $popImporter,
        private readonly ClcCandidateImporter $clcImporter,
        private readonly ActivateImportedPrecinctPackage $activateImportedPrecinct,
        private readonly ActivatePrecinctBallotPackage $activatePrecinctBallot,
        private readonly CanonicalJson $json,
        private readonly DeviceCertificationService $devices,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $clusteredPrecinct = (string) config('election.pop.clustered_precinct');
        $currentRun = $this->storage->currentRun();

        if ($currentRun === [] || ($currentRun['precinct_id'] ?? null) !== $clusteredPrecinct) {
            $this->storage->startRun(
                'operator',
                $clusteredPrecinct,
                now()->format('Ymd-His'),
                creationSource: 'browser-provisioning',
            );
        }

        $popManifest = $this->popImporter->import(
            (string) config('election.pop.source_path'),
            (string) config('election.pop.profile'),
        );
        $clcManifest = $this->clcImporter->import();
        $importedPackage = $this->activateImportedPrecinct->handle($clusteredPrecinct);
        $activation = $this->activatePrecinctBallot->handle(
            $clusteredPrecinct,
            (string) config('election.pop.district'),
        );

        $report = [
            'schema_version' => 'configured-precinct-activation-1',
            'run_id' => $this->storage->currentRun()['run_id'] ?? null,
            'precinct_id' => $clusteredPrecinct,
            'district' => (string) config('election.pop.district'),
            'location' => $importedPackage['location'] ?? [],
            'ballot_style_id' => $activation['configuration']['ballot_style_id'] ?? null,
            'contest_count' => $activation['report']['contest_count'] ?? 0,
            'candidate_count' => $activation['report']['candidate_count'] ?? 0,
            'mapping_hash' => $activation['configuration']['mapping_hash'] ?? null,
            'tabulation_profile' => $activation['configuration']['tabulation_profile'] ?? null,
            'package_hash' => $activation['report']['package_hash'] ?? null,
            'ballot_registry_hash' => $activation['report']['registry_hash'] ?? null,
            'pop' => [
                'source_filename' => $popManifest['source']['filename'] ?? null,
                'source_hash' => $popManifest['source']['sha256'] ?? null,
                'mapping_profile' => $popManifest['mapping_profile'] ?? null,
                'registry_hash' => $popManifest['registry_hash'] ?? null,
                'manifest_hash' => $popManifest['manifest_hash'] ?? null,
            ],
            'clc' => [
                'source_count' => $clcManifest['source_count'] ?? 0,
                'candidate_count' => $clcManifest['candidate_count'] ?? 0,
                'needs_review_count' => $clcManifest['needs_review_count'] ?? 0,
                'registry_hash' => $clcManifest['registry_hash'] ?? null,
                'manifest_hash' => $clcManifest['manifest_hash'] ?? null,
            ],
        ];
        $report['activation_hash'] = $this->json->hash($report);
        $report['artifact_path'] = $this->storage->path('packages/configured-precinct-activation.json');
        $this->storage->writeJson('packages/configured-precinct-activation.json', $report);
        $certification = $this->devices->run();

        return [
            ...$activation,
            'pop_import' => $popManifest,
            'clc_import' => $clcManifest,
            'imported_package' => $importedPackage,
            'activation_report' => $report,
            'device_certification' => $certification,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return $this->storage->readJson('packages/configured-precinct-activation.json');
    }
}
