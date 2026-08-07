<?php

namespace App\Election\Printing\Documents;

use App\Election\Printing\PrintFormProfile;
use Imagick;
use RuntimeException;

final class ThermalPdfDocument
{
    private const PageHeight = 792;

    /** @var array<int, array{section: string, commands: array<int, string>}> */
    private array $pages = [];

    /** @var array<string, array{width: int, height: int, data: string}> */
    private array $images = [];

    public function __construct(
        private readonly string $title,
        private readonly string $documentCode,
        private readonly string $precinctId,
        private readonly PrintFormProfile $profile,
        private readonly string $subtitle = 'Alternative Election System - Simulation Evidence Artifact',
    ) {}

    public function width(): float
    {
        return $this->profile === PrintFormProfile::Thermal58 ? 164.41 : 226.77;
    }

    public function left(): float
    {
        return 10;
    }

    public function right(): float
    {
        return $this->width() - 10;
    }

    public function contentTop(): float
    {
        return 710;
    }

    public function contentBottom(): float
    {
        return 40;
    }

    public function addPage(string $section = ''): int
    {
        $this->pages[] = ['section' => $section, 'commands' => []];

        return count($this->pages) - 1;
    }

    public function text(int $page, string $text, float $x, float $y, float $size = 8, bool $bold = false, string $align = 'left', bool $monospace = false): void
    {
        $font = $monospace ? 'F3' : ($bold ? 'F2' : 'F1');
        $position = match ($align) {
            'center' => $x - ($this->textWidth($text, $size, $monospace) / 2),
            'right' => $x - $this->textWidth($text, $size, $monospace),
            default => $x,
        };
        $this->command($page, sprintf("0.08 0.08 0.08 rg\nBT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET", $font, $size, $position, $y, $this->encode($text)));
    }

    /** @return array<int, string> */
    public function wrap(string $text, float $width, float $size = 8, bool $monospace = false): array
    {
        $characters = max(1, (int) floor($width / ($size * ($monospace ? 0.60 : 0.52))));
        $words = preg_split('/\s+/u', trim(preg_replace('/\s+/u', ' ', $text) ?? $text)) ?: [];
        $lines = [];
        $line = '';

        foreach ($words as $word) {
            foreach (mb_str_split($word, $characters) as $index => $chunk) {
                $candidate = $line === '' ? $chunk : $line.' '.$chunk;

                if (mb_strlen($candidate) <= $characters) {
                    $line = $candidate;

                    continue;
                }

                if ($line !== '') {
                    $lines[] = $line;
                }
                $line = $chunk;

                if ($index < count(mb_str_split($word, $characters)) - 1) {
                    $lines[] = $line;
                    $line = '';
                }
            }
        }

        return [...$lines, ...($line === '' ? [] : [$line])];
    }

    public function wrappedText(int $page, string $text, float $x, float $y, float $width, float $size = 8, float $leading = 10, bool $bold = false, bool $monospace = false): float
    {
        foreach ($this->wrap($text, $width, $size, $monospace) as $line) {
            $this->text($page, $line, $x, $y, $size, $bold, monospace: $monospace);
            $y -= $leading;
        }

        return $y;
    }

    public function line(int $page, float $x1, float $y1, float $x2, float $y2, float $width = 0.7, float $gray = 0.25): void
    {
        $this->command($page, sprintf('%.2F %.2F %.2F RG %.2F w %.2F %.2F m %.2F %.2F l S', $gray, $gray, $gray, $width, $x1, $y1, $x2, $y2));
    }

    public function rectangle(int $page, float $x, float $y, float $width, float $height, float $gray = 0.92, bool $fill = true): void
    {
        $this->command($page, sprintf('%.2F %.2F %.2F %s %.2F %.2F %.2F %.2F re %s', $gray, $gray, $gray, $fill ? 'rg' : 'RG', $x, $y, $width, $height, $fill ? 'f' : 'S'));
    }

    public function tallyMarkHeight(int $count, float $width): float
    {
        $groups = intdiv(max(0, $count), 5) + (max(0, $count) % 5 > 0 ? 1 : 0);

        return $groups === 0 ? 0 : (ceil($groups / max(1, (int) floor($width / 11))) * 10) + 4;
    }

    public function tallyMarks(int $page, int $count, float $x, float $y, float $width, float $strokeWidth = 0.7): void
    {
        $count = max(0, $count);
        $fullGroups = intdiv($count, 5);
        $remainder = $count % 5;
        $groups = $fullGroups + ($remainder > 0 ? 1 : 0);

        if ($groups === 0) {
            return;
        }

        $perLine = max(1, (int) floor($width / 11));
        $this->command($page, sprintf('%% AES-TALLY-MARKS count=%d groups=%d remainder=%d', $count, $fullGroups, $remainder));

        for ($group = 0; $group < $groups; $group++) {
            $groupX = $x + (($group % $perLine) * 11);
            $centerY = $y - 8 - (intdiv($group, $perLine) * 10);
            $marks = $group < $fullGroups ? 4 : $remainder;

            for ($mark = 0; $mark < $marks; $mark++) {
                $markX = $groupX + ($mark * 2.25);
                $this->line($page, $markX, $centerY - 4, $markX, $centerY + 4, $strokeWidth, 0.08);
            }

            if ($group < $fullGroups) {
                $this->line($page, $groupX - 1, $centerY + 4, $groupX + 7.75, $centerY - 4, $strokeWidth, 0.08);
            }
        }
    }

