<?php

namespace App\Election\Printing\Documents;

use Carbon\CarbonImmutable;

final class ControlNumberReceiptPdf
{
    private const PageWidth = 226.77;

    private const PageHeight = 180;

    /**
     * Standard AFM widths (1000 units/em) for Helvetica, WinAnsiEncoding
     * printable ASCII range (space through tilde, codes 32-126). CUPS's
     * PDF-to-PostScript filter chain has been observed to garble text drawn
     * with a Type1 font reference that omits /Widths and /FontDescriptor,
     * even though such fonts are technically optional per the PDF spec for
     * the 14 standard fonts. Declaring them explicitly avoids that.
     *
     * @var list<int>
     */
    private const HelveticaWidths = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

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
        $courierBoldWidths = '['.implode(' ', array_fill(0, 10, 600)).']';
        $helveticaWidths = '['.implode(' ', self::HelveticaWidths).']';
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %d] /Resources << /Font << /F1 4 0 R /F2 6 0 R >> >> /Contents 5 0 R >>', self::PageWidth, self::PageHeight),
            4 => "<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding /FirstChar 48 /LastChar 57 /Widths {$courierBoldWidths} /FontDescriptor 7 0 R >>",
            5 => '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream",
            6 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding /FirstChar 32 /LastChar 126 /Widths {$helveticaWidths} /FontDescriptor 8 0 R >>",
            7 => '<< /Type /FontDescriptor /FontName /Courier-Bold /Flags 33 /FontBBox [-113 -250 749 801] /ItalicAngle 0 /Ascent 629 /Descent -157 /CapHeight 562 /StemV 106 /MissingWidth 600 >>',
            8 => '<< /Type /FontDescriptor /FontName /Helvetica /Flags 32 /FontBBox [-166 -225 1000 931] /ItalicAngle 0 /Ascent 718 /Descent -207 /CapHeight 718 /StemV 88 /MissingWidth 278 >>',
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
