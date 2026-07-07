<?php

namespace App\Election\Support;

final readonly class PdfPageText
{
    public function __construct(
        public int $page,
        public string $text,
    ) {}
}
