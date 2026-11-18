<?php

namespace App\Console\Commands;

use App\Election\Bundle\NationwideSourceSnapshotValidator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:bundle:validate-sources {--output= : Write the JSON report to this path}')]
#[Description('Validate the frozen 2025 POP and nationwide COMELEC CLC source snapshot')]
class ValidateNationwideElectionSources extends Command
{
    public function handle(NationwideSourceSnapshotValidator $validator): int
    {
        $report = $validator->validate(
            resource_path('election/clc/2025-national-source-catalog.json'),
            storage_path('app/comelec-clc-2025/download-manifest.json'),
            storage_path('app/comelec-clc-2025/raw'),
            resource_path('election/pop/2025NLE_POP.xlsx'),
            NationwideSourceSnapshotValidator::Pop2025Sha256,
            NationwideSourceSnapshotValidator::Catalog2025Sha256,
        );
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            if (is_file($output)) {
                $this->error('The report output already exists.');

                return self::FAILURE;
            }

            file_put_contents($output, $json);
        } else {
            $this->line($json);
        }

        return $report['valid'] ? self::SUCCESS : self::FAILURE;
    }
}
