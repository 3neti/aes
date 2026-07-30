<?php

namespace App\Election\Printing;

enum PrintFormProfile: string
{
    case A4 = 'a4';
    case Thermal80 = 'thermal-80';
    case Thermal58 = 'thermal-58';

    public function label(): string
    {
        return match ($this) {
            self::A4 => 'A4 evidence copy',
            self::Thermal80 => '80 mm thermal roll',
            self::Thermal58 => '58 mm thermal roll',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::A4 => 'Full-page review, posting, and evidence-copy layout.',
            self::Thermal80 => 'Wide receipt layout for precinct thermal printers.',
            self::Thermal58 => 'Compact receipt layout for constrained thermal printers.',
        };
    }

    public function widthMillimetres(): int
    {
        return match ($this) {
            self::A4 => 210,
            self::Thermal80 => 80,
            self::Thermal58 => 58,
        };
    }

    public function isThermal(): bool
    {
        return $this !== self::A4;
    }
}
