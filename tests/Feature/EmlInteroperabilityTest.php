<?php

use App\Election\Interoperability\Eml\EmlArtifactService;
use App\Election\Interoperability\Eml\EmlArtifactSigner;
use App\Election\Interoperability\Eml\EmlMessageType;
use App\Election\Interoperability\Eml\EmlSchemaRegistry;
use App\Election\Interoperability\Eml\SecureXml;
use App\Election\Support\ElectionStorage;
use Illuminate\Http\UploadedFile;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
});

function emlConfiguration(): array
{
    return [
        'election_id' => 'WAES-2026-DEMO',
        'election_name' => 'WAES 2026 Demonstration Election',
        'precinct_id' => '39010402',
        'ballot_style_id' => 'BS-39010402',
        'mapping_hash' => str_repeat('a', 64),
        'contests' => [
            [
                'id' => 'president',
                'title' => 'President',
                'max_selections' => 1,
                'candidates' => [
                    ['id' => 'president-001', 'name' => 'Candidate One'],
                    ['id' => 'president-002', 'name' => 'Candidate Two'],
                ],
            ],
            [
                'id' => 'mayor',
                'title' => 'Mayor',
                'max_selections' => 1,
                'candidates' => [
                    ['id' => 'mayor-001', 'name' => 'Candidate Three'],
                ],
            ],
        ],
    ];
}

function emlResult(): array
{
    return [
        'election_id' => 'WAES-2026-DEMO',
        'precinct_id' => '39010402',
        'mapping_hash' => str_repeat('a', 64),
        'accepted_ballots' => 3,
        'rejected_ballots' => 0,
        'tally_hash' => str_repeat('b', 64),
        'return_hash' => str_repeat('c', 64),
        'tally' => [
            'president' => ['president-001' => 2, 'president-002' => 1],
            'mayor' => ['mayor-001' => 3],
        ],
    ];
}

test('official EML schemas are hash pinned and validate every supported export', function (): void {
    $service = app(EmlArtifactService::class);
    $configuration = emlConfiguration();
    $result = emlResult();
    $contexts = [
        EmlMessageType::ElectionEvent->value => ['configuration' => $configuration],
        EmlMessageType::CandidateList->value => ['configuration' => $configuration],
        EmlMessageType::BallotDefinition->value => ['configuration' => $configuration],
        EmlMessageType::AuditLog->value => [
            'configuration' => $configuration,
            'audit_entries' => [['event_type' => 'ballot.accepted', 'event_hash' => str_repeat('d', 64)]],
        ],
        EmlMessageType::PrecinctCount->value => ['configuration' => $configuration, 'result' => $result],
        EmlMessageType::CanvassResult->value => ['configuration' => $configuration, 'result' => $result],
        EmlMessageType::Statistics->value => ['configuration' => $configuration, 'result' => $result],
    ];

    foreach (EmlMessageType::cases() as $type) {
        $artifact = $service->export(
            $type,
            $contexts[$type->value],
            "tests/eml/{$type->value}.xml",
        );

        expect($artifact->validation['valid'])->toBeTrue()
            ->and($artifact->sha256)->toHaveLength(64)
            ->and($service->verifyStored($artifact->relativePath))
            ->valid->toBeTrue();
    }
});

test('EML output is deterministic and a changed vote changes the artifact identity', function (): void {
    $service = app(EmlArtifactService::class);
    $context = ['configuration' => emlConfiguration(), 'result' => emlResult()];
    $first = $service->export(EmlMessageType::PrecinctCount, $context, 'tests/eml/first.xml');
    $second = $service->export(EmlMessageType::PrecinctCount, $context, 'tests/eml/second.xml');
    $changed = $context;
    $changed['result']['tally']['president']['president-001'] = 1;
    $third = $service->export(EmlMessageType::PrecinctCount, $changed, 'tests/eml/third.xml');

    expect($first->canonicalXml)->toBe($second->canonicalXml)
        ->and($first->sha256)->toBe($second->sha256)
        ->and($third->sha256)->not->toBe($first->sha256);
});

test('secure XML rejects DTDs and external entities', function (): void {
    app(SecureXml::class)->load('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY attack SYSTEM "file:///etc/passwd">]><x>&attack;</x>');
})->throws(RuntimeException::class, 'DTD or entity');

test('a canvassing appliance can trust a separately commissioned precinct public key', function (): void {
    config()->set('election.eml.signing_seed', base64_encode(str_repeat('A', SODIUM_CRYPTO_SIGN_SEEDBYTES)));
    $signature = app(EmlArtifactSigner::class)->sign('commissioned precinct artifact');

    config()->set('election.eml.signing_seed', base64_encode(str_repeat('B', SODIUM_CRYPTO_SIGN_SEEDBYTES)));
    config()->set('election.eml.trusted_public_keys', $signature['public_key']);

    expect(app(EmlArtifactSigner::class)->verify('commissioned precinct artifact', $signature))->toBeTrue()
        ->and(app(EmlArtifactSigner::class)->verify('altered artifact', $signature))->toBeFalse();
});

test('schema integrity validation detects a substituted schema', function (): void {
    $original = config('election.eml.schema_root');
    config()->set('election.eml.schema_root', storage_path('framework/testing/eml-substituted/Schemas'));

    expect(fn (): array => app(EmlSchemaRegistry::class)->verifiedManifest())
        ->toThrow(RuntimeException::class, 'integrity manifest is missing');

    config()->set('election.eml.schema_root', $original);
});

test('an operator can validate EML without importing it', function (): void {
    $artifact = app(EmlArtifactService::class)->export(
        EmlMessageType::PrecinctCount,
        ['configuration' => emlConfiguration(), 'result' => emlResult()],
        'tests/eml/upload.xml',
    );

    $upload = UploadedFile::fake()->createWithContent('precinct-count.xml', $artifact->xml);

    $this->post(route('election.eml.validate'), ['artifact' => $upload], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('message_type', '510')
        ->assertJsonPath('profile', 'waes-eml-7-base-1')
        ->assertJsonPath('signature_status', 'not_supplied')
        ->assertJsonPath('artifact_sha256', $artifact->sha256);
});

test('the validation endpoint rejects hostile XML without importing it', function (): void {
    $upload = UploadedFile::fake()->createWithContent(
        'hostile.xml',
        '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY attack SYSTEM "file:///etc/passwd">]><x>&attack;</x>',
    );

    $this->post(route('election.eml.validate'), ['artifact' => $upload], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('valid', false)
        ->assertJsonPath('reason', 'invalid_xml');
});
