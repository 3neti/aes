<?php

return [
    'sample' => [
        'election_id' => 'AES-2026-SAMPLE',
        'precinct_id' => '0421-A',
        'ballot_style_id' => 'BS-0421-A',
    ],
    'devices' => [
        'printer' => [
            'adapter' => env('ELECTION_PRINTER_ADAPTER', 'simulated'),
            'driver' => env('ELECTION_PRINTER_DRIVER', 'file'),
            'cups' => [
                'name' => env('ELECTION_CUPS_PRINTER', ''),
                'timeout' => (int) env('ELECTION_CUPS_TIMEOUT', 3),
            ],
        ],
        'scanner' => [
            'adapter' => env('ELECTION_SCANNER_ADAPTER', 'simulated'),
            'driver' => env('ELECTION_SCANNER_DRIVER', 'manual'),
            'handheld' => [
                'name' => env('ELECTION_HANDHELD_SCANNER', ''),
            ],
        ],
    ],
    'dictionary' => [
        'app_name' => 'Alternative Election System',
        'stage' => [
            'provision' => 'Provision',
            'certification' => 'Certification',
            'open_precinct' => 'Open Precinct',
            'open_polls' => 'Open Polls',
            'voting' => 'Voting',
            'close_polls' => 'Close Polls',
            'counting' => 'Counting',
            'election_return' => 'Election Return',
            'close_precinct' => 'Close Precinct',
            'audit' => 'Audit',
        ],
        'ceremony' => [
            'provision' => 'Load Precinct Package',
            'certification' => 'Friday Certification',
            'open_precinct' => 'Open Precinct Ceremony',
            'open_polls' => 'Open Polls',
            'voting' => 'Voting Session',
            'close_polls' => 'Close Polls',
            'counting' => 'Official Counting',
            'election_return' => 'Generate Election Return',
            'close_precinct' => 'Close Precinct',
            'audit' => 'Audit Review',
        ],
        'action' => [
            'provision' => 'Activate sample precinct package',
            'certification' => 'Run Friday certification',
            'open_precinct' => 'Open precinct',
            'open_polls' => 'Open polls',
            'voting' => 'Cast and finalize a simulated ballot',
            'close_polls' => 'Close polls',
            'counting' => 'Scan and count ballot payloads',
            'election_return' => 'Generate Election Return',
            'close_precinct' => 'Close precinct',
            'audit' => 'Review journal and artifacts',
        ],
    ],
];
