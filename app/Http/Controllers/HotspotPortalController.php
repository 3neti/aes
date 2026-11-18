<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;

final class HotspotPortalController extends Controller
{
    public function show(Request $request): Response
    {
        $this->requireHotspotClient($request);

        return response()->view('hotspot', [
            'connectUrl' => $this->localUrl('/hotspot/connect'),
            'baseUrl' => $this->localUrl(''),
        ])->withHeaders($this->headers());
    }

    public function connect(Request $request): RedirectResponse
    {
        $this->requireHotspotClient($request);
        Cache::put(
            $this->clientKey($request),
            true,
            now()->addMinutes(max(1, (int) config('hotspot.acknowledgment_minutes', 720))),
        );

        return redirect($this->localUrl('/election/role-demo'))->withHeaders($this->headers());
    }

    public function probe(Request $request, string $kind): Response|RedirectResponse
    {
        $this->requireHotspotClient($request);

        if (! Cache::get($this->clientKey($request), false)) {
            return redirect($this->localUrl('/hotspot'))->withHeaders($this->headers());
        }

        $response = match ($kind) {
            'empty' => response('', 204),
            'apple' => response(
                '<HTML><HEAD><TITLE>Success</TITLE></HEAD><BODY>Success</BODY></HTML>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
            'windows' => response('Microsoft Connect Test', 200, ['Content-Type' => 'text/plain; charset=UTF-8']),
            'firefox' => response("success\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']),
            'open-app' => redirect($this->localUrl('/election/role-demo')),
            default => abort(404),
        };

        return $response->withHeaders($this->headers());
    }

    private function requireHotspotClient(Request $request): void
    {
        abort_unless(
            IpUtils::checkIp(
                $this->clientAddress($request),
                (string) config('hotspot.subnet', '10.42.0.0/24'),
            ),
            404,
        );
    }

    private function clientKey(Request $request): string
    {
        $address = $this->clientAddress($request);
        $identity = $address;
        $entries = @file((string) config('hotspot.arp_table', '/proc/net/arp'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($entries ?: [] as $entry) {
            $fields = preg_split('/\s+/', trim($entry)) ?: [];

            if (
                count($fields) >= 6
                && $fields[0] === $address
                && $fields[5] === config('hotspot.interface', 'wlp2s0')
                && preg_match('/\A(?:[a-f0-9]{2}:){5}[a-f0-9]{2}\z/i', $fields[3]) === 1
                && $fields[3] !== '00:00:00:00:00:00'
            ) {
                $identity .= '|'.strtolower($fields[3]);
                break;
            }
        }

        return 'waes-hotspot:'.hash('sha256', $identity);
    }

    private function clientAddress(Request $request): string
    {
        $address = $request->server('REMOTE_ADDR');

        return is_string($address) ? $address : '';
    }

    private function localUrl(string $path): string
    {
        return rtrim((string) config('hotspot.base_url', 'http://10.42.0.1'), '/').$path;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self' ".$this->localUrl('')."; base-uri 'none'; frame-ancestors 'none'",
        ];
    }
}
