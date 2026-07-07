<?php

namespace App\Election\Voting;

interface QrCodeDecoder
{
    public function decodePngFile(string $path): string;
}
