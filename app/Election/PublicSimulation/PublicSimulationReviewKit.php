<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class PublicSimulationReviewKit
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
    ) {}

    /** @return array<string, mixed> */
    public function build(SimulationRound $round): array
    {
        $round->loadMissing('precincts');
        $kitDirectory = $this->kitDirectory($round);
        $this->files->ensureDirectoryExists($kitDirectory);

        $kit = [
            'schema_version' => 'public-simulation-review-kit-1',
            'generated_at' => $this->clock->now()->toIso8601String(),
            'round' => [
                'code' => $round->code,
                'name' => $round->name,
                'status' => $round->status,
                'opened_at' => $round->opened_at?->toIso8601String(),
                'archived_at' => $round->archived_at?->toIso8601String(),
            ],
            'privacy_notice' => 'This kit indexes ceremony-level review artifacts only. It excludes voter identities, browser sessions, control numbers, private print releases, individual ballot selections, and QR payloads.',
            'precincts' => $round->precincts
                ->sortBy('code')
                ->map(fn (SimulationPrecinct $precinct): array => $this->precinct($round, $precinct))
                ->values()
                ->all(),
        ];
        $kit['kit_hash'] = $this->json->hash($kit);
        $kit['artifact_path'] = $kitDirectory.'/review-kit.json';
        $this->files->put($kit['artifact_path'], $this->json->encode($kit));
        $this->files->put($kitDirectory.'/README.md', $this->readme($kit));

        return $kit;
    }

    private function kitDirectory(SimulationRound $round): string
    {
        return $this->roundDirectory($round).'/REVIEW-KIT';
    }

    private function roundDirectory(SimulationRound $round): string
    {
        $directory = trim((string) config('election.storage.directory', 'election'), '/');
        $baseDirectory = Str::before($directory.'/public-simulations/', '/public-simulations/');

        return storage_path("app/{$baseDirectory}/public-simulations/{$round->code}");
    }

    /** @return array<string, mixed> */
    private function precinct(SimulationRound $round, SimulationPrecinct $precinct): array
    {
        $precinctDirectory = $this->roundDirectory($round).'/'.$precinct->code;
        $context = $this->runContext($precinctDirectory);
        $runPath = is_string($context['run_path'] ?? null) ? $context['run_path'] : null;

        return [
            'code' => $precinct->code,
            'label' => $precinct->label,
            'city_municipality' => $precinct->city_municipality,
            'province' => $precinct->province,
            'status' => $precinct->status,
            'run_id' => $context['run_id'] ?? null,
            'run_status' => $context['status'] ?? null,
            'review_artifacts' => $runPath !== null && $this->files->isDirectory($runPath)
                ? $this->reviewArtifacts($runPath)
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function runContext(string $precinctDirectory): array
    {
        $paths = [$precinctDirectory.'/current-run.json', ...glob($precinctDirectory.'/pointers/*.json')];

        foreach ($paths as $path) {
            if (! $this->files->exists($path)) {
                continue;
            }

            $context = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);

            if (is_array($context) && isset($context['run_path'])) {
                return $context;
            }
        }

        return [];
    }

    /** @return array<int, array{path: string, bytes: int, sha256: string}> */
    private function reviewArtifacts(string $runPath): array
    {
        return collect($this->files->allFiles($runPath))
            ->map(fn ($file): array => [
                'path' => str_replace($runPath.'/', '', $file->getPathname()),
                'absolute_path' => $file->getPathname(),
                'bytes' => $file->getSize(),
            ])
            ->filter(fn (array $artifact): bool => $this->isReviewArtifact($artifact['path']))
            ->map(fn (array $artifact): array => [
                'path' => $artifact['path'],
                'bytes' => $artifact['bytes'],
                'sha256' => hash_file('sha256', $artifact['absolute_path']),
            ])
            ->sortBy('path')
            ->values()
            ->all();
    }

    private function isReviewArtifact(string $path): bool
    {
        return str_ends_with($path, '/public-simulation-participation-policy.json')
            || str_contains($path, '/contention-reports/')
            || str_ends_with($path, '/vvdat-ledger-freeze.json')
            || str_ends_with($path, '/tally-sheet.pdf')
            || str_contains($path, '/public-rma-audit-summary.')
            || str_ends_with($path, '/publication-manifest.json')
            || (str_contains($path, '/07-election-return/') && str_ends_with($path, '-return.pdf'));
    }

    /** @param array<string, mixed> $kit */
    private function readme(array $kit): string
    {
        $lines = [
            '# COMELEC Public Simulation Review Kit',
            '',
            "Round: {$kit['round']['code']} ({$kit['round']['status']})",
            "Generated: {$kit['generated_at']}",
            "Kit hash: {$kit['kit_hash']}",
            '',
            'This folder is a review index. `review-kit.json` lists the ceremony-level artifacts that can be inspected in each precinct evidence namespace, with SHA-256 hashes.',
            '',
            'It deliberately excludes voter identities, browser sessions, control numbers, private print releases, individual selections, and QR payloads.',
            '',
            '## Precincts',
        ];

        foreach ($kit['precincts'] as $precinct) {
            $lines[] = "- {$precinct['code']}: {$precinct['status']}; ".count($precinct['review_artifacts']).' review artifacts indexed.';
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
