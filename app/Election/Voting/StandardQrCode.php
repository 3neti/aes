<?php

namespace App\Election\Voting;

use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use RuntimeException;
use Symfony\Component\Process\Process;

final class StandardQrCode
{
    public function renderPng(string $payload): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(360, 4),
            new ImagickImageBackEnd('png'),
        );

        return (new Writer($renderer))->writeString($payload);
    }

    public function decodePngFile(string $path): string
    {
        if (! file_exists($path)) {
            throw new RuntimeException("QR artifact [{$path}] does not exist.");
        }

        $process = new Process(['zbarimg', '--quiet', '--raw', $path]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Unable to decode QR artifact: '.$process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    public function decodePngBytes(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'aes-qr-');

        if ($path === false) {
            throw new RuntimeException('Unable to create temporary QR decode file.');
        }

        $pngPath = $path.'.png';
        rename($path, $pngPath);
        file_put_contents($pngPath, $contents);

        try {
            return $this->decodePngFile($pngPath);
        } finally {
            @unlink($pngPath);
        }
    }
}
