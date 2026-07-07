<?php

namespace App\Election\Preparation;

interface PopSourceAdapter
{
    public function supports(string $path): bool;

    public function extract(string $path, string $sourceLabel): PopSourceData;
}
