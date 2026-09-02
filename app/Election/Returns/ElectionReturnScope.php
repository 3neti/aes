<?php

namespace App\Election\Returns;

enum ElectionReturnScope: string
{
    case Combined = 'combined';
    case National = 'national';
    case Local = 'local';

    public function label(): string
    {
        return match ($this) {
            self::Combined => 'Combined Election Return',
            self::National => 'National Election Return',
            self::Local => 'Local Election Return',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Combined => 'Election Returns for National and Local Positions',
            self::National => 'Election Returns for National Positions',
            self::Local => 'Election Returns for Local Positions',
        };
    }

    public function filenameSuffix(): string
    {
        return match ($this) {
            self::Combined => 'combined-election-return',
            self::National => 'national-election-return',
            self::Local => 'local-election-return',
        };
    }

    /** @return array<int, self> */
    public static function splitScopes(): array
    {
        return [self::National, self::Local, self::Combined];
    }
}
