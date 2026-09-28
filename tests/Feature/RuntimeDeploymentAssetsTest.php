<?php

test('runtime services use the verified appliance account', function (): void {
    $services = [
        'waes-queue.service',
        'waes-reverb.service',
        'waes-scanner.service',
        'waes-scheduler.service',
    ];

    foreach ($services as $service) {
        $contents = file_get_contents(base_path('deployment/systemd/'.$service));

        expect($contents)
            ->toContain('User=ace')
            ->toContain('Group=ace')
            ->toContain('WorkingDirectory=/var/www/aes');
    }
});

test('runtime target includes the scanner and scanner input permissions', function (): void {
    $target = file_get_contents(base_path('deployment/systemd/waes-runtime.target'));
    $scanner = file_get_contents(base_path('deployment/systemd/waes-scanner.service'));

    expect($target)->toContain('waes-scanner.service')
        ->and($scanner)->toContain('SupplementaryGroups=input')
        ->and($scanner)->toContain('ExecStart=/var/www/aes/scripts/waes-scanner-service')
        ->and($scanner)->toContain('Restart=always');
});

test('appliance environment uses HTTP Reverb and the stable scanner device path', function (): void {
    $environment = file_get_contents(base_path('deployment/environment/waes-runtime.env.example'));
    $nginx = file_get_contents(base_path('deployment/nginx/waes-reverb.conf.example'));

    expect($environment)
        ->toContain('APP_DEBUG=false')
        ->toContain('REVERB_PORT=80')
        ->toContain('REVERB_SCHEME=http')
        ->toContain('ELECTION_SCANNER_RUNTIME_MODE=evdev')
        ->toContain('ELECTION_SCANNER_DEVICE=/dev/input/by-id/usb-Scanner_Barcode_0215-event-kbd')
        ->and($nginx)
        ->toContain('proxy_pass http://127.0.0.1:8080;')
        ->toContain('location /app/')
        ->toContain('location /apps/');
});

test('scanner service wrapper supports precinct and canvassing ingestion', function (): void {
    $wrapper = file_get_contents(base_path('scripts/waes-scanner-service'));
    $bridge = file_get_contents(base_path('scripts/canvassing-scanner-bridge.py'));

    expect($wrapper)
        ->toContain('election:precinct-ballot-scanner-ingest')
        ->toContain('election:canvassing-scanner-ingest')
        ->toContain('--source=scanner_bridge')
        ->and($bridge)
        ->toContain('select.select(')
        ->toContain('device.grab()')
        ->toContain('record_heartbeat(')
        ->toContain('except BrokenPipeError:')
        ->toContain('ctrl_held and event.code == ecodes.KEY_C');
});

test('runtime installer is explicit and preserves existing appliance configuration', function (): void {
    $installer = file_get_contents(base_path('deployment/install-waes-runtime'));

    expect($installer)
        ->toContain('if [[ ! -f "${environment_file}" ]]')
        ->toContain('systemctl enable waes-runtime.target')
        ->toContain('if [[ "${start_runtime}" == true ]]')
        ->toContain('python3-evdev is required')
        ->toContain('must belong to the input group');
});

test('runtime support helper exposes the documented bounded operations', function (): void {
    $helper = file_get_contents(base_path('deployment/waes-runtime'));

    expect($helper)
        ->toContain('start|stop|restart')
        ->toContain('election:runtime-check')
        ->toContain('journalctl --no-pager')
        ->toContain('waes-scanner.service')
        ->not->toContain('reset --hard');
});
