<?php

namespace App\Election\Interoperability\Eml;

final readonly class EmlArtifact
{
    /**
     * @param  array<string, mixed>  $signature
     * @param  array<string, mixed>  $validation
     */
    public function __construct(
        public EmlMessageType $messageType,
        public string $profile,
        public string $xml,
        public string $canonicalXml,
        public string $sha256,
        public array $signature,
        public array $validation,
        public string $relativePath,
        public string $absolutePath,
        public string $signatureRelativePath,
    ) {}

    /** @return array<string, mixed> */
    public function reference(): array
    {
        return [
            'message_type' => $this->messageType->value,
            'profile' => $this->profile,
            'sha256' => $this->sha256,
            'path' => $this->relativePath,
            'signature_path' => $this->signatureRelativePath,
            'signature_algorithm' => $this->signature['algorithm'] ?? null,
            'signing_key_id' => $this->signature['key_id'] ?? null,
            'schema_valid' => $this->validation['valid'] ?? false,
        ];
    }
}
