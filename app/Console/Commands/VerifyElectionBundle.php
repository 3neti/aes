<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LBHurtado\ElectionCeremonies\Bundle\AuthoritySignature;
use LBHurtado\ElectionCeremonies\Bundle\ElectionBundleVerifier;
use Throwable;

#[Signature('election:bundle:verify {archive} {--trusted-key= : Path to a raw Ed25519 public key}')]
#[Description('Verify a signed election bundle offline against a trusted public key')]
class VerifyElectionBundle extends Command
{
    public function handle(ElectionBundleVerifier $bundles, AuthoritySignature $signatures): int
    {
        $keyPath = $this->option('trusted-key');

        if (! is_string($keyPath) || ! is_file($keyPath)) {
            $this->error('Supply an existing raw Ed25519 public key with --trusted-key.');

            return self::FAILURE;
        }

        $publicKey = file_get_contents($keyPath);

        if (! is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            $this->error('The trusted key file must contain a raw Ed25519 public key.');

            return self::FAILURE;
        }

        try {
            $bundle = $bundles->verify((string) $this->argument('archive'), [
                $signatures->keyId($publicKey) => $publicKey,
            ]);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Verified {$bundle['bundle_id']} ({$bundle['coverage']}, {$bundle['readiness']})");
        $this->line('SHA-256: '.$bundle['archive_sha256']);

        return self::SUCCESS;
    }
}
