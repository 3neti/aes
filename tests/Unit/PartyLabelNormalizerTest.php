<?php

use App\Election\Support\PartyLabelNormalizer;

test('party labels strip imported comelec boilerplate while preserving party names', function (?string $raw, ?string $expected): void {
    expect(app(PartyLabelNormalizer::class)->normalize($raw))->toBe($expected);
})->with([
    'empty' => ['', null],
    'null' => [null, null],
    'full party name' => ['PARTIDO FEDERAL NG PILIPINAS', 'PARTIDO FEDERAL NG PILIPINAS'],
    'independent with privacy notice' => [
        'INDEPENDENT pertinent documents attached thereto that are shared by the Commission on Elections in compliance with the existing laws and rules',
        'INDEPENDENT',
    ],
    'party with privacy notice' => [
        'PARTIDO PILIPINO SA PAGBABAGO pertinent documents attached thereto that are shared by the Commission on Elections',
        'PARTIDO PILIPINO SA PAGBABAGO',
    ],
]);
