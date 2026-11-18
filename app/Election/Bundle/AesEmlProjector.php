<?php

namespace App\Election\Bundle;

use App\Election\Interoperability\Eml\EmlArtifactService;
use App\Election\Interoperability\Eml\EmlMessageType;
use LBHurtado\ElectionCeremonies\Contracts\EmlProjector;
use RuntimeException;

class AesEmlProjector implements EmlProjector
{
    public function __construct(private readonly EmlArtifactService $artifacts) {}

    /**
     * @param  array<string, mixed>  $record
     * @return array{sha256: string, path: string, schema_valid: bool, signature_valid: bool}
     */
    public function export(string $messageType, array $record): array
    {
        $type = EmlMessageType::tryFrom($messageType)
            ?? throw new RuntimeException("Unsupported EML message type [{$messageType}].");
        $sourceHash = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
        $path = "ceremonies/eml/{$messageType}-{$sourceHash}.xml";
        $artifact = $this->artifacts->export($type, $record, $path);
        $verification = $this->artifacts->verifyStored($artifact->relativePath);

        return [
            'sha256' => $artifact->sha256,
            'path' => $artifact->relativePath,
            'schema_valid' => (bool) ($verification['schema_valid'] ?? false),
            'signature_valid' => (bool) ($verification['signature_valid'] ?? false),
        ];
    }
}
