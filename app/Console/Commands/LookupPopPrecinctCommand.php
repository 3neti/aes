<?php

namespace App\Console\Commands;

use App\Election\Preparation\PopPrecinctRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:pop-lookup {clustered_precinct : Clustered precinct identifier from the POP workbook}')]
#[Description('Look up a clustered precinct in the imported POP registry.')]
final class LookupPopPrecinctCommand extends Command
{
    public function handle(PopPrecinctRegistry $registry): int
    {
        $record = $registry->find((string) $this->argument('clustered_precinct'));

        $this->info("Clustered precinct {$record['clustered_precinct']}");
        $this->line("Region: {$record['region']}");
        $this->line("Province: {$record['province']}");
        $this->line("City/Municipality: {$record['city_municipality']}");
        $this->line("Barangay: {$record['barangay']}");
        $this->line("Precinct cluster: {$record['precinct_cluster']}");
        $this->line("Cluster total: {$record['cluster_total']}");
        $this->line("Polling place: {$record['polling_place']}");

        return self::SUCCESS;
    }
}
