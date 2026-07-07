<?php

namespace App\Console\Commands;

use App\Election\Preparation\PopWorkbookImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:pop-import {path : Source 2025 NLE POP XLSX workbook path}')]
#[Description('Import the 2025 NLE POP clustered precinct workbook into deterministic local registry files.')]
final class ImportPopWorkbookCommand extends Command
{
    public function handle(PopWorkbookImporter $importer): int
    {
        try {
            $manifest = $importer->import((string) $this->argument('path'));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('POP workbook imported.');
        $this->line("Rows: {$manifest['row_count']}");
        $this->line("Unique clustered precincts: {$manifest['unique_clustered_precinct_count']}");
        $this->line("Total registered voters: {$manifest['total_registered_voters']}");
        $this->line("Registry hash: {$manifest['registry_hash']}");
        $this->line("Manifest: {$manifest['artifact_path']}");

        return self::SUCCESS;
    }
}
