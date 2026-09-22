<?php

namespace App\Election\Printing\Documents;

use Carbon\CarbonImmutable;

final class ControlNumberReceiptPdf
{
    private const PageWidth = 226.77;

    private const PageHeight = 180;

    /**
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $configuration
     */
    public function render(array $release, array $configuration): string
    {
        $controlNumber = (string) ($release['release_code'] ?? '');
        $fontSize = $this->fontSize($controlNumber);
        $x = (self::PageWidth - $this->textWidth($controlNumber, $fontSize)) / 2;
        $y = ((self::PageHeight - $fontSize) / 2) + 11;
        [$precinctLine, $timeLine] = $this->finePrint($release, $configuration);
        $finePrintSize = 5.8;
        $precinctLineX = (self::PageWidth - $this->textWidth($precinctLine, $finePrintSize, false)) / 2;
        $timeLineX = (self::PageWidth - $this->textWidth($timeLine, $finePrintSize, false)) / 2;
        $content = sprintf(
            "0.05 0.05 0.05 rg\nBT /F1 %.2F Tf %.2F %.2F Td (%s) Tj ET\n0.42 0.42 0.42 rg\nBT /F2 %.2F Tf %.2F 22.00 Td (%s) Tj ET\nBT /F2 %.2F Tf %.2F 13.00 Td (%s) Tj ET",
            $fontSize,
            max(0, $x),
            $y,
            $this->encode($controlNumber),
            $finePrintSize,
            max(6, $precinctLineX),
            $this->encode($precinctLine),
            $finePrintSize,
            max(6, $timeLineX),
            $this->encode($timeLine),
        );
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %d] /Resources << /Font << /F1 4 0 R /F2 6 0 R >> >> /Contents 5 0 R >>', self::PageWidth, self::PageHeight),
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>',
            5 => '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream",
            6 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

        return $this->serialize($objects);
    }

    private function fontSize(string $controlNumber): float
    {
        $digits = max(1, strlen($controlNumber));

        return min(84, (self::PageWidth - 24) / ($digits * 0.60));
    }

    private function textWidth(string $text, float $size, bool $monospace = true): float
    {
        return strlen($text) * $size * ($monospace ? 0.60 : 0.49);
    }

    /**
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $configuration
     * @return array{0: string, 1: string}
     */
    private function finePrint(array $release, array $configuration): array
    {
        $precinctId = (string) ($configuration['precinct_id'] ?? 'unknown');
        $cityMunicipality = (string) ($configuration['city_municipality'] ?? $configuration['location']['city_municipality'] ?? 'unknown');
        $timestamp = (string) ($release['printed_at'] ?? $release['created_at'] ?? $release['expires_at'] ?? now()->toIso8601String());
        $printedAt = CarbonImmutable::parse($timestamp)
            ->setTimezone(date_default_timezone_get())
            ->format('d Hi\H M Y');

        return ["Precinct {$precinctId} | {$cityMunicipality}", $printedAt];
    }

    private function encode(string $value): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $value) ?: $value;

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $encoded);
    }

    /** @param non-empty-array<int, string> $objects */
    private function serialize(array $objects): string
    {
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $size = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($number = 1; $number < $size; $number++) {
            $pdf .= str_pad((string) ($offsets[$number] ?? 0), 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        return $pdf."trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
