<?php

namespace App\Election\Tabulation;

enum TabulationProfile: string
{
    case PaperFirst = 'paper-first';
    case DeviceTabulationWithPaperAudit = 'device-tabulation-with-paper-audit';

    public function label(): string
    {
        return match ($this) {
            self::PaperFirst => 'Paper-first',
            self::DeviceTabulationWithPaperAudit => 'VCM-style Device Tabulation with Paper Audit',
        };
    }

    public function tallySource(): string
    {
        return match ($this) {
            self::PaperFirst => 'accepted paper ballot scans',
            self::DeviceTabulationWithPaperAudit => 'sealed device VVDAT records',
        };
    }

    public function routineScanningEnabled(): bool
    {
        return $this === self::PaperFirst;
    }
}
