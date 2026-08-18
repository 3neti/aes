<?php

namespace App\Console\Commands;

use App\Election\Preparation\ClcCandidateImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:clc-import {source? : CLC PDF file, PDF directory, or candidate XLSX workbook path}')]
#[Description('Import COMELEC CLC candidate PDFs or a candidate workbook into deterministic local registry files.')]
final class ImportClcCandidatesCommand extends Command
{
    public function handle(ClcCandidateImporter $importer): int
    {
        try {
            $manifest = $importer->import($this->argument('source') === null ? null : (string) $this->argument('source'));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Candidate source imported.');
        $this->line("Sources: {$manifest['source_count']}");
        $this->line("Candidates: {$manifest['candidate_count']}");
        $this->line("Needs review: {$manifest['needs_review_count']}");
        $this->line("Registry hash: {$manifest['registry_hash']}");
        $this->line("Manifest: {$manifest['artifact_path']}");

        return self::SUCCESS;
    }
}
