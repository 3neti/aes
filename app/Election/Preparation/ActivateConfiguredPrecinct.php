<?php

namespace App\Election\Preparation;

use App\Election\Core\CanonicalJson;
use App\Election\Devices\DeviceCertificationService;
use App\Election\Lifecycle\ElectionRunType;
use App\Election\Scenarios\BrowserWalkthroughControl;
use App\Election\Support\ElectionOperationLock;
use App\Election\Support\ElectionRunScopeLock;
use App\Election\Support\ElectionStorage;
use App\Http\Middleware\LockElectionRunScope;
use RuntimeException;

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
        private readonly ElectionOperationLock $lock,
        private readonly ElectionRunScopeLock $runLock,
        private readonly BrowserWalkthroughControl $walkthrough,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $operation = fn (): array => $this->lock->execute(
            'configured-precinct-activation',
            fn (): array => $this->handleWithinLock(),
            leaseSeconds: 120,
            waitSeconds: 30,
        );

        if (config('election.voting_lock_driver') !== 'local') {
            return $operation();
        }

        return $this->runLock->execute(function () use ($operation): array {
            $this->assertWalkthroughScope();

            return $operation();
        });
    }

    private function assertWalkthroughScope(): void
    {
        $control = $this->walkthrough->read();
        if ($control === [] || in_array($control['status'] ?? null, ['passed', 'failed'], true)) {
            return;
        }
        if (($control['status'] ?? null) !== 'active') {
            throw new RuntimeException('The browser walkthrough reservation is inconsistent. Operator investigation is required.');
        }
        $request = request();
        $verifiedHash = $request->attributes->get(LockElectionRunScope::VerifiedWalkthrough);
        $token = $request->header(BrowserWalkthroughControl::Header);
        if (! is_string($verifiedHash) || ! is_string($control['control_hash'] ?? null)
            || ! hash_equals($control['control_hash'], $verifiedHash)
            || ! is_string($token) || $token === '' || ! in_array($request->ip(), ['127.0.0.1', '::1'], true)
            || config('election.runtime.run_type') !== ElectionRunType::Rehearsal->value) {
            throw new RuntimeException('The active walkthrough does not authorize this precinct activation scope.');
        }
        $verified = $this->walkthrough->authorize($token);
        if (in_array($verified['scenario'] ?? null, ['public-simulation', 'demo-room'], true)
            && ! isset($verified['simulation_scope'])) {
            throw new RuntimeException('The active walkthrough does not authorize this precinct activation scope.');
        }
        $reservedRun = $verified['simulation_scope'] ?? $verified;
        $run = $this->storage->currentRun(ElectionRunType::Rehearsal);
        if (! hash_equals($verifiedHash, (string) ($verified['control_hash'] ?? ''))
            || $reservedRun['run_path'] !== $this->storage->root().'/runs/'.$reservedRun['run_id']
            || ($run['run_id'] ?? null) !== $reservedRun['run_id']
            || ($run['run_path'] ?? null) !== $reservedRun['run_path']
            || ($run['precinct_id'] ?? null) !== (string) config('election.pop.clustered_precinct')) {
            throw new RuntimeException('The active walkthrough does not authorize this precinct activation scope.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function handleWithinLock(): array
    {
        $clusteredPrecinct = (string) config('election.pop.clustered_precinct');
        $currentRun = $this->storage->currentRun();

        $existing = $currentRun !== [] && ($currentRun['precinct_id'] ?? null) === $clusteredPrecinct
            ? $this->existingActivation($clusteredPrecinct)
            : [];

        if ($existing !== []) {
            return $existing;
        }

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

    /**
     * @return array<string, mixed>
     */
    private function existingActivation(string $clusteredPrecinct): array
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $activation = $this->storage->readJson('packages/configured-precinct-activation.json');
        $contests = $configuration['contests'] ?? [];
        $candidateCount = collect(is_array($contests) ? $contests : [])
            ->sum(fn (array $contest): int => count($contest['candidates'] ?? []));

        if (
            ($configuration['precinct_id'] ?? null) !== $clusteredPrecinct
            || ($activation['precinct_id'] ?? null) !== $clusteredPrecinct
            || ! is_array($contests)
            || count($contests) === 0
            || $candidateCount === 0
        ) {
            return [];
        }

        return [
            'configuration' => $configuration,
            'activation_report' => $activation,
            'pop_import' => $activation['pop'] ?? [],
            'clc_import' => $activation['clc'] ?? [],
            'imported_package' => [
                'location' => $activation['location'] ?? [],
            ],
            'device_certification' => $this->storage->readJson('certification/device-certification.json'),
            'cached' => true,
        ];
    }
}
