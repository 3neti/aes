<?php

namespace App\Election\Attestation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;

final class ElectoralBoardBaselineService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly OfficerRegistry $officers,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function write(): array
    {
        $runPath = $this->storage->activeRunPath();
        $runPathTail = basename($runPath);
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $precinct = (string) ($configuration['precinct_id'] ?? 'unknown');

        $requiredRoles = $this->roleDefinitions('required');
        $optionalRoles = $this->roleDefinitions('optional');
        $registry = $this->officers->all();
        $evaluatedRequired = collect($requiredRoles)->map(function (array $definition): array {
            return $this->evaluateRole($definition, 'required');
        })->values()->all();
        $evaluatedOptional = collect($optionalRoles)->map(function (array $definition): array {
            return $this->evaluateRole($definition, 'optional');
        })->values()->all();

        $missingRequired = collect($evaluatedRequired)->filter(fn (array $entry): bool => ! $entry['present'])->values()->all();

        $report = [
            'schema_version' => 'electoral-board-baseline-1',
            'baseline_profile' => 'eb-role-baseline-v1',
            'generated_at' => $this->clock->now()->toIso8601String(),
            'run_id' => $runPathTail,
            'precinct_id' => $precinct,
            'run_path' => $runPath,
            'required_roles' => $evaluatedRequired,
            'optional_roles' => $evaluatedOptional,
            'required_role_count' => count($evaluatedRequired),
            'required_roles_present' => count($evaluatedRequired) - count($missingRequired),
            'missing_required_roles' => collect($missingRequired)->pluck('code')->values()->all(),
            'missing_required_role_count' => count($missingRequired),
            'officer_count' => count($registry),
            'passed' => count($missingRequired) === 0,
        ];

        $artifactPath = $this->storage->writeJson('runtime/electoral-board-baseline.json', $report);
        $report['artifact_path'] = $artifactPath;
        $report['baseline_hash'] = $this->json->hash($report);
        $this->storage->writeJson('runtime/electoral-board-baseline.json', $report);

        $this->journal->record('electoral_board_baseline.generated', [
            'run_id' => $runPathTail,
            'precinct_id' => $precinct,
            'required_roles_present' => $report['required_roles_present'],
            'required_role_count' => $report['required_role_count'],
            'missing_required_role_count' => $report['missing_required_role_count'],
            'baseline_hash' => $report['baseline_hash'],
        ]);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $path = $this->storage->path('runtime/electoral-board-baseline.json');

        if (! $this->files->exists($path)) {
            return [
                'exists' => false,
                'generate_url' => route('election.provision.eb-role-baseline'),
            ];
        }

        $baseline = $this->storage->readJson('runtime/electoral-board-baseline.json');

        return [
            'exists' => true,
            'artifact' => basename($path),
            'artifact_path' => $path,
            'run_id' => $baseline['run_id'] ?? null,
            'precinct_id' => $baseline['precinct_id'] ?? null,
            'baseline_hash' => $baseline['baseline_hash'] ?? null,
            'required_role_count' => $baseline['required_role_count'] ?? 0,
            'required_roles_present' => $baseline['required_roles_present'] ?? 0,
            'missing_required_role_count' => $baseline['missing_required_role_count'] ?? 0,
            'passed' => $baseline['passed'] ?? false,
            'generated_at' => $baseline['generated_at'] ?? null,
            'generate_url' => route('election.provision.eb-role-baseline'),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function evaluateRole(array $definition, string $scope): array
    {
        $officerRoles = collect((array) ($definition['officer_roles'] ?? []));
        $registryMatches = collect($this->officers->all())
            ->filter(function (array $officer) use ($officerRoles): bool {
                return $officerRoles
                    ->map(fn (string $role): string => strtolower(trim($role)))
                    ->contains(strtolower(trim((string) ($officer['role'] ?? ''))));
            })
            ->map(fn (array $officer): array => [
                'code_hash' => hash('sha256', (string) ($officer['code'] ?? '')),
                'name' => (string) ($officer['name'] ?? ''),
                'role' => (string) ($officer['role'] ?? ''),
            ])
            ->values()
            ->all();

        return [
            'code' => (string) ($definition['code'] ?? ''),
            'name' => (string) ($definition['name'] ?? ''),
            'scope' => $scope,
            'officer_roles' => $officerRoles->values()->all(),
            'present' => $registryMatches !== [],
            'officer_count' => count($registryMatches),
            'officers' => $registryMatches,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function roleDefinitions(string $scope): array
    {
        $roles = config('election.electoral_board_roles.'.$scope, []);

        if (! is_array($roles)) {
            return [];
        }

        return collect($roles)
            ->map(function (array $definition): array {
                return [
                    'code' => (string) ($definition['code'] ?? ''),
                    'name' => (string) ($definition['name'] ?? ''),
                    'officer_roles' => $definition['officer_roles'] ?? [],
                ];
            })
            ->values()
            ->all();
    }
}
