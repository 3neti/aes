<?php

use App\Election\Bundle\PilotElectionBundleService;
use Illuminate\Support\Facades\Artisan;

test('bundle verification command requires the trusted signing public key', function (): void {
    $root = sys_get_temp_dir().'/bundle-verification-test-'.bin2hex(random_bytes(6));
    mkdir($root);
    $archivePath = $root.'/pilot.zip';
    $keypair = sodium_crypto_sign_keypair();
    app(PilotElectionBundleService::class)->build($archivePath, sodium_crypto_sign_secretkey($keypair));
    $publicPath = $root.'/trusted.pub';
    file_put_contents($publicPath, sodium_crypto_sign_publickey($keypair));

    expect(Artisan::call('election:bundle:verify', ['archive' => $archivePath, '--trusted-key' => $publicPath]))->toBe(0)
        ->and(Artisan::output())->toContain('Verified');

    file_put_contents($publicPath, sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

    expect(Artisan::call('election:bundle:verify', ['archive' => $archivePath, '--trusted-key' => $publicPath]))->toBe(1);
});
