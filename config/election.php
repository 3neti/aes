<?php

return [
    'sample' => [
        'election_id' => 'AES-2026-SAMPLE',
        'precinct_id' => '0421-A',
        'ballot_style_id' => 'BS-0421-A',
    ],
    'pop' => [
        'source_path' => env('ELECTION_POP_SOURCE_PATH', resource_path('election/pop/2025NLE_POP.xlsx')),
        'profile' => env('ELECTION_POP_PROFILE', 'comelec-pop-2025-nle'),
        'clustered_precinct' => env('ELECTION_POP_CLUSTERED_PRECINCT', '39010001'),
        'district' => env('ELECTION_POP_DISTRICT', 'FIRST DIST'),
        'contest_rules' => [
            'SENATOR' => 12,
            'PARTY LIST' => 1,
            'MEMBER, HOUSE OF REPRESENTATIVES' => 1,
            'MAYOR' => 1,
            'VICE-MAYOR' => 1,
            'COUNCILOR' => 6,
        ],
    ],
    'pdf' => [
        'ghostscript_binary' => env('ELECTION_PDF_GHOSTSCRIPT_BINARY', 'gs'),
    ],
    'clc' => [
        'source_path' => env('ELECTION_CLC_SOURCE_PATH', resource_path('election/clc')),
        'profile' => env('ELECTION_CLC_PROFILE', 'comelec-clc-2025-nle'),
        'registry_version' => env('ELECTION_CLC_REGISTRY_VERSION', 'clc-2025-nle'),
        'precinct_aliases' => [
            'BINONDO' => 'CITY OF MANILA',
            'ERMITA' => 'CITY OF MANILA',
            'INTRAMUROS' => 'CITY OF MANILA',
            'MALATE' => 'CITY OF MANILA',
            'PACO' => 'CITY OF MANILA',
            'PANDACAN' => 'CITY OF MANILA',
            'PORT AREA' => 'CITY OF MANILA',
            'QUIAPO' => 'CITY OF MANILA',
            'SAMPALOC' => 'CITY OF MANILA',
            'SAN MIGUEL' => 'CITY OF MANILA',
            'SAN NICOLAS' => 'CITY OF MANILA',
            'SANTA ANA' => 'CITY OF MANILA',
            'SANTA CRUZ' => 'CITY OF MANILA',
            'TONDO' => 'CITY OF MANILA',
        ],
    ],
    'officers' => [
        [
            'code' => 'SIM-OFFICER-001',
            'name' => 'Simulation Officer',
            'role' => 'Election Board Chairperson',
            'pin_hash' => '8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92',
        ],
        [
            'code' => 'SIM-OFFICER-002',
            'name' => 'Simulation Poll Clerk',
            'role' => 'Poll Clerk',
            'pin_hash' => '8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92',
        ],
        [
            'code' => 'SIM-OFFICER-003',
            'name' => 'Simulation EB Member',
            'role' => 'Third Member',
            'pin_hash' => '8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92',
        ],
    ],
    'electoral_board_roles' => [
        'required' => [
            [
                'code' => 'chairperson',
                'name' => 'Election Board Chairperson',
                'officer_roles' => ['Election Board Chairperson'],
            ],
            [
                'code' => 'poll_clerk',
                'name' => 'Poll Clerk',
                'officer_roles' => ['Poll Clerk'],
            ],
            [
                'code' => 'third_member',
                'name' => 'Third Member',
                'officer_roles' => ['Third Member'],
            ],
        ],
        'optional' => [
            [
                'code' => 'support_staff',
                'name' => 'Electoral Board Support Staff',
                'officer_roles' => ['Support Staff'],
            ],
        ],
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
            'camera' => [
                'name' => env('ELECTION_CAMERA_SCANNER', ''),
            ],
        ],
    ],
    'removable_media' => [
        'path' => env('ELECTION_REMOVABLE_MEDIA_PATH', ''),
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
            'transmission' => 'Transmission',
            'final_backup' => 'Final Backup',
            'custody' => 'Custody',
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
            'transmission' => 'Official Handoff',
            'final_backup' => 'Final Backup',
            'custody' => 'Custody Transfer',
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
            'transmission' => 'Prepare transmission package',
            'final_backup' => 'Run final backup',
            'custody' => 'Record custody report',
            'close_precinct' => 'Close precinct',
            'audit' => 'Review journal and artifacts',
        ],
    ],
];
