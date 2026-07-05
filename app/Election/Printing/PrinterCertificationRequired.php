<?php

namespace App\Election\Printing;

use RuntimeException;

final class PrinterCertificationRequired extends RuntimeException
{
    public static function forCupsPrinter(string $printerName): self
    {
        return new self("CUPS printer [{$printerName}] requires a passing device certification before ballot submission.");
    }
}
