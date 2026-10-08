<?php

namespace App\Election\Interoperability\Eml;

use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use ZipArchive;

final class EmlEvidencePackageService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly CanonicalJson $json,
        private readonly EmlArtifactSigner $signer,
        private readonly Filesystem $files,
    ) {}

    /**
     * @param  array<string, mixed>  $return
     * @param  array<string, array<string, mixed>>  $emlArtifacts
     * @return array<string, mixed>
     */
    public function writePrecinct(array $return, array $emlArtifacts): array
    {
        $precinctId = (string) ($return['precinct_id'] ?? 'unknown-precinct');
        $artifactPaths = collect($emlArtifacts)
            ->flatMap(fn (array $artifact): array => array_filter([
                $artifact['path'] ?? null,
                $artifact['signature_path'] ?? null,
            ]))
            ->push("returns/{$precinctId}-return.json")
            ->push("returns/{$precinctId}-return.pdf")
            ->push("returns/{$precinctId}-return-national.pdf")
            ->push("returns/{$precinctId}-return-local.pdf")
            ->filter(fn (mixed $path): bool => is_string($path) && $this->files->isFile($this->storage->path($path)))
            ->unique()
            ->sort()
            ->values();
        $artifacts = $artifactPaths->map(fn (string $path): array => [
            'path' => $path,
            'bytes' => $this->files->size($this->storage->path($path)),
            'sha256' => hash_file('sha256', $this->storage->path($path)),
        ])->all();

        $manifest = [
            'schema_version' => 'waes-eml-evidence-manifest-1',
            'profile' => 'waes-eml-7-base-1',
            'election_id' => $return['election_id'] ?? null,
            'precinct_id' => $precinctId,
            'mapping_hash' => $return['mapping_hash'] ?? null,
            'tally_hash' => $return['tally_hash'] ?? null,
            'return_hash' => $return['return_hash'] ?? null,
            'truth_payload_hashes' => collect((array) data_get($return, 'truth_tally.scopes', []))
                ->map(fn (array $scope): mixed => $scope['payload_hash'] ?? null)
                ->filter()
                ->all(),
            'eml_artifacts' => $emlArtifacts,
            'artifacts' => $artifacts,
        ];
        $manifest['manifest_hash'] = $this->json->hash($manifest);
        $manifest['signature'] = $this->signer->sign($this->json->encode($manifest));
        $manifestRelativePath = "returns/{$precinctId}-eml-evidence-manifest.json";
        $this->storage->writeJson($manifestRelativePath, $manifest);
        $zipRelativePath = "returns/{$precinctId}-eml-evidence-package.zip";
        $this->writeZip($zipRelativePath, $artifactPaths->push($manifestRelativePath)->all());

        return [
            'manifest_path' => $manifestRelativePath,
            'manifest_hash' => $manifest['manifest_hash'],
            'package_path' => $zipRelativePath,
            'package_sha256' => hash_file('sha256', $this->storage->path($zipRelativePath)),
        ];
    }

    /** @return array<string, mixed> */
    public function verifyPrecinctManifest(string $relativePath): array
    {
        $manifest = $this->storage->readJson($relativePath);
        $signature = (array) ($manifest['signature'] ?? []);
        unset($manifest['signature']);
        $manifestHash = (string) ($manifest['manifest_hash'] ?? '');
        $hashable = $manifest;
        unset($hashable['manifest_hash']);
        $manifestHashValid = $manifestHash !== '' && hash_equals($manifestHash, $this->json->hash($hashable));
        $signatureValid = $signature !== [] && $this->signer->verify($this->json->encode($manifest), $signature);
        $artifactChecks = collect((array) ($manifest['artifacts'] ?? []))
            ->map(function (array $artifact): array {
                $path = $this->storage->path((string) ($artifact['path'] ?? ''));
                $exists = $this->files->isFile($path);
                $hashValid = $exists && hash_equals(
                    (string) ($artifact['sha256'] ?? ''),
                    (string) hash_file('sha256', $path),
                );

                return [
                    'path' => $artifact['path'] ?? null,
                    'exists' => $exists,
                    'hash_valid' => $hashValid,
                ];
            })
            ->values()
            ->all();
        $artifactsValid = collect($artifactChecks)->every(fn (array $check): bool => $check['exists'] && $check['hash_valid']);

        return [
            'valid' => $manifestHashValid && $signatureValid && $artifactsValid,
            'manifest_hash_valid' => $manifestHashValid,
            'signature_valid' => $signatureValid,
            'artifacts_valid' => $artifactsValid,
            'artifact_checks' => $artifactChecks,
        ];
    }

    /** @param array<int, string> $relativePaths */
    private function writeZip(string $relativePath, array $relativePaths): void
    {
        $path = $this->storage->path($relativePath);
        $this->files->ensureDirectoryExists(dirname($path));
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the EML evidence package.');
        }

        foreach ($relativePaths as $artifactPath) {
            $absolutePath = $this->storage->path($artifactPath);

            if ($this->files->isFile($absolutePath)) {
                $zip->addFile($absolutePath, $artifactPath);
            }
        }

        $zip->close();
    }
}
