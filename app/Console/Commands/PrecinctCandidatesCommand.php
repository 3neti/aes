<?php

namespace App\Console\Commands;

use App\Election\Preparation\PrecinctCandidateResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:precinct-candidates {clustered_precinct : Clustered precinct identifier from the POP registry} {--district= : District label for district-level contests} {--json : Output the resolved candidates as JSON} {--write-report : Persist JSON and text report artifacts}')]
#[Description('View candidates for a precinct by combining imported POP and CLC registries.')]
final class PrecinctCandidatesCommand extends Command
{
    public function handle(PrecinctCandidateResolver $resolver): int
    {
        try {
            $report = $resolver->resolve(
                (string) $this->argument('clustered_precinct'),
                $this->option('district') === null ? null : (string) $this->option('district'),
                (bool) $this->option('write-report'),
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Candidates for clustered precinct {$report['clustered_precinct']}");
        $this->line("Location: {$report['precinct']['city_municipality']}, {$report['precinct']['province']}");
        $this->line("Contests: {$report['contest_count']}");
        $this->line("Candidates: {$report['candidate_count']}");
        $this->line("Needs review: {$report['needs_review_count']}");
        $this->line("CLC registry hash: {$report['clc_registry_hash']}");

        foreach ($report['contests'] as $contest) {
            $this->newLine();
            $this->line("{$contest['office']} - {$contest['geography']}");

            foreach ($contest['candidates'] as $candidate) {
                $this->line("  {$candidate['ballot_number']}. {$candidate['name_on_ballot']}");
            }
        }

        if (isset($report['artifact_path'])) {
            $this->newLine();
            $this->line("Report: {$report['artifact_path']}");
            $this->line("Text report: {$report['text_artifact_path']}");
        }

        return self::SUCCESS;
    }
}
