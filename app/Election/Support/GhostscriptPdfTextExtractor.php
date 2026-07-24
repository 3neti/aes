<?php

namespace App\Election\Support;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class GhostscriptPdfTextExtractor implements PdfTextExtractor
{
    /**
     * @return array<int, PdfPageText>
     */
    public function extract(string $path): array
    {
        $configuredBinary = (string) config('election.pdf.ghostscript_binary', 'gs');
        $binary = $this->resolveBinary($configuredBinary);
        $process = new Process([
            $binary,
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-sDEVICE=txtwrite',
            '-o',
            '-',
            $path,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("Unable to extract PDF text with Ghostscript [{$configuredBinary}]: ".$process->getErrorOutput());
        }

        return $this->pages($process->getOutput());
    }

    private function resolveBinary(string $configuredBinary): string
    {
        if (str_contains($configuredBinary, DIRECTORY_SEPARATOR)) {
            return $configuredBinary;
        }

        return (new ExecutableFinder)->find(
            $configuredBinary,
            $configuredBinary,
            [
                dirname(PHP_BINARY),
                '/opt/homebrew/bin',
                '/usr/local/bin',
                '/usr/bin',
            ],
        );
    }

    /**
     * @return array<int, PdfPageText>
     */
    private function pages(string $text): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        preg_match_all('/(.*?Page\s+(\d+)\s+of\s+\d+\s*\n\s*[a-f0-9]{32}\s*)/is', $normalized, $matches, PREG_SET_ORDER);

        if ($matches === []) {
            return [new PdfPageText(1, trim($normalized))];
        }

        return collect($matches)
            ->map(fn (array $match): PdfPageText => new PdfPageText((int) $match[2], trim((string) $match[1])))
            ->values()
            ->all();
    }
}
