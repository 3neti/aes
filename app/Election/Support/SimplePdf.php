<?php

namespace App\Election\Support;

final class SimplePdf
{
    private const PAGE_WIDTH = 612;

    private const PAGE_HEIGHT = 792;

    private const LEFT_MARGIN = 54;

    private const TOP = 744;

    private const BODY_LINE_HEIGHT = 13;

    /**
     * @param  array<int, string>  $lines
     */
    public function render(string $title, array $lines): string
    {
        $bodyLines = $this->wrapLines($lines);
        $content = collect([
            '0.96 0.95 0.92 rg',
            '0 720 612 72 re',
            'f',
            '0.10 0.10 0.10 rg',
            'BT',
            '/F1 20 Tf',
            self::LEFT_MARGIN.' '.self::TOP.' Td',
            '('.$this->escape($title).') Tj',
            '/F1 9 Tf',
            '0 -18 Td',
            '(Alternative Election System - Simulation Evidence Artifact) Tj',
            'ET',
            '0.23 0.23 0.23 RG',
            '0.8 w',
            self::LEFT_MARGIN.' 704 m',
            (self::PAGE_WIDTH - self::LEFT_MARGIN).' 704 l',
            'S',
            'BT',
            '/F2 10 Tf',
            self::LEFT_MARGIN.' 678 Td',
        ])
            ->merge(collect($bodyLines)->flatMap(fn (string $line): array => [
                '('.$this->escape($line).') Tj',
                '0 -'.self::BODY_LINE_HEIGHT.' Td',
            ]))
            ->push('ET')
            ->push('0.23 0.23 0.23 RG')
            ->push('0.5 w')
            ->push(self::LEFT_MARGIN.' 54 m')
            ->push((self::PAGE_WIDTH - self::LEFT_MARGIN).' 54 l')
            ->push('S')
            ->push('BT')
            ->push('/F1 8 Tf')
            ->push(self::LEFT_MARGIN.' 36 Td')
            ->push('(Paper ballots remain the legal source of truth. Device artifacts are supporting evidence.) Tj')
            ->push('ET')
            ->implode("\n");

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::PAGE_WIDTH.' '.self::PAGE_HEIGHT.'] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
            '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $number = $index + 1;
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT).' 00000 n '.PHP_EOL;
        }

        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }

    private function escape(string $value): string
    {
        return str_replace(
            ['\\', '(', ')', "\r", "\n"],
            ['\\\\', '\(', '\)', ' ', ' '],
            $value,
        );
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function wrapLines(array $lines): array
    {
        return collect($lines)
            ->flatMap(function (string $line): array {
                $chunks = str_split($line, 86);

                return $chunks === [] ? [''] : $chunks;
            })
            ->take(44)
            ->values()
            ->all();
    }
}
