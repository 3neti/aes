<?php

namespace App\Election\Truth;

final class TruthQrPrintGeometry
{
    public const Inches = 72.0;

    public const DefaultQrSizeInches = 3.0;

    public const DefaultQrSizePoints = self::DefaultQrSizeInches * self::Inches;

    public const DefaultQuietZonePoints = 8.0;

    /**
     * @return array{size_points: float, quiet_zone_points: float}
     */
    public function defaultBlock(): array
    {
        return [
            'size_points' => self::DefaultQrSizePoints,
            'quiet_zone_points' => self::DefaultQuietZonePoints,
        ];
    }

    /**
     * @return array<int, array{x: float, y: float, size_points: float}>
     */
    public function grid(float $left, float $top, int $columns, int $count, float $gap = 18.0): array
    {
        $size = self::DefaultQrSizePoints;

        return collect(range(0, max(0, $count - 1)))
            ->map(fn (int $index): array => [
                'x' => $left + (($index % $columns) * ($size + $gap)),
                'y' => $top - (intdiv($index, $columns) * ($size + $gap)) - $size,
                'size_points' => $size,
            ])
            ->all();
    }
}
