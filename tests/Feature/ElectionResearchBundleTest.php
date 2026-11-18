<?php

use App\Election\Bundle\ResearchElectionBundleService;
use LBHurtado\ElectionCeremonies\Bundle\AuthoritySignature;
use LBHurtado\ElectionCeremonies\Bundle\ElectionBundleVerifier;

test('2025 sources build a signed research snapshot without candidate mappings', function (): void {
    $root = sys_get_temp_dir().'/aes-research-test-'.bin2hex(random_bytes(6));
    mkdir($root);
    $archivePath = $root.'/research.zip';
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);
    $manifest = app(ResearchElectionBundleService::class)->build($archivePath, $secret);
    $signatures = app(AuthoritySignature::class);
    $verified = app(ElectionBundleVerifier::class)->verify($archivePath, [$signatures->keyId($public) => $public]);
    $archive = new ZipArchive;
    $archive->open($archivePath);
    $report = json_decode((string) $archive->getFromName('reports/source-snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    $sqlite = $root.'/election.sqlite';
    file_put_contents($sqlite, $archive->getFromName('election.sqlite'));
    $database = new PDO('sqlite:'.$sqlite);

    expect($manifest['coverage'])->toBe('source_snapshot')
        ->and($verified['readiness'])->toBe('research')
        ->and($report['registry']['precinct_count'])->toBe(93629)
        ->and($report['registry']['source_count'])->toBe(1730)
        ->and($database->query('SELECT COUNT(*) FROM precincts')->fetchColumn())->toBe(93629)
        ->and($database->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'candidate_codes'")->fetchColumn())->toBe(0);

    $archive->close();
});
