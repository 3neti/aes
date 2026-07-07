<?php

namespace App\Console\Commands;

use App\Election\Preparation\ActivateImportedPrecinctPackage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:pop-activate {clustered_precinct : Clustered precinct identifier from the imported POP registry}')]
#[Description('Create an imported precinct package skeleton from the POP registry.')]
final class ActivatePopPrecinctCommand extends Command
{
    public function handle(ActivateImportedPrecinctPackage $activate): int
    {
        $package = $activate->handle((string) $this->argument('clustered_precinct'));

        $this->info("Imported POP precinct package {$package['precinct_id']} written.");
        $this->line("Package hash: {$package['package_hash']}");
        $this->line("Artifact: {$package['artifact_path']}");

        return self::SUCCESS;
    }
}
