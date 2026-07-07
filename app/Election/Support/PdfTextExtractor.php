<?php

namespace App\Election\Support;

interface PdfTextExtractor
{
    /**
     * @return array<int, PdfPageText>
     */
    public function extract(string $path): array;
}
