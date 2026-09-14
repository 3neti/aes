<?php

use App\Election\Truth\TruthQrEnvelope;

test('truth qr envelope identifies a compact ballot document', function (): void {
    $canonicalPayload = 'aes-ballot-compact-1:AES2|ELECTION|PRECINCT|STYLE|mapping|paper-first|SERIAL|CAND00001';
    $payload = truthQrEnvelopeTestPayload('waes-ballot/aes-ballot-compact-1', $canonicalPayload);
    $metadata = app(TruthQrEnvelope::class)->inspect($payload);

    expect($metadata)
        ->toMatchArray([
            'document_type' => 'waes-ballot',
            'payload_version' => 'aes-ballot-compact-1',
            'part_kind' => 'complete',
            'part_number' => 1,
            'total_parts' => 1,
            'canonical_payload' => $canonicalPayload,
        ])
        ->and(app(TruthQrEnvelope::class)->canonicalPayload($payload))->toBe($canonicalPayload);
});

test('truth qr envelope identifies a complete election return document', function (): void {
    $canonicalPayload = 'waes-er-compact-1:WAESER1|ELECTION|PRECINCT|combined|mapping|paper-first|10|0|tally|return|profile|hash|bundle|bundle-hash|CAND00001=10';
    $payload = truthQrEnvelopeTestPayload('waes-election-return/waes-er-compact-1', $canonicalPayload);
    $metadata = app(TruthQrEnvelope::class)->inspect($payload);

    expect($metadata)
        ->toMatchArray([
            'document_type' => 'waes-election-return',
            'payload_version' => 'waes-er-compact-1',
            'part_kind' => 'complete',
            'part_number' => 1,
            'total_parts' => 1,
            'canonical_payload' => $canonicalPayload,
        ]);
});

test('truth qr envelope reassembles multipart election returns out of order', function (): void {
    $canonicalPayload = 'waes-er-compact-1:'.str_repeat('WAESER1|', 80).'CAND00001=10';
    $encoded = truthQrEnvelopeTestBase64UrlEncode($canonicalPayload);
    $hash = hash('sha256', $canonicalPayload);
    $chunks = str_split($encoded, 80);
    $payloads = collect($chunks)
        ->map(fn (string $chunk, int $index): string => sprintf(
            'truth://v1/waes-election-return/waes-er-fragment-1/%d/%d?h=%s&p=%s',
            $index + 1,
            count($chunks),
            $hash,
            $chunk,
        ))
        ->reverse()
        ->values()
        ->all();

    $metadata = app(TruthQrEnvelope::class)->inspect($payloads[0]);

    expect($metadata)
        ->toMatchArray([
            'document_type' => 'waes-election-return',
            'payload_version' => 'waes-er-fragment-1',
            'part_kind' => 'fragment',
            'total_parts' => count($chunks),
            'group_id' => $hash,
        ])
        ->and(app(TruthQrEnvelope::class)->reassemble($payloads))->toBe($canonicalPayload);
});

function truthQrEnvelopeTestPayload(string $path, string $canonicalPayload): string
{
    return sprintf('truth://v1/%s?p=%s', $path, truthQrEnvelopeTestBase64UrlEncode($canonicalPayload));
}

function truthQrEnvelopeTestBase64UrlEncode(string $payload): string
{
    return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
}
