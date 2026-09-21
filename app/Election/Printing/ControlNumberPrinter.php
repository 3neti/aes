<?php

namespace App\Election\Printing;

interface ControlNumberPrinter
{
    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    public function print(array $release): array;
}