    public function registerPng(string $name, string $path): void
    {
        if (! is_file($path)) {
            throw new RuntimeException("PDF image [{$path}] does not exist.");
        }

        $image = new Imagick($path);
        $image->setImageBackgroundColor('white');
        $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $image->setImageColorspace(Imagick::COLORSPACE_GRAY);
        $image->setImageDepth(8);
        $image->setImageFormat('gray');
        $data = gzcompress($image->getImageBlob(), 9);
        if ($data === false) {
            throw new RuntimeException("Unable to compress PDF image [{$path}].");
        }
        $this->images[$name] = ['width' => $image->getImageWidth(), 'height' => $image->getImageHeight(), 'data' => $data];
        $image->clear();
        $image->destroy();
    }

    public function image(int $page, string $name, float $x, float $y, float $width, float $height): void
    {
        if (! isset($this->images[$name])) {
            throw new RuntimeException("PDF image [{$name}] is not registered.");
        }
        $this->command($page, sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q', $width, $height, $x, $y, $name));
    }

    public function render(): string
    {
        if ($this->pages === []) {
            $this->addPage();
        }
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>', 4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>', 5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>'];
        $next = 6;
        $imageObjects = [];
        foreach ($this->images as $name => $image) {
            $imageObjects[$name] = $next;
            $objects[$next++] = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Interpolate false /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream", $image['width'], $image['height'], strlen($image['data']), $image['data']);
        }
        $pageObjects = [];
        $pages = count($this->pages);
        $xObjects = collect($imageObjects)->map(fn (int $object, string $name): string => "/{$name} {$object} 0 R")->implode(' ');
        foreach ($this->pages as $index => $page) {
            $pageObject = $next++;
            $contentObject = $next++;
            $pageObjects[] = $pageObject;
            $resources = '<< /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >>'.($xObjects === '' ? '' : " /XObject << {$xObjects} >>").' >>';
            $objects[$pageObject] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %d] /Resources %s /Contents %d 0 R >>', $this->width(), self::PageHeight, $resources, $contentObject);
            $content = implode("\n", [$this->header($page['section']), ...$page['commands'], $this->footer($index + 1, $pages)]);
            $objects[$contentObject] = '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream";
        }
        $kids = collect($pageObjects)->map(fn (int $object): string => "{$object} 0 R")->implode(' ');
        $objects[2] = "<< /Type /Pages /Kids [{$kids}] /Count {$pages} >>";
        ksort($objects);

        return $this->serialize($objects);
    }

    private function header(string $section): string
    {
        $width = $this->width();
        $title = $this->encode($this->title);
        $section = $this->encode($section);

        return implode("\n", [
            sprintf('0.12 0.26 0.56 rg 0 %.2F %.2F 4 re f', self::PageHeight - 4, $width),
            sprintf('BT /F2 5.8 Tf 10 772 Td (REPUBLIC OF THE PHILIPPINES) Tj ET'),
            sprintf('BT /F2 7 Tf 10 759 Td (COMMISSION ON ELECTIONS) Tj ET'),
            sprintf('BT /F2 10 Tf 10 742 Td (%s) Tj ET', $title),
            sprintf('BT /F1 5.8 Tf 10 731 Td (%s) Tj ET', $this->encode($this->profile->label())),
            $section === '' ? '' : sprintf('BT /F2 5.5 Tf 10 719 Td (%s) Tj ET', $section),
            sprintf('0.25 0.25 0.25 RG 0.6 w 10 714 m %.2F 714 l S', $this->right()),
        ]);
    }

    private function footer(int $page, int $pages): string
    {
        $left = $this->encode('Paper ballot remains controlling evidence.');
        $right = $this->encode("Roll segment {$page} of {$pages}");

        return implode("\n", [
            sprintf('0.45 0.45 0.45 RG 0.45 w 10 29 m %.2F 29 l S', $this->right()),
            sprintf('0.30 0.30 0.30 rg BT /F1 4.8 Tf 10 19 Td (%s) Tj ET', $left),
            sprintf('0.30 0.30 0.30 rg BT /F1 4.8 Tf %.2F 19 Td (%s) Tj ET', $this->right() - $this->textWidth($right, 4.8), $right),
            sprintf('0.30 0.30 0.30 rg BT /F1 4.6 Tf 10 10 Td (%s) Tj ET', $this->encode("Precinct {$this->precinctId} | ".mb_substr($this->documentCode, 0, 16))),
        ]);
    }

    private function command(int $page, string $command): void
    {
        if (! isset($this->pages[$page])) {
            throw new RuntimeException("PDF page [{$page}] does not exist.");
        }
        $this->pages[$page]['commands'][] = $command;
    }

    private function textWidth(string $text, float $size, bool $monospace = false): float
    {
        return mb_strlen($text) * $size * ($monospace ? 0.60 : 0.49);
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
