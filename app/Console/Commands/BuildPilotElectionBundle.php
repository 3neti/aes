<?php

namespace App\Console\Commands;

use App\Election\Bundle\PilotElectionBundleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:bundle:build-pilot {output} {--signing-key= : Path to a raw Ed25519 authority secret key}')]
#[Description('Build a signed pilot election bundle from the AES sample registry')]
class BuildPilotElectionBundle extends Command
{
    public function handle(PilotElectionBundleService $bundles): int
    {
        $output = (string) $this->argument('output');
        $keyPath = $this->option('signing-key');

        if (is_file($output)) {
            $this->error('The output bundle already exists.');

            return self::FAILURE;
        }

        if (! is_string($keyPath) || ! is_file($keyPath)) {
            $this->error('Supply an existing Ed25519 secret key file with --signing-key.');

            return self::FAILURE;
        }

        $secretKey = file_get_contents($keyPath);

        if (! is_string($secretKey) || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->error('Authority secret key file must contain a raw Ed25519 secret key.');

            return self::FAILURE;
        }

        $manifest = $bundles->build($output, $secretKey);
        $this->info("Built {$manifest['bundle_id']} at {$output}");
        $this->line('SHA-256: '.hash_file('sha256', $output));

        return self::SUCCESS;
    }
}
