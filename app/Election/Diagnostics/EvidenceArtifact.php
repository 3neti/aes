<?php

namespace App\Election\Diagnostics;

final readonly class EvidenceArtifact
{
    public function __construct(
        public string $file,
        public string $relativePath,
        public int $bytes,
        public string $sha256,
    ) {}

    public static function fromPath(string $directory, string $path): self
    {
        $file = basename($path);

        return new self(
            file: $file,
            relativePath: $directory.'/'.$file,
            bytes: (int) filesize($path),
            sha256: (string) hash_file('sha256', $path),
        );
    }

    /**
     * @return array{file: string, relative_path: string, bytes: int, sha256: string}
     */
    public function toManifestEntry(): array
    {
        return [
            'file' => $this->file,
            'relative_path' => $this->relativePath,
            'bytes' => $this->bytes,
            'sha256' => $this->sha256,
        ];
    }
}
