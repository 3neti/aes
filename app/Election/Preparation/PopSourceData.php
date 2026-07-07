<?php

namespace App\Election\Preparation;

final readonly class PopSourceData
{
    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    public function __construct(
        public string $sourceType,
        public string $sourceLabel,
        public array $headers,
        public array $rows,
        public string $originalPath,
        public string $filename,
        public int $bytes,
        public string $sha256,
    ) {}
}
