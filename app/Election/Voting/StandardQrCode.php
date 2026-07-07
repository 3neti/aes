<?php

namespace App\Election\Voting;

use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use RuntimeException;

final class StandardQrCode
{
    public function __construct(
        private readonly QrCodeDecoder $decoder,
    ) {}

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
        return $this->decoder->decodePngFile($path);
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
