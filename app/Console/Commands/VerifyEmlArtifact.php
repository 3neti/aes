<?php

namespace App\Console\Commands;

use App\Election\Interoperability\Eml\EmlArtifactService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

#[Signature('election:eml-verify {artifact : Path to the EML XML artifact} {--signature= : Optional detached signature JSON path}')]
#[Description('Verify an EML artifact using the commissioned offline schemas and signing key')]
final class VerifyEmlArtifact extends Command
{
    public function handle(EmlArtifactService $artifacts, Filesystem $files): int
    {
        $artifactPath = (string) $this->argument('artifact');

        if (! $files->isFile($artifactPath)) {
            $this->error('EML artifact not found.');

            return self::FAILURE;
        }

        $signaturePath = (string) ($this->option('signature') ?: $artifactPath.'.signature.json');
        $signature = $files->isFile($signaturePath)
            ? json_decode($files->get($signaturePath), true, flags: JSON_THROW_ON_ERROR)
            : [];
        $report = $artifacts->verifyContents($files->get($artifactPath), $signature);
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return ($report['valid'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
