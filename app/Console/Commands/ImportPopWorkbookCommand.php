<?php

namespace App\Console\Commands;

use App\Election\Preparation\PopWorkbookImporter;
use App\Election\Support\ElectionRunScopeLock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:pop-import {path : Source 2025 NLE POP XLSX workbook path} {--profile=comelec-pop-2025-nle : POP mapping profile name}')]
#[Description('Import the 2025 NLE POP clustered precinct workbook into deterministic local registry files.')]
final class ImportPopWorkbookCommand extends Command
{
    public function handle(PopWorkbookImporter $importer, ElectionRunScopeLock $runLock): int
    {
        $operation = fn (): int => $this->importWorkbook($importer);

        return config('election.voting_lock_driver') === 'local' ? $runLock->executeCommand($operation) : $operation();
    }

    private function importWorkbook(PopWorkbookImporter $importer): int
    {
        try {
            $manifest = $importer->import((string) $this->argument('path'), (string) $this->option('profile'));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('POP workbook imported.');
        $this->line("Mapping profile: {$manifest['mapping_profile']}");
        $this->line("Rows: {$manifest['row_count']}");
        $this->line("Unique clustered precincts: {$manifest['unique_clustered_precinct_count']}");
        $this->line("Total registered voters: {$manifest['total_registered_voters']}");
        $this->line("Registry hash: {$manifest['registry_hash']}");
        $this->line("Manifest: {$manifest['artifact_path']}");

        return self::SUCCESS;
    }
}
