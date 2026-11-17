<?php

return [
    'base_url' => 'http://10.42.0.1',
    'subnet' => '10.42.0.0/24',
    'interface' => 'wlp2s0',
    'arp_table' => '/proc/net/arp',
    'acknowledgment_minutes' => 720,
    'probes' => [
        'captive.apple.com' => [
            '/hotspot-detect.html' => 'apple',
        ],
        'connectivitycheck.gstatic.com' => [
            '/generate_204' => 'empty',
        ],
        'connectivitycheck.android.com' => [
            '/generate_204' => 'empty',
        ],
        'www.msftconnecttest.com' => [
            '/connecttest.txt' => 'windows',
            '/redirect' => 'open-app',
        ],
        'detectportal.firefox.com' => [
            '/success.txt' => 'firefox',
        ],
    ],
];
