<?php

use App\Election\Printing\Documents\ControlNumberReceiptPdf;
use Symfony\Component\Process\Process as SystemProcess;

test('control number receipt preserves leading zeros and centers content inside the thermal margins', function (string $controlNumber): void {
    $pdf = app(ControlNumberReceiptPdf::class)->render([
        'release_code' => $controlNumber,
        'printed_at' => '2026-11-17T06:38:43+00:00',
    ], [
        'precinct_id' => '39010402',
        'city_municipality' => 'CITY OF MANILA',
    ]);
    $path = tempnam(sys_get_temp_dir(), 'aes-receipt-layout-');

    expect($path)->not->toBeFalse();
    file_put_contents($path, $pdf);

    try {
        $process = new SystemProcess([
            (string) config('election.pdf.ghostscript_binary', 'gs'),
            '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER',
            '-sDEVICE=txtwrite', '-dTextFormat=0', '-o', '-', $path,
        ]);
        $process->mustRun();
        $document = simplexml_load_string('<document>'.$process->getOutput().'</document>');
        $spans = $document->xpath('//span[@font="Courier-Bold"]');

        expect($spans)->toHaveCount(1);
        $number = $spans[0];
        $characters = array_map(fn (SimpleXMLElement $character): string => (string) $character['c'], iterator_to_array($number->char, false));
        $bounds = array_map('floatval', preg_split('/\s+/', trim((string) $number['bbox'])));

        expect(implode('', $characters))->toBe($controlNumber)
            ->and(abs((($bounds[0] + $bounds[2]) / 2) - 82))->toBeLessThanOrEqual(1)
            ->and($bounds[0])->toBeGreaterThanOrEqual(20)
            ->and($bounds[2])->toBeLessThanOrEqual(144)
            ->and(abs((float) $number['size'] - min(84, 76 / (strlen($controlNumber) * 0.60))))->toBeLessThan(0.02)
            ->and($pdf)->toMatch('/\/MediaBox\s*\[\s*0\s+0\s+164(?:\.0+)?\s+120(?:\.0+)?\s*\]/');

        foreach ($document->xpath('//span') as $span) {
            $spanBounds = array_map('floatval', preg_split('/\s+/', trim((string) $span['bbox'])));
            expect(abs((($spanBounds[0] + $spanBounds[2]) / 2) - 82))->toBeLessThanOrEqual(1);
        }
    } finally {
        @unlink($path);
    }
})->with(['0129', '0001', '1234567890']);
