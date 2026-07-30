<?php

namespace App\Election\Printing;

interface BallotPrinter
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function print(array $payload, ?PrintFormProfile $profile = null): array;
}
