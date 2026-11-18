<?php

namespace App\Election\Bundle;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LBHurtado\ElectionCeremonies\Bundle\AuthoritySignature;
use LBHurtado\ElectionCeremonies\Bundle\BundleCommissioner;
use LBHurtado\ElectionCeremonies\Bundle\ElectionBundleVerifier;
use LBHurtado\ElectionCeremonies\Bundle\SiteAssignment;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyProfile;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyRequest;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyRunner;
use RuntimeException;
use Throwable;
use ZipArchive;

final readonly class ResearchRunService
{
    public function __construct(
        private ResearchBundleWorkbench $workbench,
        private ElectionBundleVerifier $verifier,
        private SiteAssignment $assignments,
        private AuthoritySignature $signatures,
        private DatabaseResearchCeremonyStore $store,
        private ResearchActorVerifier $actors,
        private ResearchSnapshotPipe $snapshot,
        private ResearchDecisionPipe $decision,
        private ResearchReportPipe $report,
        private Pipeline $pipeline,
    ) {}

    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        $overview = $this->workbench->overview();

        if (! $overview['available']) {
            return null;
        }

        $run = DB::table('election_research_runs')
            ->where('bundle_hash', $overview['bundle']['archive_sha256'])->first();

        return $run === null ? null : $this->present($run);
    }

    public function canStart(): bool
    {
        if ($this->current() !== null) {
            return false;
        }

        try {
            $overview = $this->workbench->overview();
            $public = $this->requiredFile((string) config('election.bundle_workbench.trusted_key'));
            $document = $this->requiredFile((string) config('election.bundle_workbench.assignment_document'));
            $signature = $this->requiredFile((string) config('election.bundle_workbench.assignment_signature'));
            $assignment = $this->assignments->verify(
                $document,
                $signature,
                [$this->signatures->keyId($public) => $public],
                [...$overview['bundle'], 'bundle_id' => $overview['bundle']['id']],
                (string) config('election.bundle_workbench.device_id'),
            );
            $archive = new ZipArchive;

            if ($archive->open((string) config('election.bundle_workbench.archive'), ZipArchive::RDONLY) !== true) {
                return false;
            }

            try {
                $bytes = $archive->getFromName('profiles/ceremonies.yaml');
            } finally {
                $archive->close();
            }

            if (! is_string($bytes)) {
                return false;
            }

            $temporary = tempnam(sys_get_temp_dir(), 'research-profile-');
            file_put_contents($temporary, $bytes);

            try {
                $profile = CeremonyProfile::fromYaml($temporary);
            } finally {
                unlink($temporary);
            }

            return $profile->runType === 'research'
                && $assignment['role'] === 'research'
                && array_diff(['begin_review', 'record_decision', 'close_review'], $assignment['ceremonies']) === []
                && array_diff($assignment['ceremonies'], array_keys($profile->ceremonies)) === [];
        } catch (Throwable) {
            return false;
        }
    }

    public function start(User $reviewer): string
    {
        $overview = $this->workbench->overview();

        if (! $overview['available']) {
            throw new RuntimeException('A verified research bundle is required.');
        }

        $bundleHash = $overview['bundle']['archive_sha256'];
        $existing = DB::table('election_research_runs')->where('bundle_hash', $bundleHash)->first();

        if ($existing !== null) {
            return $this->resumeExisting($existing, $reviewer, $overview['bundle']['id']);
        }

        $document = $this->requiredFile((string) config('election.bundle_workbench.assignment_document'));
        $signature = $this->requiredFile((string) config('election.bundle_workbench.assignment_signature'));
        $publicKey = $this->requiredFile((string) config('election.bundle_workbench.trusted_key'));
        $runId = (string) Str::ulid();
        $directory = rtrim((string) config('election.bundle_workbench.run_directory'), '/').'/'.$runId;
        $active = $directory.'/bundle';
        $commissioner = new BundleCommissioner($this->verifier, $this->assignments, $this->store);
        try {
            $commissioned = $commissioner->commission(
                $runId,
                (string) config('election.bundle_workbench.archive'),
                $document,
                $signature,
                [$this->signatures->keyId($publicKey) => $publicKey],
                (string) config('election.bundle_workbench.device_id'),
                'research',
                $active,
            );
        } catch (QueryException $exception) {
            $existing = DB::table('election_research_runs')->where('bundle_hash', $bundleHash)->first();

            if ($existing === null) {
                throw $exception;
            }

            return $this->resumeExisting($existing, $reviewer, $overview['bundle']['id']);
        }

        DB::table('election_research_runs')->where('id', $runId)->update([
            'bundle_id' => $commissioned['bundle_id'],
            'started_by' => $reviewer->id,
            'artifact_directory' => $directory,
            'updated_at' => now(),
        ]);
        $this->run($runId, 'begin_review', $reviewer, [], 'begin-review');

        return $runId;
    }

    private function resumeExisting(object $existing, User $reviewer, string $bundleId): string
    {
        if ($existing->artifact_directory === '') {
            $directory = rtrim((string) config('election.bundle_workbench.run_directory'), '/').'/'.$existing->id;

            if (! is_file($directory.'/bundle/profiles/ceremonies.yaml')) {
                throw new RuntimeException('Research run staging is incomplete.');
            }

            DB::table('election_research_runs')->where('id', $existing->id)->update([
                'bundle_id' => $bundleId,
                'artifact_directory' => $directory,
                'started_by' => $existing->started_by ?? $reviewer->id,
                'updated_at' => now(),
            ]);
        }

        if ($existing->stage === null) {
            $this->run($existing->id, 'begin_review', $reviewer, [], 'begin-review');
        }

        return $existing->id;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function run(string $runId, string $ceremony, User $reviewer, array $input, string $idempotencyKey): array
    {
        $run = $this->requireRun($runId);
        $profile = CeremonyProfile::fromYaml($run->artifact_directory.'/bundle/profiles/ceremonies.yaml');
        $runner = new CeremonyRunner($profile, $this->store, $this->pipeline, $this->actors, [
            'source_snapshot_check' => $this->snapshot,
            'review_decision' => $this->decision,
            'review_report' => $this->report,
        ]);
        $request = new CeremonyRequest(
            $runId,
            $ceremony,
            (string) $reviewer->id,
            $run->bundle_hash,
            $run->assignment_hash,
            $idempotencyKey,
            $input,
        );
        $result = $runner->run($request);

        if ($ceremony === 'close_review') {
            $this->export($run, $result['evidence']['review_report']);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function show(string $runId): array
    {
        $run = $this->requireRun($runId);
        $this->store->state($runId);

        return $this->present($run);
    }

    /** @return array<int, array<string, mixed>> */
    public function decisions(string $runId, int $limit = 30): array
    {
        $this->requireRun($runId);

        return DB::table('election_research_decisions')
            ->where('run_id', $runId)->orderByDesc('id')->limit($limit)->get()
            ->map(fn (object $row): array => [
                'case_type' => $row->case_type,
                'case_id' => $row->case_id,
                'decision' => $row->decision,
                'reviewer_id' => $row->reviewer_id,
                'rationale' => $row->rationale,
                'created_at' => $row->created_at,
            ])->all();
    }

    /** @return array<string, string> */
    public function precinctDecisions(string $runId): array
    {
        $latest = [];

        foreach (DB::table('election_research_decisions')->where('run_id', $runId)
            ->where('case_type', 'precinct')->orderBy('id')->cursor() as $decision) {
            $latest[$decision->case_id] = $decision->decision;
        }

        return $latest;
    }

    private function requireRun(string $runId): object
    {
        $run = DB::table('election_research_runs')->where('id', $runId)->first();
        $overview = $this->workbench->overview();

        if ($run === null || ! $overview['available']
            || $run->bundle_hash !== $overview['bundle']['archive_sha256']) {
            throw new RuntimeException('Research run is unavailable for the current bundle.');
        }

        return $run;
    }

    /** @return array<string, mixed> */
    private function present(object $run): array
    {
        return [
            'id' => $run->id,
            'bundle_id' => $run->bundle_id,
            'bundle_hash' => $run->bundle_hash,
            'stage' => $run->stage,
            'started_by' => $run->started_by,
            'started_at' => $run->created_at,
            'closed_at' => $run->closed_at,
            'decision_count' => DB::table('election_research_decisions')->where('run_id', $run->id)->count(),
        ];
    }

    private function requiredFile(string $path): string
    {
        $bytes = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('A signed research assignment and trust key must be configured.');
        }

        return $bytes;
    }

    /** @param array<string, mixed> $summary */
    private function export(object $run, array $summary): void
    {
        $directory = $run->artifact_directory.'/review';

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create research review export directory.');
        }

        $ledgerPath = $directory.'/decisions.jsonl';
        $hash = $this->report->writeLedger($run->id, $ledgerPath);

        if (! hash_equals($summary['decision_ledger_sha256'], $hash)) {
            throw new RuntimeException('Research decision export changed after closure.');
        }

        $reportPath = $directory.'/research-review.json';
        $temporary = $reportPath.'.tmp';
        file_put_contents($temporary, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        if (! rename($temporary, $reportPath)) {
            throw new RuntimeException('Cannot publish research review report.');
        }
    }
}
