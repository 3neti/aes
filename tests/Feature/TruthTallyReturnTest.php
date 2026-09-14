<?php

use App\Election\Counting\CountingService;
use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Returns\ElectionReturnPayloadEnvelope;
use App\Election\Returns\ElectionReturnQrPayload;
use App\Election\Returns\ElectionReturnScope;
use App\Election\Returns\ElectionReturnService;
use App\Election\Support\ElectionStorage;
use App\Election\Tabulation\TabulationProfile;
use App\Election\Voting\BallotPayloadService;

beforeEach(function (): void {
    config()->set('election.review.access.enabled', false);
    config()->set('election.tabulation.profile', TabulationProfile::PaperFirst->value);
    config()->set('election.devices.printer.driver', 'file');
    app(ElectionStorage::class)->reset();
    $this->withoutVite();
});

test('election return truth tally payload uses compact candidate codes and decodes to a tally', function (): void {
    app(ActivateSamplePackage::class)->handle();
    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
    ], 'truth-tally-er-ballot');

    app(CountingService::class)->accept($payload['qr_payload']);
    $return = app(ElectionReturnService::class)->generate(app(CountingService::class)->tally());

    expect($return['truth_tally']['payload_type'])->toBe('waes-election-return')
        ->and($return['truth_tally']['canonical_payload'])->toStartWith('waes-er-compact-1:WAESER1|')
        ->and($return['truth_tally']['canonical_payload'])->toContain('CAND')
        ->and($return['truth_tally']['canonical_payload'])->not->toContain('pres-ada')
        ->and($return['truth_tally']['qr_payloads'][0])->toStartWith('truth://v1/waes-election-return/waes-er-compact-1?p=')
        ->and($return['truth_tally']['scopes']['national']['qr_payloads'][0])->toStartWith('truth://v1/waes-election-return/waes-er-compact-1?p=')
        ->and($return['truth_tally']['scopes']['local']['qr_payloads'][0])->toStartWith('truth://v1/waes-election-return/waes-er-compact-1?p=')
        ->and(app(ElectionStorage::class)->path('returns/0421-A-truth-tally-qr.png'))->toBeReadableFile()
        ->and(app(ElectionStorage::class)->path('returns/0421-A-truth-tally-national-qr.png'))->toBeReadableFile()
        ->and(app(ElectionStorage::class)->path('returns/0421-A-truth-tally-local-qr.png'))->toBeReadableFile();

    $decoded = app(ElectionReturnQrPayload::class)->decode(
        app(ElectionReturnPayloadEnvelope::class)->reassemble($return['truth_tally']['qr_payloads']),
    );
    $decodedNational = app(ElectionReturnQrPayload::class)->decode(
        app(ElectionReturnPayloadEnvelope::class)->reassemble($return['truth_tally']['scopes']['national']['qr_payloads']),
    );
    $decodedLocal = app(ElectionReturnQrPayload::class)->decode(
        app(ElectionReturnPayloadEnvelope::class)->reassemble($return['truth_tally']['scopes']['local']['qr_payloads']),
    );

    expect($decoded['precinct_id'])->toBe('0421-A')
        ->and($decoded['return_scope'])->toBe(ElectionReturnScope::Combined->value)
        ->and($decoded['accepted_ballots'])->toBe(1)
        ->and($decoded['tally']['president']['pres-ada'])->toBe(1)
        ->and($decoded['tally']['mayor']['mayor-lina'])->toBe(1)
        ->and($decodedNational['return_scope'])->toBe(ElectionReturnScope::National->value)
        ->and($decodedNational['tally'])->toHaveKey('president')
        ->and($decodedNational['tally'])->not->toHaveKey('mayor')
        ->and($decodedLocal['return_scope'])->toBe(ElectionReturnScope::Local->value)
        ->and($decodedLocal['tally'])->toHaveKey('mayor')
        ->and($decodedLocal['tally'])->not->toHaveKey('president');
});

test('election return truth tally payload can be split and reassembled from qr fragments', function (): void {
    app(ActivateSamplePackage::class)->handle();
    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
    ], 'truth-tally-er-fragment-ballot');

    app(CountingService::class)->accept($payload['qr_payload']);
    $return = app(ElectionReturnService::class)->generate(app(CountingService::class)->tally());
    $canonicalPayload = app(ElectionReturnQrPayload::class)->encode($return);
    $fragments = app(ElectionReturnPayloadEnvelope::class)->wrap($canonicalPayload, 150);

    expect(count($fragments))->toBeGreaterThan(1)
        ->and($fragments[0])->toStartWith('truth://v1/waes-election-return/waes-er-fragment-1/1/');

    $reassembled = app(ElectionReturnPayloadEnvelope::class)->reassemble(array_reverse($fragments));

    expect($reassembled)->toBe($canonicalPayload);
});

test('deprecated role demo truth tally election return page redirects to the role demo hub', function (): void {
    $this->get(route('election.role-demo.truth-tally-return'))
        ->assertRedirect(route('election.role-demo.index'));
});
