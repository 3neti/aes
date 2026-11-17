import assert from 'node:assert/strict';
import test from 'node:test';

import { getBrowserWebSocketOptions } from '../../resources/js/lib/browser-websocket-options.ts';

test('offline AP clients connect to the current local address', () => {
    assert.deepEqual(
        getBrowserWebSocketOptions({
            hostname: '10.42.0.1',
            port: '',
            protocol: 'http:',
        }),
        {
            wsHost: '10.42.0.1',
            wsPort: 80,
            wssPort: 443,
            forceTLS: false,
        },
    );
});

test('HTTPS clients use their current hostname and secure transport', () => {
    assert.deepEqual(
        getBrowserWebSocketOptions({
            hostname: 'waes.home.arpa',
            port: '',
            protocol: 'https:',
        }),
        {
            wsHost: 'waes.home.arpa',
            wsPort: 80,
            wssPort: 443,
            forceTLS: true,
        },
    );
});

test('Ethernet clients retain a nonstandard page port', () => {
    assert.deepEqual(
        getBrowserWebSocketOptions({
            hostname: '192.168.88.38',
            port: '8090',
            protocol: 'http:',
        }),
        {
            wsHost: '192.168.88.38',
            wsPort: 8090,
            wssPort: 8090,
            forceTLS: false,
        },
    );
});
