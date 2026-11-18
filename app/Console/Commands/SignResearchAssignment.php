<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LBHurtado\ElectionCeremonies\Bundle\AuthoritySignature;
use LBHurtado\ElectionCeremonies\Bundle\ElectionBundleVerifier;
use LBHurtado\ElectionCeremonies\Bundle\SiteAssignment;

#[Signature('election:research:sign-assignment {output-prefix} {--signing-key= : Raw Ed25519 authority key} {--archive= : Research bundle path} {--device-id= : Workstation identity}')]
#[Description('Sign a research workstation assignment on an offline authority machine')]
class SignResearchAssignment extends Command
{
    public function handle(ElectionBundleVerifier $verifier, SiteAssignment $assignments, AuthoritySignature $signatures): int
    {
        $keyPath = (string) $this->option('signing-key');
        $secret = is_file($keyPath) ? file_get_contents($keyPath) : false;

        if (! is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->error('Supply a raw Ed25519 authority key with --signing-key.');

            return self::FAILURE;
        }

        $prefix = (string) $this->argument('output-prefix');
        $documentPath = $prefix.'.json';
        $signaturePath = $prefix.'.sig';

        if (file_exists($documentPath) || file_exists($signaturePath) || ! is_dir(dirname($prefix))) {
            $this->error('Output directory must exist and assignment files must not already exist.');

            return self::FAILURE;
        }

        $archive = (string) ($this->option('archive') ?: config('election.bundle_workbench.archive'));
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        $bundle = $verifier->verify($archive, [$signatures->keyId($public) => $public]);

        if ($bundle['coverage'] !== 'source_snapshot' || $bundle['readiness'] !== 'research') {
            $this->error('Only a research source snapshot can receive this assignment.');

            return self::FAILURE;
        }

        $assignment = $assignments->sign([
            'bundle_id' => $bundle['bundle_id'],
            'bundle_sha256' => $bundle['archive_sha256'],
            'device_id' => (string) ($this->option('device-id') ?: config('election.bundle_workbench.device_id')),
            'role' => 'research',
            'jurisdiction_id' => 'PH',
            'permitted_sources' => [],
            'ceremonies' => ['begin_review', 'record_decision', 'close_review'],
        ], $secret);
        file_put_contents($documentPath, $assignment['document']);
        file_put_contents($signaturePath, $assignment['signature']);
        $this->info("Signed research assignment at {$documentPath} and {$signaturePath}.");

        return self::SUCCESS;
    }
}
