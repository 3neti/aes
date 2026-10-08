<?php

namespace App\Election\Interoperability\Eml;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class EmlSchemaRegistry
{
    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    private ?string $schemaProfileHash = null;

    public function __construct(
        private readonly Filesystem $files,
        private readonly SecureXml $xml,
    ) {}

    public function root(): string
    {
        return (string) config('election.eml.schema_root', resource_path('election/eml/7.0/Schemas'));
    }

    public function profileSchemaHash(): string
    {
        if ($this->schemaProfileHash !== null) {
            return $this->schemaProfileHash;
        }

        $manifest = $this->verifiedManifest();

        return $this->schemaProfileHash = hash('sha256', json_encode($manifest['files'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public function validate(EmlMessageType $messageType, string $xml): array
    {
        $this->verifiedManifest();
        $document = $this->xml->load($xml);
        $schemaPath = $this->root().'/'.$messageType->schemaFilename();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $valid = $document->schemaValidate($schemaPath, LIBXML_NONET);
            $errors = collect(libxml_get_errors())
                ->map(fn (\LibXMLError $error): array => [
                    'level' => $error->level,
                    'line' => $error->line,
                    'column' => $error->column,
                    'message' => trim($error->message),
                ])
                ->values()
                ->all();

            return [
                'valid' => $valid,
                'message_type' => $messageType->value,
                'schema' => $messageType->schemaFilename(),
                'schema_profile_hash' => $this->profileSchemaHash(),
                'errors' => $errors,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array<string, mixed> */
    public function verifiedManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $manifestPath = dirname($this->root()).'/schema-manifest.json';

        if (! $this->files->isFile($manifestPath)) {
            throw new RuntimeException('The EML schema integrity manifest is missing.');
        }

        $manifest = json_decode($this->files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        foreach ((array) ($manifest['files'] ?? []) as $relativePath => $expectedHash) {
            $path = $this->root().'/'.$relativePath;

            if (! $this->files->isFile($path) || ! hash_equals((string) $expectedHash, (string) hash_file('sha256', $path))) {
                throw new RuntimeException("EML schema integrity check failed for [{$relativePath}].");
            }
        }

        if (($manifest['files'] ?? []) === []) {
            throw new RuntimeException('The EML schema integrity manifest is empty.');
        }

        return $this->manifest = $manifest;
    }
}
