<?php

namespace App\Election\Voting;

use DOMDocument;
use RuntimeException;

final class SimulationQrCode
{
    public function render(string $payload, string $label): string
    {
        $moduleSize = 8;
        $modules = 37;
        $hash = hash('sha256', $payload.$label, true);
        $bits = '';

        foreach (str_split($hash) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $rects = [];
        $bitIndex = 0;

        for ($row = 0; $row < $modules; $row++) {
            for ($column = 0; $column < $modules; $column++) {
                $filled = $this->isFinder($row, $column, $modules);

                if (! $filled && $row >= 4 && $column >= 4 && $row < $modules - 4 && $column < $modules - 4) {
                    $filled = $bits[$bitIndex % strlen($bits)] === '1';
                    $bitIndex++;
                }

                if ($filled) {
                    $x = $column * $moduleSize;
                    $y = $row * $moduleSize;
                    $rects[] = "<rect x=\"{$x}\" y=\"{$y}\" width=\"{$moduleSize}\" height=\"{$moduleSize}\"/>";
                }
            }
        }

        $size = $modules * $moduleSize;
        $rectMarkup = implode(PHP_EOL.'        ', $rects);
        $escapedLabel = htmlspecialchars($label, ENT_QUOTES | ENT_XML1);
        $escapedPayload = htmlspecialchars($payload, ENT_NOQUOTES | ENT_XML1);

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="{$size}" height="{$size}" viewBox="0 0 {$size} {$size}" role="img" aria-label="{$escapedLabel}" data-codec="aes-simulation-qr">
            <title>{$escapedLabel}</title>
            <metadata><aes-payload>{$escapedPayload}</aes-payload></metadata>
            <rect width="100%" height="100%" fill="#ffffff"/>
            <g fill="#111111">
                {$rectMarkup}
            </g>
        </svg>
        SVG;
    }

    public function decode(string $svg): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('QR artifact is not valid SVG XML.');
        }

        $nodes = $document->getElementsByTagName('aes-payload');

        if ($nodes->length < 1) {
            throw new RuntimeException('QR artifact does not contain an AES payload.');
        }

        return (string) $nodes->item(0)?->textContent;
    }

    private function isFinder(int $row, int $column, int $modules): bool
    {
        return $this->inFinder($row, $column, 4, 4)
            || $this->inFinder($row, $column, 4, $modules - 11)
            || $this->inFinder($row, $column, $modules - 11, 4);
    }

    private function inFinder(int $row, int $column, int $top, int $left): bool
    {
        $inside = $row >= $top && $row < $top + 7 && $column >= $left && $column < $left + 7;

        if (! $inside) {
            return false;
        }

        $localRow = $row - $top;
        $localColumn = $column - $left;

        return $localRow === 0
            || $localRow === 6
            || $localColumn === 0
            || $localColumn === 6
            || ($localRow >= 2 && $localRow <= 4 && $localColumn >= 2 && $localColumn <= 4);
    }
}
