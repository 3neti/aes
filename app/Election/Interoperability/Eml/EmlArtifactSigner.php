<?php

namespace App\Election\Interoperability\Eml;

use RuntimeException;

final class EmlArtifactSigner
{
    /** @return array<string, string> */
    public function sign(string $canonicalXml): array
    {
        $keypair = $this->keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $signature = sodium_crypto_sign_detached($canonicalXml, $secretKey);

        return [
            'algorithm' => 'Ed25519',
            'key_id' => $this->keyId($publicKey),
            'public_key' => $this->base64UrlEncode($publicKey),
            'signature' => $this->base64UrlEncode($signature),
        ];
    }

    /** @param array<string, mixed> $signature */
    public function verify(string $canonicalXml, array $signature): bool
    {
        if (($signature['algorithm'] ?? null) !== 'Ed25519') {
            return false;
        }

        try {
            $publicKey = $this->base64UrlDecode((string) ($signature['public_key'] ?? ''));
            $detached = $this->base64UrlDecode((string) ($signature['signature'] ?? ''));
        } catch (RuntimeException) {
            return false;
        }

        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($detached) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        if (! hash_equals($this->keyId($publicKey), (string) ($signature['key_id'] ?? ''))) {
            return false;
        }

        if (! $this->isTrustedPublicKey($publicKey)) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($detached, $canonicalXml, $publicKey);
    }

    public function trustedKeyId(): string
    {
        return $this->keyId($this->trustedPublicKey());
    }

    private function trustedPublicKey(): string
    {
        return sodium_crypto_sign_publickey($this->keypair());
    }

    private function isTrustedPublicKey(string $publicKey): bool
    {
        return collect([
            $this->trustedPublicKey(),
            ...collect(explode(',', (string) config('election.eml.trusted_public_keys', '')))
                ->map(fn (string $configured): string => trim($configured))
                ->filter()
                ->map(function (string $configured): ?string {
                    try {
                        $decoded = $this->base64UrlDecode($configured);

                        return strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $decoded : null;
                    } catch (RuntimeException) {
                        return null;
                    }
                })
                ->filter()
                ->all(),
        ])->contains(fn (string $trusted): bool => hash_equals($trusted, $publicKey));
    }

    private function keypair(): string
    {
        return sodium_crypto_sign_seed_keypair($this->seed());
    }

    private function seed(): string
    {
        $configured = trim((string) config('election.eml.signing_seed', ''));

        if ($configured !== '') {
            $seed = base64_decode($configured, true);

            if (is_string($seed) && strlen($seed) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
                return $seed;
            }

            throw new RuntimeException('ELECTION_EML_SIGNING_SEED must be a base64-encoded 32-byte Ed25519 seed.');
        }

        $applicationKey = (string) config('app.key');

        if ($applicationKey === '') {
            throw new RuntimeException('An EML signing seed or application key is required.');
        }

        return hash('sha256', 'waes-eml-7-base-signing|'.$applicationKey, true);
    }

    private function keyId(string $publicKey): string
    {
        $configured = trim((string) config('election.eml.signing_key_id', ''));

        return $configured !== '' ? $configured : 'waes-eml-'.substr(hash('sha256', $publicKey), 0, 16);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if (! is_string($decoded)) {
            throw new RuntimeException('Invalid base64url value.');
        }

        return $decoded;
    }
}
