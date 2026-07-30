<?php

namespace App\Console\Commands;

use App\Election\PublicSimulation\PublicVvdatAuditExportVerifier;
use Illuminate\Console\Command;

final class VerifyPublicVvdatAuditExport extends Command
{
    /** @var string */
    protected $signature = 'election:public-simulation:verify-vvdat-export {path : Path to a downloaded public VVDAT audit export JSON file}';

    /** @var string */
    protected $description = 'Independently verify the hash and tally in a public VVDAT audit export';

    public function handle(PublicVvdatAuditExportVerifier $verifier): int
    {
        $path = (string) $this->argument('path');
        $contents = file_get_contents($path);

        if ($contents === false) {
            $this->error("Unable to read VVDAT audit export [{$path}].");

            return self::FAILURE;
        }

        $verification = $verifier->verify($contents);

        if (! $verification['passed']) {
            $this->error('VVDAT audit export verification failed.');
            $this->line(implode(PHP_EOL, $verification['errors']));

            return self::FAILURE;
        }

        $this->info("VVDAT audit export verified for {$verification['record_count']} records.");
        $this->line("Derived tally hash: {$verification['derived_tally_hash']}");

        return self::SUCCESS;
    }
}
