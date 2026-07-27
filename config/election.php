<?php

$reviewChairpersonCode = (string) env('ELECTION_REVIEW_CHAIRPERSON_CODE', 'SIM-OFFICER-001');
$reviewPollClerkCode = (string) env('ELECTION_REVIEW_POLL_CLERK_CODE', 'SIM-OFFICER-002');
$reviewThirdMemberCode = (string) env('ELECTION_REVIEW_THIRD_MEMBER_CODE', 'SIM-OFFICER-003');
$reviewOfficerPin = (string) env('ELECTION_REVIEW_OFFICER_PIN', '123456');

return [
    'storage' => [
        'directory' => env('ELECTION_STORAGE_DIRECTORY', 'election'),
    ],
    'runtime' => [
        'run_type' => env('ELECTION_RUN_TYPE'),
    ],
    'cloud_evidence' => [
        'enabled' => (bool) env('ELECTION_CLOUD_EVIDENCE_ENABLED', false),
        'disk' => (string) env('ELECTION_CLOUD_EVIDENCE_DISK', 'election_evidence'),
        'prefix' => (string) env('ELECTION_CLOUD_EVIDENCE_PREFIX', 'review-evidence'),
    ],
    'review' => [
        'enabled' => (bool) env('ELECTION_REVIEW_MODE', false),
        'label' => (string) env('ELECTION_REVIEW_LABEL', 'COMELEC Review Environment'),
        'access' => [
            'enabled' => (bool) env('ELECTION_REVIEW_ACCESS_ENABLED', false),
            'username' => (string) env('ELECTION_REVIEW_ACCESS_USERNAME', ''),
            'password' => (string) env('ELECTION_REVIEW_ACCESS_PASSWORD', ''),
        ],
    ],
    'voter' => [
        'authorization_ttl_seconds' => (int) env('ELECTION_VOTER_AUTHORIZATION_TTL', 300),
        'print_release_ttl_seconds' => (int) env('ELECTION_PRINT_RELEASE_TTL', 600),
        'candidate_photos_enabled' => false,
        'individual_ballot_disclosure' => false,
    ],
    'simulation' => [
        'precinct_setup' => [
            'chairperson_code' => $reviewChairpersonCode,
            'chairperson_pin' => $reviewOfficerPin,
            'poll_clerk_code' => $reviewPollClerkCode,
            'poll_clerk_pin' => $reviewOfficerPin,
            'third_member_code' => $reviewThirdMemberCode,
            'device_serial' => (string) env('ELECTION_REVIEW_DEVICE_SERIAL', 'AES-PI-39010001-001'),
            'printer_serial' => (string) env('ELECTION_REVIEW_PRINTER_SERIAL', 'AES-PRINTER-39010001-001'),
            'scanner_serial' => (string) env('ELECTION_REVIEW_SCANNER_SERIAL', 'AES-SCANNER-39010001-001'),
            'ballot_stock_start' => (int) env('ELECTION_REVIEW_BALLOT_STOCK_START', 1),
            'ballot_stock_end' => (int) env('ELECTION_REVIEW_BALLOT_STOCK_END', 1000),
            'ballot_box_id' => (string) env('ELECTION_REVIEW_BALLOT_BOX_ID', 'AES-BOX-39010001-001'),
            'custody_envelope_id' => (string) env('ELECTION_REVIEW_CUSTODY_ENVELOPE_ID', 'AES-ENV-39010001-001'),
            'seal_numbers' => (string) env('ELECTION_REVIEW_SEAL_NUMBERS', 'AES-SEAL-39010001-001,AES-SEAL-39010001-002'),
        ],
    ],
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
            'code' => $reviewChairpersonCode,
            'name' => 'Simulation Officer',
            'role' => 'Election Board Chairperson',
            'pin_hash' => hash('sha256', $reviewOfficerPin),
        ],
        [
            'code' => $reviewPollClerkCode,
            'name' => 'Simulation Poll Clerk',
            'role' => 'Poll Clerk',
            'pin_hash' => hash('sha256', $reviewOfficerPin),
        ],
        [
            'code' => $reviewThirdMemberCode,
            'name' => 'Simulation EB Member',
            'role' => 'Third Member',
            'pin_hash' => hash('sha256', $reviewOfficerPin),
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
        'operator_label' => 'Precinct Election Operations',
        'workflow' => [
            [
                'id' => 'setup',
                'label' => 'Precinct Setup',
                'description' => 'Load the precinct package and verify the local configuration.',
                'stages' => ['provision'],
            ],
            [
                'id' => 'certification',
                'label' => 'Final Testing and Sealing',
                'description' => 'Prove the device is ready, zero the test records, and seal it.',
                'stages' => ['certification'],
            ],
            [
                'id' => 'opening',
                'label' => 'Opening of Polls',
                'description' => 'Initialize the precinct and record the opening ceremony.',
                'stages' => ['open_precinct', 'open_polls'],
            ],
            [
                'id' => 'voting',
                'label' => 'Voting',
                'description' => 'Prepare and print voter ballots while polls are open.',
                'stages' => ['voting'],
            ],
            [
                'id' => 'closing',
                'label' => 'Closing of Polls',
                'description' => 'Close voting and preserve the closing evidence.',
                'stages' => ['close_polls'],
            ],
            [
                'id' => 'counting',
                'label' => 'Counting and Tally',
                'description' => 'Scan paper ballots and reconcile the precinct tally.',
                'stages' => ['counting'],
            ],
            [
                'id' => 'return',
                'label' => 'Election Return',
                'description' => 'Generate, print, and distribute the Election Return.',
                'stages' => ['election_return'],
            ],
            [
                'id' => 'handoff',
                'label' => 'Official Handoff',
                'description' => 'Prepare the official handoff, backup, and custody records.',
                'stages' => ['transmission', 'final_backup', 'custody', 'close_precinct'],
            ],
            [
                'id' => 'audit',
                'label' => 'Audit and Reconciliation',
                'description' => 'Review the evidence bundle and reconcile the completed run.',
                'stages' => ['audit'],
            ],
        ],
        'stage' => [
            'provision' => 'Precinct Package and Configuration',
            'certification' => 'Final Testing and Sealing',
            'open_precinct' => 'Precinct Initialization',
            'open_polls' => 'Opening of Polls',
            'voting' => 'Voting',
            'close_polls' => 'Closing of Polls',
            'counting' => 'Counting and Tally',
            'election_return' => 'Election Return Generation',
            'transmission' => 'Transmission or Official Handoff',
            'final_backup' => 'Final Backup',
            'custody' => 'Custody Turnover',
            'close_precinct' => 'Close Precinct',
            'audit' => 'Audit and Reconciliation',
        ],
        'ceremony' => [
            'provision' => 'Load Precinct Package and Ballot Configuration',
            'certification' => 'Final Testing and Sealing',
            'open_precinct' => 'Precinct Initialization Ceremony',
            'open_polls' => 'Opening of Polls',
            'voting' => 'Voting Session',
            'close_polls' => 'Closing of Polls',
            'counting' => 'Counting and Tally',
            'election_return' => 'Election Return Generation',
            'transmission' => 'Transmission or Official Handoff',
            'final_backup' => 'Final Backup',
            'custody' => 'Custody Turnover',
            'close_precinct' => 'Close Precinct',
            'audit' => 'Audit and Reconciliation',
        ],
        'action' => [
            'provision' => 'Activate precinct package and ballot configuration',
            'certification' => 'Run final testing and sealing checks',
            'open_precinct' => 'Initialize precinct ceremony',
            'open_polls' => 'Open polls',
            'voting' => 'Cast and finalize a simulated ballot',
            'close_polls' => 'Close polls',
            'counting' => 'Scan ballots and generate tally',
            'election_return' => 'Generate Election Return',
            'transmission' => 'Prepare transmission or official handoff package',
            'final_backup' => 'Run final backup',
            'custody' => 'Record custody turnover',
            'close_precinct' => 'Close precinct',
            'audit' => 'Review audit and reconciliation evidence',
        ],
    ],
];
