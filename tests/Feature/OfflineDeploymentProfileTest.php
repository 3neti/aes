<?php

test('offline DNS profile is Wi-Fi-only and matches the configured captive probes', function (): void {
    $dns = file_get_contents(base_path('deployment/offline-ap/dnsmasq-ap.conf.example'));

    expect($dns)->toContain("interface=wlp2s0\n")
        ->toContain("except-interface=enp1s0\n")
        ->toContain("except-interface=lo\n")
        ->toContain("listen-address=10.42.0.1\n")
        ->toContain("bind-dynamic\n")
        ->toContain('dhcp-range=10.42.0.50,10.42.0.200,255.255.255.0,12h')
        ->not->toContain('address=/#/')
        ->not->toContain('option:114');

    foreach (array_keys((array) config('hotspot.probes')) as $host) {
        expect($dns)->toContain("local=/{$host}/\n")
            ->toContain("address=/{$host}/10.42.0.1\n");
    }
});

test('offline radio profile uses the requested open SSID without changing Ethernet addressing', function (): void {
    $radio = file_get_contents(base_path('deployment/offline-ap/hostapd.conf.example'));
    $network = file_get_contents(base_path('deployment/offline-ap/05-waes-ap.network'));

    expect($radio)->toContain("ssid=WAES\n")
        ->toContain("country_code=PH\n")
        ->toContain("channel=6\n")
        ->toContain("wpa=0\n")
        ->not->toMatch('/^wpa_(?:psk|passphrase)=/m')
        ->and($network)->toContain("Name=wlp2s0\n")
        ->toContain("Address=10.42.0.1/24\n")
        ->toContain("DHCP=no\n")
        ->not->toContain('enp1s0');
});

test('offline runtime units consistently use the MySQL web-service profile', function (): void {
    $root = base_path('deployment/offline-ap/systemd/');

    foreach (['waes-queue.service', 'waes-reverb.service', 'waes-scanner.service', 'waes-scheduler.service'] as $name) {
        $unit = file_get_contents($root.$name);
        expect($unit)->toContain("User=www-data\n")
            ->toContain("Group=www-data\n")
            ->not->toContain('postgresql.service')
            ->not->toContain('EnvironmentFile=-/etc/waes/runtime.env');
    }

    $scanner = file_get_contents($root.'waes-scanner.service');
    expect($scanner)->toContain('ConditionPathExists=/etc/waes/scanner.env')
        ->toContain("SupplementaryGroups=input\n")
        ->toContain('EnvironmentFile=/etc/waes/scanner.env')
        ->toContain("Restart=always\n");
});

test('offline environment examples separate local broadcasting and printer roles without shipping credentials', function (): void {
    $example = file_get_contents(base_path('deployment/offline-ap/application.env.example'));

    expect($example)->toContain("APP_URL=http://10.42.0.1\n")
        ->toContain("REVERB_HOST=127.0.0.1\n")
        ->toContain("REVERB_PORT=8080\n")
        ->toContain("ELECTION_CUPS_PRINTER=HP_Laser_108a\n")
        ->toContain("ELECTION_CONTROL_NUMBER_CUPS_PRINTER=Thermal58\n")
        ->toContain("ELECTION_CLOSEOUT_PRINTER_DRIVER=file\n")
        ->not->toMatch('/^(?:APP_KEY|DB_PASSWORD|REVERB_APP_SECRET)=/m')
        ->not->toContain('REVERB_ALLOWED_ORIGINS=*');
});

test('offline firewall profile preserves unrelated rules and confines uplink sharing', function (): void {
    $rules = file_get_contents(base_path('deployment/offline-ap/waes-ap.nft'));
    $service = file_get_contents(base_path('deployment/offline-ap/systemd/waes-ap-firewall.service'));

    expect($rules)->toContain('table inet waes_ap_forward')
        ->toContain('table ip waes_ap_nat')
        ->toContain('iifname "wlp2s0" oifname "enp1s0"')
        ->toContain('ip saddr 10.42.0.0/24 oifname "enp1s0" masquerade')
        ->not->toContain('flush ruleset')
        ->and($service)->toContain('/usr/sbin/nft -f /etc/waes/waes-ap.nft')
        ->not->toContain('flush ruleset');
});

test('offline web profile keeps the expected public root and PHP memory budget', function (): void {
    $nginx = file_get_contents(base_path('deployment/offline-ap/nginx-aes.conf.example'));
    $php = parse_ini_file(base_path('deployment/offline-ap/php-fpm.ini.example'), false, INI_SCANNER_RAW);

    expect($nginx)->toContain('root /var/www/aes/public;')
        ->toContain('location = /index.php')
        ->toContain('unix:/run/php/php8.5-fpm.sock')
        ->toContain('include /etc/nginx/snippets/waes-reverb.conf;')
        ->and($php['memory_limit'])->toBe('512M')
        ->and($php['expose_php'])->toBe('Off');
});
