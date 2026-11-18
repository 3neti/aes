<?php

test('2025 COMELEC candidate source catalog covers national lists and every regional index', function (): void {
    $catalog = json_decode(
        (string) file_get_contents(resource_path('election/clc/2025-national-source-catalog.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $regions = array_column($catalog['regions'], 'code');
    $urls = array_column($catalog['sources'], 'url');

    expect($catalog['schema_version'])->toBe('comelec-2025-clc-source-catalog-1')
        ->and($catalog['pdf_count'])->toBe(1730)
        ->and($catalog['sources'])->toHaveCount(1730)
        ->and($regions)->toHaveCount(18)
        ->and($regions)->toContain('NCR', 'CAR', 'NIR', 'R1', 'R4A', 'R4B', 'R13', 'BARMM')
        ->and(count($urls))->toBe(count(array_unique($urls)))
        ->and(array_sum(array_column($catalog['regions'], 'pdf_count')))->toBe(1728)
        ->and(collect($catalog['sources'])->where('region', 'NATIONAL'))->toHaveCount(2);

    foreach ($urls as $url) {
        expect($url)->toStartWith('https://www.comelec.gov.ph/php-tpls-attachments/2025NLE/COC_2025NLE/CLC2025_')
            ->toEndWith('.pdf');
    }
});
