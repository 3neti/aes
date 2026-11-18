<?php

namespace App\Console\Commands;

use App\Election\Bundle\DistrictReconciliationService;
use App\Election\Bundle\NationwideSourceSnapshotValidator;
use App\Election\Core\CanonicalJson;
use App\Election\Preparation\PopMappingProfiles;
use App\Election\Preparation\XlsxPopSourceAdapter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:bundle:reconcile-districts {output-directory}')]
#[Description('Create a source-linked 2025 precinct district crosswalk and correction review ledger')]
class ReconcileElectionDistricts extends Command
{
    public function handle(DistrictReconciliationService $reconciler, XlsxPopSourceAdapter $adapter, CanonicalJson $json): int
    {
        $popPath = resource_path('election/pop/2025NLE_POP.xlsx');

        if (! hash_equals(NationwideSourceSnapshotValidator::Pop2025Sha256, (string) hash_file('sha256', $popPath))) {
            $this->error('The POP workbook differs from the frozen 2025 source.');

            return self::FAILURE;
        }

        $profile = PopMappingProfiles::get();
        $pop = $adapter->extract($popPath, $profile->sourceLabel);
        $profile->validateHeaders($pop->headers);
        $precincts = (function () use ($pop, $profile, $json): \Generator {
            foreach ($pop->rows as $index => $row) {
                yield $profile->map($pop->headers, $row, $index + 2, $json);
            }
        })();
        $report = $reconciler->reconcile(
            $precincts,
            $pop->sha256,
            resource_path('election/pop/2025-district-evidence.json'),
            (string) $this->argument('output-directory'),
        );
        $this->info("Reconciled {$report['precinct_count']} precincts; {$report['unresolved_precinct_count']} remain unresolved.");
        $this->line('Readiness: research');

        return self::SUCCESS;
    }
}
