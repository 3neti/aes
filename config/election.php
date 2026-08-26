<?php

$reviewChairpersonCode = (string) env('ELECTION_REVIEW_CHAIRPERSON_CODE', 'SIM-OFFICER-001');
$reviewPollClerkCode = (string) env('ELECTION_REVIEW_POLL_CLERK_CODE', 'SIM-OFFICER-002');
$reviewThirdMemberCode = (string) env('ELECTION_REVIEW_THIRD_MEMBER_CODE', 'SIM-OFFICER-003');
$reviewOfficerPin = (string) env('ELECTION_REVIEW_OFFICER_PIN', '123456');

return [
    'storage' => [
        'directory' => env('ELECTION_STORAGE_DIRECTORY', 'election'),
    ],
    'branding' => [
        'print_colored' => (bool) env('ELECTION_BALLOT_PRINT_COLORED', true),
        'comelec_logo' => (string) env('ELECTION_COMELEC_LOGO_PATH', resource_path('election/branding/comelec.png')),
        'bagong_pilipinas_logo' => (string) env('ELECTION_BAGONG_PILIPINAS_LOGO_PATH', resource_path('election/branding/bagong-pilipinas.png')),
        'republic_seal' => (string) env('ELECTION_REPUBLIC_SEAL_PATH', resource_path('election/branding/republic-seal.jpg')),
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
            'demo_credentials' => [
                'enabled' => (bool) env('ELECTION_REVIEW_DEMO_CREDENTIALS_ENABLED', (bool) env('ELECTION_REVIEW_MODE', false)),
                'username' => (string) env('ELECTION_REVIEW_DEMO_USERNAME', 'user'),
                'password' => (string) env('ELECTION_REVIEW_DEMO_PASSWORD', 'user'),
            ],
        ],
    ],
    'review_room' => [
        'enabled' => (bool) env('ELECTION_REVIEW_ROOM_ENABLED', false),
        'default_name' => (string) env('ELECTION_REVIEW_ROOM_NAME', 'COMELEC Multi-Tablet Review'),
        'default_voter_stations' => (int) env('ELECTION_REVIEW_ROOM_VOTER_STATIONS', 5),
        'max_voter_stations' => (int) env('ELECTION_REVIEW_ROOM_MAX_VOTER_STATIONS', 10),
        'join_link_ttl_minutes' => (int) env('ELECTION_REVIEW_ROOM_JOIN_TTL', 480),
        'online_window_seconds' => (int) env('ELECTION_REVIEW_ROOM_ONLINE_WINDOW', 30),
    ],
    'public_simulation' => [
        'enabled' => (bool) env('ELECTION_PUBLIC_SIMULATION_ENABLED', true),
        'default_name' => (string) env('ELECTION_PUBLIC_SIMULATION_NAME', 'Public Election Simulation'),
        'precincts' => [
            ['code' => 'TONDO-01', 'clustered_precinct' => '39010402', 'district' => 'SECOND DIST', 'label' => 'Tondo Precinct 01'],
            ['code' => 'TONDO-02', 'clustered_precinct' => '39010402', 'district' => 'SECOND DIST', 'label' => 'Tondo Precinct 02'],
            ['code' => 'TONDO-03', 'clustered_precinct' => '39010402', 'district' => 'SECOND DIST', 'label' => 'Tondo Precinct 03'],
        ],
        'god_mode' => [
            'enabled' => (bool) env('ELECTION_PUBLIC_SIMULATION_GOD_MODE_ENABLED', false),
        ],
        'maximum_active_admissions' => (int) env('ELECTION_PUBLIC_SIMULATION_MAX_ACTIVE_ADMISSIONS', 10),
        'retention_days' => (int) env('ELECTION_PUBLIC_SIMULATION_RETENTION_DAYS', 30),
        'participation_required' => (bool) env('ELECTION_PUBLIC_SIMULATION_PARTICIPATION_REQUIRED', true),
        'admission_queue' => [
            'enabled' => (bool) env('ELECTION_PUBLIC_SIMULATION_QUEUE_ENABLED', true),
            'maximum_waiting_voters' => (int) env('ELECTION_PUBLIC_SIMULATION_QUEUE_MAXIMUM_WAITING_VOTERS', 25),
            'ticket_ttl_seconds' => (int) env('ELECTION_PUBLIC_SIMULATION_QUEUE_TICKET_TTL', 900),
        ],
        'vvdat_audit_export' => [
            'enabled' => (bool) env('ELECTION_PUBLIC_SIMULATION_VVDAT_AUDIT_EXPORT_ENABLED', true),
            'minimum_records' => (int) env('ELECTION_PUBLIC_SIMULATION_VVDAT_AUDIT_EXPORT_MINIMUM_RECORDS', 1),
        ],
        'watcher_ballot_viewer' => [
            'enabled' => (bool) env('ELECTION_WATCHER_BALLOT_VIEWER_ENABLED', true),
            'during_voting' => (bool) env('ELECTION_WATCHER_BALLOT_VIEWER_DURING_VOTING', true),
            'download_enabled' => (bool) env('ELECTION_WATCHER_BALLOT_DOWNLOAD_ENABLED', true),
            'qr_audit_tally_enabled' => (bool) env('ELECTION_WATCHER_QR_AUDIT_TALLY_ENABLED', true),
        ],
        'demo_control_number_share' => [
            'enabled' => (bool) env('ELECTION_DEMO_CONTROL_NUMBER_SHARE_ENABLED', (bool) env('ELECTION_REVIEW_MODE', false)),
        ],
        'role_demo_bulk_ballots' => [
            'enabled' => (bool) env('ELECTION_ROLE_DEMO_BULK_BALLOTS_ENABLED', true),
            'max_count' => (int) env('ELECTION_ROLE_DEMO_BULK_BALLOTS_MAX', 700),
            'chunk_size' => (int) env('ELECTION_ROLE_DEMO_BULK_BALLOT_CHUNK_SIZE', 25),
            'rendered_pdf_limit' => (int) env('ELECTION_ROLE_DEMO_BULK_BALLOT_PDF_LIMIT', 3),
            'presets' => [10, 100, 700],
        ],
    ],
    'voter' => [
        'authorization_ttl_seconds' => (int) env('ELECTION_VOTER_AUTHORIZATION_TTL', (bool) env('ELECTION_REVIEW_MODE', false) ? 14400 : 300),
        'print_release_ttl_seconds' => (int) env('ELECTION_PRINT_RELEASE_TTL', 600),
        'print_pin_digits' => (int) env('ELECTION_PRINT_PIN_DIGITS', 4),
        'ballot_ui_profile' => (string) env('ELECTION_BALLOT_UI_PROFILE', 'comelec_2022_facsimile'),
        'ballot_artifact_profile' => (string) env('ELECTION_BALLOT_ARTIFACT_PROFILE', 'selected_candidates_compact_official'),
        'paper_facsimile_max_columns' => (int) env('ELECTION_BALLOT_PAPER_FACSIMILE_MAX_COLUMNS', 4),
        'selection_target' => (string) env('ELECTION_BALLOT_SELECTION_TARGET', 'circle'),
        'demo_random_fill_enabled' => (bool) env('ELECTION_BALLOT_DEMO_RANDOM_FILL', (bool) env('ELECTION_REVIEW_MODE', false)),
        'role_demo_random_fill_enabled' => (bool) env('ELECTION_ROLE_DEMO_RANDOM_FILL', true),
        'role_demo_voter_ballot_preview_enabled' => (bool) env('ELECTION_ROLE_DEMO_VOTER_BALLOT_PREVIEW', true),
        'analytics' => [
            'enabled' => (bool) env('ELECTION_BALLOT_ANALYTICS_ENABLED', (bool) env('ELECTION_REVIEW_MODE', false)),
            'display_mode' => (string) env('ELECTION_BALLOT_ANALYTICS_DISPLAY_MODE', (bool) env('ELECTION_REVIEW_MODE', false) ? 'review' : 'hidden'),
        ],
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
        'clustered_precinct' => env('ELECTION_POP_CLUSTERED_PRECINCT', '39010402'),
        'district' => env('ELECTION_POP_DISTRICT', 'SECOND DIST'),
        'contest_rules' => [
            'PRESIDENT' => 1,
            'VICE PRESIDENT' => 1,
            'SENATOR' => 12,
            'PARTY LIST' => 1,
            'MEMBER, HOUSE OF REPRESENTATIVES' => 1,
            'MAYOR' => 1,
            'VICE-MAYOR' => 1,
            'COUNCILOR' => 6,
        ],
        'candidate_limits' => [
            'SENATOR' => (int) env('ELECTION_DEMO_SENATOR_CANDIDATE_LIMIT', 64),
        ],
    ],
    'pdf' => [
        'ghostscript_binary' => env('ELECTION_PDF_GHOSTSCRIPT_BINARY', 'gs'),
    ],
    'print_forms' => [
        'default_profile' => env('ELECTION_PRINT_FORM_PROFILE', 'a4'),
        'available_profiles' => ['a4', 'thermal-80', 'thermal-58'],
    ],
    'closeout_printer' => [
        'driver' => env('ELECTION_CLOSEOUT_PRINTER_DRIVER', 'file'),
        'default_profile' => env('ELECTION_CLOSEOUT_PRINT_PROFILE', env('ELECTION_PRINT_FORM_PROFILE', 'a4')),
        'cups' => [
            'name' => env('ELECTION_CLOSEOUT_CUPS_PRINTER', ''),
            'timeout' => (int) env('ELECTION_CLOSEOUT_CUPS_TIMEOUT', 10),
        ],
    ],
    'clc' => [
        'source_path' => env('ELECTION_CLC_SOURCE_PATH', resource_path('election/ballots/Manila_Districts_1and2.xlsx')),
        'profile' => env('ELECTION_CLC_PROFILE', 'manila-districts-ballot-workbook'),
        'registry_version' => env('ELECTION_CLC_REGISTRY_VERSION', 'clc-2025-nle'),
        'workbook_election_id' => env('ELECTION_CANDIDATE_WORKBOOK_ELECTION_ID', 'MAY-9-2022-NLE-MANILA-FACSIMILE-DEMO'),
        'workbook_active_sheet' => env('ELECTION_CANDIDATE_WORKBOOK_ACTIVE_SHEET', 'Manila 2nd District'),
        'workbook_party_reference_pdf' => env('ELECTION_CANDIDATE_WORKBOOK_PARTY_REFERENCE_PDF', resource_path('election/ballots/MANILA-2ND_DISTRICT-party-reference.json')),
        'workbook_sheets' => [
            'Manila 1st District' => [
                'geography' => 'NCR - MANILA',
                'district' => 'FIRST DIST',
            ],
            'Manila 2nd District' => [
                'geography' => 'NCR - MANILA',
                'district' => 'SECOND DIST',
            ],
        ],
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
    'tabulation' => [
        'profile' => env('ELECTION_TABULATION_PROFILE', 'device-tabulation-with-paper-audit'),
    ],
    'random_manual_audit' => [
        'sample_percent' => (int) env('ELECTION_RMA_SAMPLE_PERCENT', 10),
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
                'description' => 'Tabulate the configured record source and reconcile the paper ballot box.',
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
