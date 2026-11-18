<?php

namespace App\Console\Commands;

use App\Election\Bundle\ResearchElectionBundleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:bundle:build-research {output} {--signing-key= : Path to a raw Ed25519 research signing key} {--review-report= : Closed research review report} {--decision-ledger= : Closed decision JSONL ledger} {--input-bundle= : Signed bundle reviewed by the report}')]
#[Description('Build a signed 2025 national source snapshot for offline research')]
class BuildResearchElectionBundle extends Command
{
    public function handle(ResearchElectionBundleService $bundles): int
    {
        $output = (string) $this->argument('output');
        $keyPath = $this->option('signing-key');

        if (file_exists($output)) {
            $this->error('The output bundle already exists.');

            return self::FAILURE;
        }

        if (! is_string($keyPath) || ! is_file($keyPath)) {
            $this->error('Supply an existing raw Ed25519 signing key with --signing-key.');

            return self::FAILURE;
        }

        $secretKey = file_get_contents($keyPath);

        if (! is_string($secretKey) || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->error('The signing key file must contain a raw Ed25519 secret key.');

            return self::FAILURE;
        }

        $manifest = $bundles->build(
            $output,
            $secretKey,
            $this->option('review-report') ?: null,
            $this->option('decision-ledger') ?: null,
            $this->option('input-bundle') ?: null,
        );
        $this->info("Built research snapshot {$manifest['bundle_id']} at {$output}");
        $this->line('SHA-256: '.hash_file('sha256', $output));

        return self::SUCCESS;
    }
}
