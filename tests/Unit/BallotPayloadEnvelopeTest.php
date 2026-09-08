<?php

use App\Election\Voting\BallotPayloadEnvelope;

test('it wraps compact ballot payloads in a truth uri envelope', function (): void {
    $canonicalPayload = 'aes-ballot-compact-1:AES2|election-2026|precinct-0421A|style-1|mapping-hash|device-tabulation|0421-A-000001|CAND00001,CAND00002';
    $envelope = app(BallotPayloadEnvelope::class);

    $wrapped = $envelope->wrap($canonicalPayload);

    expect($wrapped)->toStartWith('truth://v1/waes-ballot/aes-ballot-compact-1?p=')
        ->and($wrapped)->not->toContain($canonicalPayload)
        ->and($envelope->unwrap($wrapped))->toBe($canonicalPayload)
        ->and($envelope->payloadVersion($wrapped))->toBe('aes-ballot-compact-1');
});

test('it still reads legacy vaes ballot truth uri envelopes', function (): void {
    $canonicalPayload = 'aes-ballot-compact-1:AES2|election-2026|precinct-0421A|style-1|mapping-hash|device-tabulation|0421-A-000001|CAND00001';
    $wrapped = 'truth://v1/vaes-ballot/aes-ballot-compact-1?p='.rtrim(strtr(base64_encode($canonicalPayload), '+/', '-_'), '=');

    expect(app(BallotPayloadEnvelope::class)->unwrap($wrapped))->toBe($canonicalPayload);
});

test('it preserves raw compact payload compatibility', function (): void {
    $canonicalPayload = 'aes-ballot-compact-1:AES2|election-2026|precinct-0421A|style-1|mapping-hash|device-tabulation|0421-A-000001|CAND00001';
    $envelope = app(BallotPayloadEnvelope::class);

    expect($envelope->unwrap($canonicalPayload))->toBe($canonicalPayload)
        ->and($envelope->payloadVersion($canonicalPayload))->toBe('aes-ballot-compact-1');
});

test('it rejects malformed truth ballot envelopes', function (): void {
    expect(fn () => app(BallotPayloadEnvelope::class)->unwrap('truth://v1/waes-ballot/aes-ballot-compact-1'))
        ->toThrow(RuntimeException::class, 'Ballot payload envelope is missing its ballot payload.');
});
