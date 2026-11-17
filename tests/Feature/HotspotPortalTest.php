<?php

use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
    config()->set('hotspot.arp_table', '/nonexistent/waes-test-arp');
});

test('hotspot welcome is local and has no credential fields or external assets', function (): void {
    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.42.0.51'])
        ->get('http://10.42.0.1/hotspot');

    $response->assertSuccessful()
        ->assertSee('Connect to WAES')
        ->assertSee('No password or Internet connection is required.')
        ->assertSee('http://10.42.0.1/hotspot/connect', false)
        ->assertSee('name="_token"', false)
        ->assertDontSee('type="password"', false)
        ->assertDontSee('type="email"', false)
        ->assertDontSee('<script', false)
        ->assertDontSee('<link', false);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('unacknowledged hotspot probes redirect to the fixed local portal', function (string $url): void {
    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.42.0.51'])->get($url);

    $response->assertRedirect('http://10.42.0.1/hotspot');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
})->with([
    'http://captive.apple.com/hotspot-detect.html',
    'http://connectivitycheck.gstatic.com/generate_204',
    'http://connectivitycheck.android.com/generate_204',
    'http://www.msftconnecttest.com/connecttest.txt',
    'http://www.msftconnecttest.com/redirect',
    'http://detectportal.firefox.com/success.txt',
]);

test('connect acknowledges only the current client and ignores external redirect input', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '10.42.0.51'])
        ->post('http://10.42.0.1/hotspot/connect', ['redirect' => 'https://example.invalid'])
        ->assertRedirect('http://10.42.0.1/election/role-demo');

    $this->withServerVariables(['REMOTE_ADDR' => '10.42.0.51'])
        ->get('http://connectivitycheck.gstatic.com/generate_204')
        ->assertNoContent();

    $this->withServerVariables(['REMOTE_ADDR' => '10.42.0.52'])
        ->withHeader('X-Forwarded-For', '10.42.0.51')
        ->get('http://connectivitycheck.gstatic.com/generate_204')
        ->assertRedirect('http://10.42.0.1/hotspot');
});

test('acknowledged clients receive conventional probe completion responses', function (string $url, string $expected): void {
    $this->withServerVariables(['REMOTE_ADDR' => '10.42.0.51'])
        ->post('http://10.42.0.1/hotspot/connect')
        ->assertRedirect('http://10.42.0.1/election/role-demo');

    $this->get($url)->assertSuccessful()->assertContent($expected);
})->with([
    ['http://captive.apple.com/hotspot-detect.html', '<HTML><HEAD><TITLE>Success</TITLE></HEAD><BODY>Success</BODY></HTML>'],
    ['http://www.msftconnecttest.com/connecttest.txt', 'Microsoft Connect Test'],
    ['http://detectportal.firefox.com/success.txt', "success\n"],
]);

test('windows redirect opens the local app after acknowledgment', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '10.42.0.51'])
        ->post('http://10.42.0.1/hotspot/connect')
        ->assertRedirect('http://10.42.0.1/election/role-demo');

    $this->get('http://www.msftconnecttest.com/redirect')
        ->assertRedirect('http://10.42.0.1/election/role-demo');
});

test('ethernet requests cannot become hotspot clients using forwarded headers', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.88.39'])
        ->withHeader('X-Forwarded-For', '10.42.0.51')
        ->get('http://10.42.0.1/hotspot')
        ->assertNotFound();

    $this->post('http://10.42.0.1/hotspot/connect')->assertNotFound();
    $this->get('http://captive.apple.com/hotspot-detect.html')->assertNotFound();
});

test('reused hotspot addresses do not inherit a different device acknowledgment', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'waes-portal-arp-');
    config()->set('hotspot.arp_table', $path);

    try {
        file_put_contents($path, "10.42.0.51 0x1 0x2 02:00:00:00:00:01 * wlp2s0\n");
        $this->withServerVariables(['REMOTE_ADDR' => '10.42.0.51'])
            ->post('http://10.42.0.1/hotspot/connect')
            ->assertRedirect('http://10.42.0.1/election/role-demo');
        $this->get('http://connectivitycheck.gstatic.com/generate_204')->assertNoContent();

        file_put_contents($path, "10.42.0.51 0x1 0x2 02:00:00:00:00:02 * wlp2s0\n");
        $this->get('http://connectivitycheck.gstatic.com/generate_204')
            ->assertRedirect('http://10.42.0.1/hotspot');
    } finally {
        @unlink($path);
    }
});

test('portal acknowledgments expire without changing app access', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '10.42.0.51'])
        ->post('http://10.42.0.1/hotspot/connect')
        ->assertRedirect('http://10.42.0.1/election/role-demo');

    $this->travel(13)->hours();

    $this->get('http://connectivitycheck.gstatic.com/generate_204')
        ->assertRedirect('http://10.42.0.1/hotspot');
    $this->get('http://10.42.0.1/')->assertRedirect('http://10.42.0.1/election/role-demo');
});

test('the root app redirect is unchanged for Ethernet clients', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.88.39'])
        ->get('http://192.168.88.38/')
        ->assertRedirect('http://192.168.88.38/election/role-demo');
});

test('unknown probe hosts and GET connect requests cannot acknowledge clients', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '10.42.0.51'])
        ->get('http://example.invalid/generate_204')
        ->assertNotFound();
    $this->get('http://10.42.0.1/hotspot/connect')->assertMethodNotAllowed();
    $this->get('http://connectivitycheck.gstatic.com/generate_204')
        ->assertRedirect('http://10.42.0.1/hotspot');
});
