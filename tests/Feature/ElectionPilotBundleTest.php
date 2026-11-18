<?php

use App\Election\Bundle\PilotElectionBundleService;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Artisan;
use LBHurtado\ElectionCeremonies\Bundle\AuthoritySignature;
use LBHurtado\ElectionCeremonies\Bundle\BundleCommissioner;
use LBHurtado\ElectionCeremonies\Bundle\ElectionBundleVerifier;
use LBHurtado\ElectionCeremonies\Bundle\SiteAssignment;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyProfile;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyRequest;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyRunner;
use LBHurtado\ElectionCeremonies\Ceremony\FileCeremonyStore;
use LBHurtado\ElectionCeremonies\Contracts\ActorVerifier;
use LBHurtado\ElectionPh\Bundle\PhilippineRegistryReader;

test('AES sample registry builds and commissions a signed pilot bundle', function (): void {
    $directory = sys_get_temp_dir().'/aes-pilot-bundle-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $archivePath = $directory.'/pilot.zip';
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);
    $signatures = app(AuthoritySignature::class);
    $trustedKeys = [$signatures->keyId($public) => $public];

    $built = app(PilotElectionBundleService::class)->build($archivePath, $secret);
    $bundle = app(ElectionBundleVerifier::class)->verify($archivePath, $trustedKeys);

    expect($built['coverage'])->toBe('pilot')
        ->and($bundle['election_id'])->toBe('AES-2026-SAMPLE');

    $assignment = app(SiteAssignment::class)->sign([
        'bundle_id' => $bundle['bundle_id'],
        'bundle_sha256' => $bundle['archive_sha256'],
        'device_id' => 'sample-device',
        'role' => 'precinct',
        'jurisdiction_id' => '0421-A',
        'permitted_sources' => [],
        'ceremonies' => ['certify'],
    ], $secret);
    $store = new FileCeremonyStore($directory.'/states');
    $commissioner = new BundleCommissioner(app(ElectionBundleVerifier::class), app(SiteAssignment::class), $store);

    expect(fn () => $commissioner->commission('election-day', $archivePath, $assignment['document'], $assignment['signature'], $trustedKeys, 'sample-device', 'election_day', $directory.'/denied'))
        ->toThrow(RuntimeException::class);

    $commissioned = $commissioner->commission('pilot-run', $archivePath, $assignment['document'], $assignment['signature'], $trustedKeys, 'sample-device', 'pilot', $directory.'/active');
    $codes = (new PhilippineRegistryReader($directory.'/active/election.sqlite'))->candidateCodesForPrecinct('0421-A');

    expect($commissioned['jurisdiction_id'])->toBe('0421-A')
        ->and($codes)->toHaveCount(7)
        ->and($codes[0]['code'])->toBe('CAND00001')
        ->and($codes[0]['candidate_id'])->toBe('pres-ada');

    $profile = CeremonyProfile::fromYaml($directory.'/active/profiles/ceremonies.yaml');
    $actors = new class implements ActorVerifier
    {
        public function verify(CeremonyRequest $request): void
        {
            if ($request->actorId !== 'sample-officer') {
                throw new RuntimeException('Actor is not assigned to this ceremony.');
            }
        }
    };
    $runner = new CeremonyRunner($profile, $store, app(Pipeline::class), $actors, []);
    $request = new CeremonyRequest('pilot-run', 'certify', 'sample-officer', $commissioned['bundle_sha256'], $commissioned['assignment_sha256'], 'certify-1');

    expect($runner->run($request)['to'])->toBe('certification')
        ->and($runner->run($request)['to'])->toBe('certification')
        ->and($store->state('pilot-run')['stage'])->toBe('certification');

    $archive = new ZipArchive;
    $archive->open($archivePath);
    $archive->addFromString('profiles/ceremonies.yaml', 'id: changed');
    $archive->close();

    expect(fn () => app(ElectionBundleVerifier::class)->verify($archivePath, $trustedKeys))
        ->toThrow(RuntimeException::class);
});

test('pilot bundle command requires an explicit authority signing key', function (): void {
    $directory = sys_get_temp_dir().'/aes-pilot-command-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $output = $directory.'/pilot.zip';

    expect(Artisan::call('election:bundle:build-pilot', ['output' => $output]))->toBe(1)
        ->and(is_file($output))->toBeFalse();

    $keyPath = $directory.'/authority.key';
    file_put_contents($keyPath, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
    chmod($keyPath, 0600);

    expect(Artisan::call('election:bundle:build-pilot', ['output' => $output, '--signing-key' => $keyPath]))->toBe(0)
        ->and(is_file($output))->toBeTrue();
});
