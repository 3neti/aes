<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Models\SimulationPrecinct;
use RuntimeException;

final class PublicSimulationPublication
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly ElectionClock $clock,
        private readonly VvdatLedgerFreeze $freeze,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function publish(SimulationPrecinct $precinct): array
    {
        $existing = $this->storage->readJson('returns/publication-manifest.json');

        if ($existing !== []) {
            return $existing;
        }

        $validation = $this->freeze->validation();

        if (! $validation['passed']) {
            throw new RuntimeException('Results cannot be published without a valid frozen VVDAT ledger.');
        }

        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $precinctId = (string) ($configuration['precinct_id'] ?? '');
        $returnPath = "returns/{$precinctId}-return.json";
        $return = $this->storage->readJson($returnPath);
        $tally = $this->storage->readJson('runtime/tally.json');

        if ($precinctId === '' || $return === [] || $tally === []) {
            throw new RuntimeException('Results are not ready for publication.');
        }

        $manifest = [
            'schema_version' => 'public-simulation-publication-manifest-1',
            'published_at' => $this->clock->now()->toIso8601String(),
            'round_code' => $precinct->round->code,
            'precinct_code' => $precinct->code,
            'precinct_id' => $precinctId,
            'publication_policy' => 'post-close-simulation-results-only',
            'vvdat_ledger_root' => $validation['ledger_root'],
            'tally_hash' => $tally['tally_hash'] ?? null,
            'return_hash' => $return['return_hash'] ?? null,
            'artifacts' => $this->artifactHashes([
                'tally_json' => 'runtime/tally.json',
                'tally_sheet_pdf' => 'runtime/tally-sheet.pdf',
                'election_return_json' => $returnPath,
                'election_return_pdf' => "returns/{$precinctId}-return.pdf",
                'vvdat_freeze' => 'counting/vvdat-ledger-freeze.json',
            ]),
        ];
        $manifest['manifest_hash'] = $this->json->hash($manifest);
        $manifest['artifact_path'] = $this->storage->writeJson('returns/publication-manifest.json', $manifest);

        $this->journal->record('public_simulation.results_published', [
            'round_code' => $precinct->round->code,
            'precinct_code' => $precinct->code,
            'manifest_hash' => $manifest['manifest_hash'],
            'vvdat_ledger_root' => $manifest['vvdat_ledger_root'],
        ]);

        return $manifest;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return $this->storage->readJson('returns/publication-manifest.json');
    }

    /** @param array<string, string> $paths
     * @return array<string, array{path: string, sha256: string}>
     */
    private function artifactHashes(array $paths): array
    {
        $artifacts = [];

        foreach ($paths as $name => $relativePath) {
            $path = $this->storage->path($relativePath);

            if (! is_file($path)) {
                throw new RuntimeException("Required publication artifact [{$relativePath}] is missing.");
            }

            $artifacts[$name] = [
                'path' => $relativePath,
                'sha256' => hash_file('sha256', $path),
            ];
        }

        return $artifacts;
    }
}
